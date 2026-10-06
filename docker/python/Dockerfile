FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libsndfile1 \
    libgl1 \
    && rm -rf /var/lib/apt/lists/*

# CPU torch FIRST, from the CPU wheel index. Left to PyPI, whisper/demucs/
# ultralytics/kokoro pull the CUDA build: ~3.4 GB of nvidia-* + triton that
# a machine without a GPU never loads. Same versions the image ran before.
# On a GPU host: --build-arg TORCH_INDEX=https://download.pytorch.org/whl/cu128
ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
RUN pip install torch==2.12.1 torchaudio==2.11.0 torchvision==0.27.1 --index-url ${TORCH_INDEX}

# Everything else pinned (see the file's header). Its own layer, before any
# source is copied, so editing app code never re-installs packages.
COPY docker/python/requirements.lock.txt /tmp/requirements.lock.txt
RUN pip install -r /tmp/requirements.lock.txt

# Only what this service reads. In dev compose mounts the project over /app
# anyway; copying the whole repo baked storage/ (every rendered video) into
# the image.
COPY docker/python /app/docker/python
COPY resources/scripts /app/resources/scripts
COPY models /app/models
COPY yolov8n.pt /app/yolov8n.pt

CMD ["uvicorn", "docker.python.app:app", "--host", "0.0.0.0", "--port", "8000"]
