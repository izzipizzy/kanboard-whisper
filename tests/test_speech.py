"""Offline service boundary tests; no model download or Telegram calls."""
import asyncio
import os
import sys
import tempfile
import threading
import time
import tracemalloc
import unittest
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, '/app')
import app as speech
import av
import numpy as np
from fastapi import HTTPException
from fastapi.testclient import TestClient
from starlette.datastructures import UploadFile

FIXTURES = tempfile.mkdtemp(prefix='speech-fixtures-')


def write_audio(name, seconds, codec='flac', rate=8000, frequency=0.0):
    """Encode generated mono audio with the same FFmpeg that the service uses."""
    path = os.path.join(FIXTURES, name)
    with av.open(path, 'w') as out:
        stream = out.add_stream(codec, rate=rate, layout='mono')
        remaining, offset = int(seconds * rate), 0
        while remaining > 0:
            count = min(rate * 10, remaining)
            t = (np.arange(count) + offset) / rate
            pcm = (np.sin(2 * np.pi * frequency * t) * 12000).astype(np.int16)
            frame = av.AudioFrame.from_ndarray(pcm.reshape(1, -1), format='s16', layout='mono')
            frame.sample_rate = rate
            for packet in stream.encode(frame):
                out.mux(packet)
            remaining -= count
            offset += count
        for packet in stream.encode(None):
            out.mux(packet)
    return path


def upload(data=b'audio'):
    spooled = tempfile.SpooledTemporaryFile()
    spooled.write(data)
    spooled.seek(0)
    return UploadFile(spooled, filename='a.ogg')


class SpeechTests(unittest.TestCase):
    def setUp(self):
        # No lifespan context => no model downloads during these unit tests.
        self.client = TestClient(speech.app)
        self.tmp = tempfile.TemporaryDirectory()
        self.saved_tempdir, tempfile.tempdir = tempfile.tempdir, self.tmp.name

    def tearDown(self):
        tempfile.tempdir = self.saved_tempdir
        leaked = [name for name in os.listdir(self.tmp.name) if name.endswith('.audio')]
        self.tmp.cleanup()
        self.assertFalse(speech.busy.locked(), 'transcription slot leaked')
        self.assertEqual(leaked, [], 'temporary audio leaked')

    def post(self, path, **data):
        with open(path, 'rb') as audio:
            return self.client.post('/transcribe', data=data, files={'file': (os.path.basename(path), audio)})

    def test_language_rejected(self):
        response = self.client.post('/transcribe', data={'language': 'wrong'}, files={'file': ('a.ogg', b'x')})
        self.assertEqual(response.status_code, 400)

    def test_oversize_rejected(self):
        response = self.client.post('/transcribe', files={'file': ('a.ogg', b'x' * (20 * 1024 * 1024 + 1))})
        self.assertEqual(response.status_code, 413)

    def test_empty_upload_rejected(self):
        response = self.client.post('/transcribe', files={'file': ('a.ogg', b'')})
        self.assertEqual(response.status_code, 422)

    def test_invalid_audio_rejected(self):
        response = self.client.post('/transcribe', files={'file': ('a.ogg', b'not audio')})
        self.assertEqual(response.status_code, 422)

    def test_audio_without_samples_rejected(self):
        path = write_audio('empty.flac', 0)
        with self.assertRaisesRegex(speech.AudioRejected, 'No audio samples'):
            speech.decode_bounded(path)
        self.assertEqual(self.post(path).status_code, 422)

    def test_long_compressed_audio_stops_at_limit(self):
        # 30 minutes of silence compress to a tiny FLAC file. A full decode would
        # allocate ~170 MB (int16 buffer + float32 copy) before any length check.
        path = write_audio('thirty-minutes.flac', 30 * 60)
        self.assertLess(os.path.getsize(path), 1024 * 1024)
        tracemalloc.start()
        try:
            with self.assertRaises(speech.AudioRejected) as rejected:
                speech.decode_bounded(path)
            peak = tracemalloc.get_traced_memory()[1]
        finally:
            tracemalloc.stop()
        self.assertGreater(rejected.exception.decoded_seconds, 600)
        self.assertLess(rejected.exception.decoded_seconds, 605, 'decoder must stop right after the limit')
        self.assertLess(peak, 48 * 1024 * 1024, 'bounded decode must not hold the whole recording')
        response = self.post(path)
        self.assertEqual(response.status_code, 422)

    def test_limit_counts_decoded_samples(self):
        path = write_audio('twelve-seconds.flac', 12)
        with self.assertRaises(speech.AudioRejected) as rejected:
            speech.decode_bounded(path, max_seconds=10)
        self.assertLess(rejected.exception.decoded_seconds, 11)
        audio = speech.decode_bounded(path, max_seconds=12)
        self.assertEqual(audio.dtype, np.float32)
        self.assertAlmostEqual(len(audio) / speech.SAMPLE_RATE, 12, delta=0.1)

    def test_real_opus_voice_note_reaches_model(self):
        # Telegram voice notes are Ogg/Opus at 48 kHz; decode for real, fake only the model.
        path = write_audio('voice.ogg', 2, codec='libopus', rate=48000, frequency=440)
        received = {}

        class FakeModel:
            def transcribe(self, audio, **kwargs):
                received.update(audio=audio, **kwargs)
                return iter([SimpleNamespace(text=' Позвонить '), SimpleNamespace(text=' клиенту ')]), None

        with patch.object(speech, 'model', FakeModel()):
            response = self.post(path, language='ru')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {'text': 'Позвонить клиенту'})
        audio = received['audio']
        self.assertEqual(audio.dtype, np.float32)
        self.assertAlmostEqual(len(audio) / speech.SAMPLE_RATE, 2, delta=0.1)
        self.assertTrue(0.1 < np.abs(audio).max() <= 1.0)
        self.assertEqual(received['language'], 'ru')
        self.assertTrue(received['vad_filter'])

    def test_transcription_segments_and_auto_language(self):
        class FakeModel:
            def transcribe(self, audio, **kwargs):
                assert kwargs['language'] is None
                assert kwargs['vad_filter'] is True
                return iter([SimpleNamespace(text=' Позвонить '), SimpleNamespace(text=' клиенту ')]), None
        with patch.object(speech, 'decode_bounded', return_value=np.zeros(16000, dtype=np.float32)), patch.object(speech, 'model', FakeModel()):
            response = self.client.post('/transcribe', data={'language': 'auto'}, files={'file': ('a.ogg', b'fake')})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {'text': 'Позвонить клиенту'})

    def test_busy_service_refuses_instead_of_queueing(self):
        self.assertTrue(speech.busy.acquire(blocking=False))
        try:
            response = self.client.post('/transcribe', files={'file': ('a.ogg', b'fake')})
        finally:
            speech.busy.release()
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.headers['retry-after'], '30')

    def test_cancelled_request_keeps_slot_until_cpu_work_ends(self):
        started, finish, seen = threading.Event(), threading.Event(), {}

        def slow(path, language):
            seen['path'] = path
            started.set()
            finish.wait(10)
            return 'late'

        async def scenario():
            request = asyncio.create_task(speech.transcribe(file=upload(), language='ru'))
            self.assertTrue(await asyncio.to_thread(started.wait, 5))
            request.cancel()
            with self.assertRaises(asyncio.CancelledError):
                await request
            # The request is gone, but the CPU thread still runs: the slot stays taken.
            self.assertTrue(speech.busy.locked())
            self.assertTrue(os.path.exists(seen['path']))
            with self.assertRaises(HTTPException) as refused:
                await speech.transcribe(file=upload(), language='ru')
            self.assertEqual(refused.exception.status_code, 503)
            finish.set()
            deadline = time.monotonic() + 5
            while speech.busy.locked() and time.monotonic() < deadline:
                await asyncio.sleep(0.01)
            self.assertFalse(speech.busy.locked())
            self.assertFalse(os.path.exists(seen['path']))

        with patch.object(speech, 'transcribe_file', slow):
            asyncio.run(scenario())

    def test_cancelled_request_before_job_starts_frees_slot(self):
        gate, calls = threading.Event(), []
        blocker = speech.executor.submit(gate.wait, 10)  # Occupy the single worker thread.

        async def scenario():
            request = asyncio.create_task(speech.transcribe(file=upload(), language='ru'))
            deadline = time.monotonic() + 5
            while not speech.busy.locked() and time.monotonic() < deadline:
                await asyncio.sleep(0.01)
            await asyncio.sleep(0.05)  # Let the handler submit and await its queued job.
            request.cancel()
            with self.assertRaises(asyncio.CancelledError):
                await request
            self.assertFalse(speech.busy.locked(), 'a job that never ran must not hold the slot')

        try:
            with patch.object(speech, 'transcribe_file', lambda *args: calls.append(args)):
                asyncio.run(scenario())
        finally:
            gate.set()
            blocker.result(5)
        self.assertEqual(calls, [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
