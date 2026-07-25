import asyncio
import logging
import os
import io
import contextlib
import tempfile
import threading

logger = logging.getLogger(__name__)


class TTSService:
    """
    Kokoro TTS — WARM, in-process.

    Previously this shelled out to resources/scripts/tts.py for every request,
    which reloaded the ~82M Kokoro model from scratch each time (~80-90s on CPU).
    Now the KPipeline is loaded ONCE per process and reused, so only the first
    request pays the load cost; subsequent ones take a few seconds.

    Inference is serialized with a lock (torch models aren't thread-safe) and run
    in a worker thread so the FastAPI event loop is never blocked.
    """

    SAMPLE_RATE = 24000  # Kokoro always outputs 24 kHz

    _pipelines: dict = {}                 # lang_code -> KPipeline (shared, warm)
    _lock = threading.Lock()              # serialize inference across requests

    def __init__(self):
        self.tts_script = "/app/resources/scripts/tts.py"  # kept for gTTS fallback only

    @classmethod
    def _get_pipeline(cls, lang_code: str = "a"):
        pipe = cls._pipelines.get(lang_code)
        if pipe is None:
            from kokoro import KPipeline
            logger.info(f"[TTS] Loading Kokoro KPipeline (lang_code={lang_code}) — one-time warmup")
            pipe = KPipeline(lang_code=lang_code)
            cls._pipelines[lang_code] = pipe
            logger.info(f"[TTS] KPipeline ready (lang_code={lang_code})")
        return pipe

    @staticmethod
    def _lang_code_for(voice: str) -> str:
        # Kokoro voice prefixes encode the language: af_/am_ = American ('a'),
        # bf_/bm_ = British ('b'). Fall back to American.
        first = (voice or "")[:1].lower()
        return first if first in ("a", "b") else "a"

    async def generate(self, text: str, voice: str = "am_michael",
                       output_path: str = None, word_timing: bool = False,
                       speed: float = 1.05) -> dict:
        """Generate TTS audio (optionally with per-word timings) from text."""
        try:
            logger.info(f"[TTS] generate voice={voice} word_timing={word_timing} chars={len(text)}")

            if not output_path:
                output_path = os.path.join(
                    tempfile.gettempdir(),
                    f"tts_output_{int(asyncio.get_event_loop().time())}.wav"
                )

            # Run the blocking synthesis off the event loop.
            return await asyncio.to_thread(
                self._synthesize, text, voice, output_path, word_timing, speed
            )

        except Exception as e:
            logger.error(f"[TTS] generation error: {e}")
            # Basic (no-timing) requests can degrade to gTTS; timing requests can't.
            if not word_timing:
                return await self._generate_with_gtts(text, voice, output_path)
            return {"success": False, "error": str(e)}

    def _synthesize(self, text: str, voice: str, output_path: str,
                    word_timing: bool, speed: float) -> dict:
        """Blocking Kokoro synthesis on the warm pipeline. Mirrors the original
        resources/scripts/tts.py logic (incl. real token timestamps)."""
        import soundfile as sf
        import numpy as np

        lang_code = self._lang_code_for(voice)

        audio_segments = []
        timings = []
        current_time = 0.0

        with TTSService._lock:
            pipeline = self._get_pipeline(lang_code)
            generator = pipeline(text, voice=voice, speed=speed)

            # Kokoro prints phoneme info to stdout — swallow it.
            with io.StringIO() as buf, contextlib.redirect_stdout(buf):
                for result in generator:
                    audio = result.audio
                    audio_segments.append(audio)

                    if word_timing and getattr(result, "tokens", None):
                        for t in result.tokens:
                            if t.start_ts is not None and t.end_ts is not None:
                                word = t.text.strip()
                                if word:
                                    timings.append({
                                        "word":  word,
                                        "start": round(current_time + t.start_ts, 4),
                                        "end":   round(current_time + t.end_ts,   4),
                                    })

                    current_time += len(audio) / self.SAMPLE_RATE

        if not audio_segments:
            return {"success": False, "error": "pipeline produced no audio"}

        combined_audio = np.concatenate(audio_segments)

        os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
        sf.write(output_path, combined_audio, self.SAMPLE_RATE)

        return {
            "success": True,
            "audio_path": output_path,
            "word_timings": timings if word_timing else None,
        }

    async def _generate_with_gtts(self, text: str, voice: str, output_path: str) -> dict:
        """Fallback TTS using gTTS (only when Kokoro is unavailable)."""
        try:
            from gtts import gTTS

            voice_lang_map = {
                "am_michael": "en",
                "am_sarah": "en",
                "bm_george": "en",
                "bm_emma": "en",
            }
            lang = voice_lang_map.get(voice, "en")

            tts = gTTS(text=text, lang=lang, slow=False)
            tts.save(output_path)

            return {
                "success": True,
                "audio_path": output_path,
                "word_timings": None,  # gTTS doesn't provide word timing
            }

        except Exception as e:
            logger.error(f"gTTS error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
