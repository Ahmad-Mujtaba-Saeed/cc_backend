# Voice-clone TTS (Chatterbox-Nano). Its own image because chatterbox-tts pins
# torch/transformers versions that would conflict with the `ai` image.
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HUB_DISABLE_TELEMETRY=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg libsndfile1 git \
    && rm -rf /var/lib/apt/lists/*

# CPU wheels by default (~200 MB instead of ~2.5 GB of CUDA libraries).
# On a GPU host build with: --build-arg TORCH_INDEX=https://download.pytorch.org/whl/cu124
ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
RUN pip install --upgrade pip wheel \
    && pip install torch==2.6.0 torchaudio==2.6.0 --index-url ${TORCH_INDEX}

# Pinned to a GitHub commit, not PyPI: Nano support (the `nano=True` loader,
# ResembleAI/chatterbox-nano) landed in #542 and the PyPI release 0.1.7 does
# not have it. Move the pin forward deliberately once a release includes it.
# setuptools<81: the Perth watermarker still imports pkg_resources, which
# setuptools 81 removed - without the pin the model fails to load.
ARG CHATTERBOX_REF=5de7a54aa4e5e2baadb0182dde554908b48b85c2
RUN pip install "chatterbox-tts @ git+https://github.com/resemble-ai/chatterbox.git@${CHATTERBOX_REF}" \
        "setuptools<81" fastapi "uvicorn[standard]" pyloudnorm soundfile

WORKDIR /srv
COPY app.py /srv/app.py

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
