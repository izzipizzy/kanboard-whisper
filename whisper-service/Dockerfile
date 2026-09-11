FROM python:3.14-slim
ENV PYTHONUNBUFFERED=1 HF_HUB_DISABLE_TELEMETRY=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .
RUN useradd --uid 10001 --create-home whisper && mkdir /models && chown whisper /models
USER whisper
EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--no-access-log"]
