import asyncio
import importlib.util
import logging
import os
import threading

logger = logging.getLogger(__name__)

SCRIPT_PATH = "/app/resources/scripts/transcribe.py"


class TranscriptionService:
    """Speech-to-text with faster-whisper, model kept resident.

    Every call used to spawn `python3 transcribe.py`, which re-imported the
    libraries (~30 s) and reloaded the model (~20 s, including a Hugging Face
    hub check) before transcribing a few seconds of audio — and the explainer
    calls this once per scene for word timings. Now the script is imported
    once, the model loads once per process (warmed at startup), and only the
    transcription itself runs per call.

    One transcription at a time: the model is shared, and serializing keeps
    memory flat (the script still decodes long files in 10-minute chunks).
    Work runs in a thread so the event loop keeps serving other endpoints.
    """

    _module = None
    _model = None
    _load_lock = threading.Lock()   # first load only
    _run_lock = threading.Lock()    # one transcription at a time

    @classmethod
    def _script(cls):
        if cls._module is None:
            spec = importlib.util.spec_from_file_location("whisper_transcribe", SCRIPT_PATH)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            cls._module = module
        return cls._module

    @classmethod
    def _get_model(cls):
        with cls._load_lock:
            if cls._model is None:
                logger.info("[TRANSCRIBE] Loading Whisper model (one-time warmup)")
                cls._model = cls._script().load_model()
                logger.info("[TRANSCRIBE] Whisper model ready")
            return cls._model

    @classmethod
    def warm(cls):
        """Load the model ahead of the first request (called at startup).

        Loading the weights isn't enough: the first transcribe() also loads
        the VAD model and initialises the decoder, which cost ~45 s on the
        first real call. One second of faint noise through both paths moves
        all of that here.
        """
        try:
            import numpy as np

            model = cls._get_model()
            noise = (np.random.default_rng(0).standard_normal(16000) * 0.01).astype(np.float32)
            with cls._run_lock:
                cls._script()._run(model, noise, True)  # VAD path
                list(model.transcribe(noise, language="en", vad_filter=False, word_timestamps=True)[0])
            logger.info("[TRANSCRIBE] Whisper warmed (VAD + decoder)")
        except Exception as e:
            # Not fatal: the first request will retry the load.
            logger.warning(f"[TRANSCRIBE] Warmup failed: {e}")

    @classmethod
    def _transcribe_sync(cls, video_path: str, word_timestamps: bool):
        model = cls._get_model()
        with cls._run_lock:
            return cls._script().transcribe_video(video_path, word_timestamps=word_timestamps, model=model)

    async def transcribe(self, video_path: str, language: str = "en", word_timestamps: bool = False) -> dict:
        """Transcribe a video/audio file. (`language` is accepted for API
        compatibility; the script pins English, as it always has.)"""
        try:
            if not os.path.exists(video_path):
                return {"success": False, "error": f"File not found: {video_path}"}

            result = await asyncio.to_thread(self._transcribe_sync, video_path, word_timestamps)
            if not result:
                return {"success": False, "error": "Transcription failed (see ai container logs)"}

            segments = result.get("segments", [])
            text = " ".join(s.get("text", "").strip() for s in segments).strip()

            # Duration: prefer the value reported by the model, otherwise fall
            # back to the end of the last segment so downstream clip selection
            # always receives a non-zero total duration.
            duration = result.get("duration", 0) or 0
            if not duration and segments:
                duration = segments[-1].get("end", 0) or 0

            logger.info(f"[TRANSCRIBE] {len(segments)} segments, {duration:.1f}s of audio")

            return {
                "success": True,
                "segments": segments,
                "text": text,
                "duration": duration,
            }
        except Exception as e:
            logger.error(f"[TRANSCRIBE] Exception: {e}", exc_info=True)
            return {"success": False, "error": f"Exception: {e}"}
