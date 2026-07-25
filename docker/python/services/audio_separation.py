import asyncio
import logging
import os
import threading

logger = logging.getLogger(__name__)


class AudioSeparationService:
    """
    Vocal / background source separation using Demucs (htdemucs), WARM in-process.

    Used by the Moments Compilation template to strip the original spoken
    commentary out of source clips while KEEPING the background music / SFX /
    ambience, so the AI TTS commentary doesn't fight a second voice.

    Like the TTS service, the Demucs model is loaded ONCE per process and reused
    (only the first call pays the load + one-time weight download). Inference is
    serialized with a lock (torch models aren't thread-safe) and run in a worker
    thread so the FastAPI event loop is never blocked.

    Everything degrades gracefully: if Demucs (or torch) isn't installed, every
    call returns {"success": False, ...} and the PHP caller falls back to an
    FFmpeg center-channel vocal reducer.
    """

    MODEL_NAME = os.environ.get("DEMUCS_MODEL", "htdemucs")

    _model = None                 # cached, warm Demucs model
    _model_sr = 44100             # htdemucs operates at 44.1 kHz
    _lock = threading.Lock()      # serialize inference across requests
    _unavailable = False          # set True once we know Demucs can't load

    @classmethod
    def _get_model(cls):
        if cls._model is not None:
            return cls._model
        if cls._unavailable:
            return None

        try:
            from demucs.pretrained import get_model

            logger.info(f"[SEP] Loading Demucs model '{cls.MODEL_NAME}' — one-time warmup")
            model = get_model(cls.MODEL_NAME)
            model.cpu()
            model.eval()
            cls._model = model
            cls._model_sr = int(getattr(model, "samplerate", 44100))
            logger.info(f"[SEP] Demucs ready (sr={cls._model_sr}, stems={model.sources})")
            return cls._model
        except Exception as e:
            logger.warning(f"[SEP] Demucs unavailable, separation will fall back: {e}")
            cls._unavailable = True
            return None

    def is_available(self) -> bool:
        return self._get_model() is not None

    async def separate_background(self, input_path: str, output_path: str) -> dict:
        """
        Write the 'no vocals' (background) stem of input_path to output_path.

        Returns {"success": bool, "output_path": str?, "error": str?}.
        """
        try:
            if not os.path.exists(input_path):
                return {"success": False, "error": f"input not found: {input_path}"}

            if not self.is_available():
                return {"success": False, "error": "demucs not available"}

            return await asyncio.to_thread(self._separate, input_path, output_path)
        except Exception as e:
            logger.error(f"[SEP] separation error: {e}")
            return {"success": False, "error": str(e)}

    def _separate(self, input_path: str, output_path: str) -> dict:
        """Blocking Demucs separation on the warm model."""
        import numpy as np
        import soundfile as sf
        import torch
        from demucs.apply import apply_model

        model = self._get_model()
        if model is None:
            return {"success": False, "error": "demucs not available"}

        # Load audio as float32 [samples, channels]; force stereo for the model.
        data, sr = sf.read(input_path, dtype="float32", always_2d=True)
        wav = torch.from_numpy(data.T)  # [channels, samples]

        if wav.shape[0] == 1:
            wav = wav.repeat(2, 1)
        elif wav.shape[0] > 2:
            wav = wav[:2]

        # Resample to the model's sample rate if needed (rare — PHP extracts 44.1k).
        if sr != self._model_sr:
            try:
                import torchaudio

                wav = torchaudio.functional.resample(wav, sr, self._model_sr)
                sr = self._model_sr
            except Exception as e:
                logger.warning(f"[SEP] resample skipped ({e}); feeding native rate")

        # Demucs normalizes per-track before applying the model.
        ref = wav.mean(0)
        std = float(ref.std()) or 1.0
        wav_norm = (wav - ref.mean()) / std

        with AudioSeparationService._lock:
            with torch.no_grad():
                est = apply_model(
                    model,
                    wav_norm[None],
                    device="cpu",
                    split=True,
                    overlap=0.1,
                    progress=False,
                )[0]

        est = est * std + ref.mean()

        # Sum every stem except 'vocals' to rebuild the background bed.
        sources = list(model.sources)
        background = None
        for idx, name in enumerate(sources):
            if name == "vocals":
                continue
            background = est[idx] if background is None else background + est[idx]

        if background is None:
            return {"success": False, "error": "model produced no non-vocal stems"}

        out = background.cpu().numpy().T  # [samples, channels]

        os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
        sf.write(output_path, out, sr)

        if not os.path.exists(output_path) or os.path.getsize(output_path) == 0:
            return {"success": False, "error": "output stem not written"}

        return {"success": True, "output_path": output_path}
