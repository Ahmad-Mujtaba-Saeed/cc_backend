FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    ffmpeg \
    libsndfile1 \
    libgl1 \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --upgrade pip

RUN pip install --no-cache-dir \
    pytesseract pdf2image pillow \
    "numpy<2.0" \
    requests boto3 openai \
    soundfile ffmpeg-python opencv-python-headless \
    moviepy>=2.1.0 imageio imageio-ffmpeg \
    faster-whisper ultralytics mediapipe \
    kokoro psutil\
    demucs \
    fastapi uvicorn python-multipart

COPY . /app

CMD ["uvicorn", "docker.python.app:app", "--host", "0.0.0.0", "--port", "8000"]