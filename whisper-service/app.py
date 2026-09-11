"""Private-network speech service. Never expose this port to the Internet."""
import asyncio
import os
import tempfile
import threading
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager, suppress

import av
import numpy as np
from av.error import FFmpegError
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from faster_whisper import WhisperModel

MODEL = os.getenv("WHISPER_MODEL", "medium")
SAMPLE_RATE = 16000
MAX_SECONDS = 600
MAX_BYTES = 20 * 1024 * 1024
LANGUAGES = {"ru", "en", "auto"}
model = None
# One transcription at a time. The slot is released by the worker thread itself,
# so a timed-out or cancelled request never frees it while CPU work continues.
busy = threading.Lock()
executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix="transcribe")


class AudioRejected(ValueError):
    def __init__(self, message, decoded_seconds=0.0):
        super().__init__(message)
        self.decoded_seconds = decoded_seconds


@asynccontextmanager
async def lifespan(app):
    global model
    # Named models are downloaded once by faster-whisper into the persistent volume.
    model = await asyncio.to_thread(
        WhisperModel, MODEL, device="cpu", compute_type="int8",
        download_root="/models", cpu_threads=int(os.getenv("WHISPER_THREADS", "4")),
    )
    yield


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None)


@app.get("/health")
def health():
    return {"ready": model is not None, "model": MODEL}


def decode_bounded(path, max_seconds=MAX_SECONDS):
    """Decode to 16 kHz mono float32, stopping as soon as the limit is exceeded.

    Container metadata is ignored: it is supplied by the uploader. Only decoded
    samples count, so a small, highly compressed file cannot expand in memory.
    """
    limit = int(max_seconds * SAMPLE_RATE)
    chunks, total = [], 0
    with av.open(path, mode="r", metadata_errors="ignore") as container:
        if not container.streams.audio:
            raise AudioRejected("No audio stream")
        stream = container.streams.audio[0]
        resampler = av.AudioResampler(format="s16", layout="mono", rate=SAMPLE_RATE)

        def frames():
            for packet in container.demux(stream):
                try:
                    yield from packet.decode()
                except av.error.InvalidDataError:
                    continue  # Skip a damaged packet, as faster-whisper does.
            yield None  # Flush samples buffered by the resampler.

        for frame in frames():
            for resampled in resampler.resample(frame):
                samples = resampled.to_ndarray().reshape(-1)
                total += samples.size
                if total > limit:
                    raise AudioRejected("Audio exceeds ten minutes", total / SAMPLE_RATE)
                chunks.append(samples.copy())
    if not total:
        raise AudioRejected("No audio samples")
    pcm = np.concatenate(chunks)
    chunks.clear()
    audio = pcm.astype(np.float32)
    audio /= 32768.0
    return audio


def transcribe_file(path, language):
    audio = decode_bounded(path)
    segments, _ = model.transcribe(audio, language=None if language == "auto" else language, vad_filter=True, beam_size=5)
    return " ".join(segment.text.strip() for segment in segments).strip()


def discard(path):
    if path:
        with suppress(FileNotFoundError):
            os.unlink(path)


def run_exclusive(path, language):
    try:
        return transcribe_file(path, language)
    finally:
        discard(path)
        busy.release()


async def store_upload(file):
    with tempfile.NamedTemporaryFile(suffix=".audio", delete=False) as audio:
        try:
            size = 0
            while chunk := await file.read(1024 * 1024):
                size += len(chunk)
                if size > MAX_BYTES:
                    raise HTTPException(413, "Audio exceeds 20 MB")
                audio.write(chunk)
            if not size:
                raise HTTPException(422, "Empty audio")
        except BaseException:
            discard(audio.name)
            raise
        return audio.name


@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...), language: str = Form("ru")):
    try:
        if language not in LANGUAGES:
            raise HTTPException(400, "Unsupported language")
        # Refuse instead of queueing: callers already retry, and a queue would
        # let one slow recording delay an unbounded number of later requests.
        if not busy.acquire(blocking=False):
            raise HTTPException(503, "Speech service is busy", headers={"Retry-After": "30"})
        path = None
        try:
            path = await store_upload(file)
            job = executor.submit(run_exclusive, path, language)
        except BaseException:
            discard(path)
            busy.release()
            raise

        def release_if_never_started(future):
            # Cancelling a job that has not started yet means run_exclusive never runs.
            if future.cancelled():
                discard(path)
                busy.release()

        job.add_done_callback(release_if_never_started)
        try:
            text = await asyncio.wrap_future(job)
        except (ValueError, RuntimeError, FFmpegError):
            raise HTTPException(422, "Cannot decode audio or duration limit exceeded") from None
        return {"text": text}
    finally:
        await file.close()
