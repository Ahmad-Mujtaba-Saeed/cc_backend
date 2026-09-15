"""
Voice-clone narration service - Chatterbox-Nano (Resemble AI, MIT licence).

Why its own container: chatterbox-tts pins torch==2.6.0 and transformers==5.2.0,
which would fight the Kokoro / Demucs / Whisper stack in the `ai` image. Kept
apart, neither side can break the other, and this one can move to a GPU host
on its own.

Contract (called only by Laravel over the private compose network):
  GET  /health            model + device state
  POST /voices/prepare    clean a user's recording -> reference.wav + conds.pt
  POST /synthesize        text -> natural-sounding wav in that voice
  POST /voices/forget     drop a deleted voice from the in-memory cache

What makes the clone sound like a person rather than a TTS engine is mostly
what happens AROUND the model, so that is where this file spends its effort:
  - the reference is cleaned (rumble high-passed, dead air removed, long pauses
    shortened) so the model's 10-15 s conditioning window is all voice;
  - text is read in sentence-group chunks the small model handles reliably,
    instead of one long run that drifts or garbles;
  - every chunk is checked against its word count and regenerated once when it
    comes back far too long (a run-on / hallucination) or far too short
    (skipped words);
  - chunks are joined with the pause a speaker would actually leave at that
    punctuation, with click-free edges, and the result is loudness-matched.

Every path must live under VOICE_STORAGE_ROOT (the shared storage mount): the
service never reads or writes anywhere a caller merely names.
"""

import logging
import math
import os
import re
import subprocess
import threading
import time
from collections import OrderedDict
from contextlib import asynccontextmanager
from pathlib import Path
from typing import List, Optional, Tuple

import numpy as np
import soundfile as sf
from fastapi import FastAPI
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s [voice] %(message)s")
log = logging.getLogger("voice")

SR = 24000  # Chatterbox's S3GEN_SR - the model's native output rate
STORAGE_ROOT = os.path.realpath(os.getenv("VOICE_STORAGE_ROOT", "/var/www/storage"))
READY_TIMEOUT = float(os.getenv("VOICE_READY_TIMEOUT", "1200"))

# Reference recording. The model asserts > 5 s; below ~10 s likeness drops.
MIN_SPEECH_SECONDS = 6.0
GOOD_SPEECH_SECONDS = 10.0
MAX_REFERENCE_SECONDS = 30.0
MAX_GAP_SECONDS = 0.35

# Synthesis
CHUNK_CHARS = 230
HARD_SPLIT_CHARS = 280
MAX_TEXT_CHARS = 20000
WORDS_PER_SECOND = 2.6  # ~155 wpm, a natural narration pace
TARGET_LUFS = -18.0
PEAK_CEILING = 0.95
CONDS_CACHE_SIZE = 8


class EngineUnavailable(RuntimeError):
    pass


class RecordingRejected(Exception):
    """A recording that cannot make a good voice. The message is user-facing."""


class Engine:
    """One warm Chatterbox-Nano model shared by every request.

    torch modules are not thread-safe and the model keeps the active voice in
    `model.conds`, so inference is serialised: a request swaps its voice in
    and generates while holding the lock.
    """

    def __init__(self) -> None:
        self.model = None
        self.device: Optional[str] = None
        self.error: Optional[str] = None
        self.loaded_in: Optional[float] = None
        self.ready = threading.Event()
        self.lock = threading.Lock()
        self._conds: "OrderedDict[str, Tuple[float, object]]" = OrderedDict()

    def load(self) -> None:
        started = time.time()
        try:
            import torch

            threads = int(os.getenv("TORCH_THREADS", "0")) or (os.cpu_count() or 4)
            torch.set_num_threads(threads)
            device = os.getenv("VOICE_DEVICE") or ("cuda" if torch.cuda.is_available() else "cpu")

            from chatterbox.tts_turbo import ChatterboxTurboTTS

            log.info("loading Chatterbox-Nano on %s (%d threads); first boot downloads the weights", device, threads)
            self.model = ChatterboxTurboTTS.from_pretrained(device=device, nano=True)
            self.device = device
            self.loaded_in = round(time.time() - started, 1)
            log.info("Chatterbox-Nano ready in %.1fs", self.loaded_in)
        except Exception as exc:  # reported through /health
            self.error = f"{type(exc).__name__}: {exc}"
            log.exception("model load failed")
        finally:
            self.ready.set()

    def require(self):
        if not self.ready.wait(READY_TIMEOUT):
            raise EngineUnavailable("the voice model is still loading")
        if self.model is None:
            raise EngineUnavailable(self.error or "the voice model failed to load")
        return self.model

    def conditionals(self, voice_dir: str):
        """The voice's conditioning tensors, built once and cached on disk and
        in memory. Caller holds the lock."""
        from chatterbox.tts_turbo import Conditionals

        model = self.require()
        conds_path = os.path.join(voice_dir, "conds.pt")
        if not os.path.exists(conds_path):
            reference = os.path.join(voice_dir, "reference.wav")
            if not os.path.exists(reference):
                raise FileNotFoundError("this voice has no reference recording")
            model.prepare_conditionals(reference, norm_loudness=True)
            model.conds.save(Path(conds_path))
            self._conds.pop(conds_path, None)

        mtime = os.path.getmtime(conds_path)
        hit = self._conds.get(conds_path)
        if hit is not None and hit[0] == mtime:
            self._conds.move_to_end(conds_path)
            return hit[1]

        conds = Conditionals.load(conds_path, map_location="cpu").to(self.device)
        self._conds[conds_path] = (mtime, conds)
        while len(self._conds) > CONDS_CACHE_SIZE:
            self._conds.popitem(last=False)
        return conds

    def forget(self, voice_dir: str) -> None:
        self._conds.pop(os.path.join(voice_dir, "conds.pt"), None)


engine = Engine()


# --------------------------------------------------------------------------- paths + audio io

def _inside_storage(path: str) -> str:
    real = os.path.realpath(path)
    if real != STORAGE_ROOT and not real.startswith(STORAGE_ROOT + os.sep):
        raise PermissionError("path is outside the shared storage")
    return real


def _probe_sample_rate(path: str) -> int:
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "error", "-select_streams", "a:0",
             "-show_entries", "stream=sample_rate", "-of", "csv=p=0", path],
            capture_output=True, text=True, timeout=30,
        )
        first = (out.stdout or "").strip().splitlines()
        return int(first[0]) if first and first[0].isdigit() else 0
    except Exception:
        return 0


def _decode(path: str) -> np.ndarray:
    """Any container a phone or browser produces -> mono float32 at 24 kHz."""
    proc = subprocess.run(
        ["ffmpeg", "-nostdin", "-v", "error", "-i", path, "-vn", "-ac", "1",
         "-ar", str(SR), "-f", "f32le", "-"],
        capture_output=True, timeout=180,
    )
    if proc.returncode != 0 or not proc.stdout:
        raise ValueError(proc.stderr.decode(errors="ignore")[-300:].strip() or "no audio stream")
    return np.frombuffer(proc.stdout, dtype=np.float32).copy()


def _write_wav(path: str, audio: np.ndarray) -> None:
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = path + ".part.wav"
    sf.write(tmp, audio, SR, subtype="PCM_16")
    os.replace(tmp, path)  # never leave a half-written file where PHP looks


def _fade_edges(audio: np.ndarray, seconds: float = 0.008) -> np.ndarray:
    n = min(int(seconds * SR), audio.size // 2)
    if n > 0:
        ramp = np.linspace(0.0, 1.0, n, dtype=np.float32)
        audio[:n] *= ramp
        audio[-n:] *= ramp[::-1]
    return audio


# --------------------------------------------------------------------------- reference preparation

def prepare_reference(input_path: str, voice_dir: str) -> dict:
    import librosa
    from scipy.signal import butter, sosfilt

    raw = _decode(input_path)
    if raw.size < SR:
        raise RecordingRejected("That recording is under a second long. Upload 15-30 seconds of you talking.")

    warnings: List[str] = []
    source_rate = _probe_sample_rate(input_path)

    if float(np.mean(np.abs(raw) >= 0.999)) > 0.002:
        warnings.append("The recording clips (it was too loud for the mic), which can make the voice sound harsh. "
                        "Record a little further from the microphone.")
    if 0 < source_rate < 16000:
        warnings.append("The recording is phone-call quality. A 44.1 or 48 kHz recording clones noticeably better.")

    # DC offset + 70 Hz high-pass: removes rumble, desk knocks and mains hum
    # without touching the voice itself.
    audio = raw - float(np.mean(raw))
    audio = sosfilt(butter(4, 70, btype="highpass", fs=SR, output="sos"), audio).astype(np.float32)

    if float(np.max(np.abs(audio))) < 1e-3:
        raise RecordingRejected("That recording is silent. Check the microphone and upload it again.")

    frame_db = 20 * np.log10(np.maximum(librosa.feature.rms(y=audio, frame_length=2048, hop_length=512)[0], 1e-6))
    snr = float(np.percentile(frame_db, 90) - np.percentile(frame_db, 10))
    if snr < 12:
        warnings.append("There is a lot of background noise, and the clone copies it. "
                        "Record in a quiet room with no music, TV or fan.")
    elif snr < 20:
        warnings.append("Some background noise is audible. A quieter room will make the voice cleaner.")

    # Keep the talking, shorten the dead air. The model conditions on the first
    # 10-15 s, so every second of silence there is a second of voice lost.
    intervals = librosa.effects.split(audio, top_db=35, frame_length=1024, hop_length=256)
    pad, max_gap, limit = int(0.05 * SR), int(MAX_GAP_SECONDS * SR), int(MAX_REFERENCE_SECONDS * SR)
    pieces: List[np.ndarray] = []
    length = speech = 0
    prev_end: Optional[int] = None
    for start, end in intervals:
        start, end = max(0, int(start) - pad), min(audio.size, int(end) + pad)
        if prev_end is not None:
            start = max(start, prev_end)
            gap = start - prev_end
            if gap > 0:
                keep = min(gap, max_gap)  # real room tone, not digital silence
                pieces.append(audio[prev_end:prev_end + keep])
                length += keep
        if end <= start:
            continue
        pieces.append(audio[start:end])
        length += end - start
        speech += end - start
        prev_end = end
        if length >= limit:
            break

    speech_seconds = speech / SR
    if speech_seconds < MIN_SPEECH_SECONDS:
        raise RecordingRejected(
            f"Only {speech_seconds:.1f} seconds of speech were found. "
            "Upload at least 10 seconds of clear talking (20-30 seconds is best)."
        )
    if speech_seconds < GOOD_SPEECH_SECONDS:
        warnings.append(f"Only {speech_seconds:.0f} seconds of speech. 15-30 seconds gives a closer likeness.")

    cleaned = np.concatenate(pieces)[:limit].astype(np.float32)
    cleaned = _fade_edges(cleaned / max(float(np.max(np.abs(cleaned))), 1e-6) * 0.89)
    _write_wav(os.path.join(voice_dir, "reference.wav"), cleaned)

    return {
        "speech_seconds": round(speech_seconds, 2),
        "reference_seconds": round(cleaned.size / SR, 2),
        "source_sample_rate": source_rate,
        "snr_db": round(snr, 1),
        "warnings": warnings,
    }


# --------------------------------------------------------------------------- text planning

_ABBREVIATION = re.compile(r"\b(?:Mr|Mrs|Ms|Dr|Prof|Sr|Jr|St|Mt|vs|etc|approx|No|Fig|e\.g|i\.e)\.$", re.IGNORECASE)
_TAG = re.compile(r"\[[a-z ]{2,20}\]", re.IGNORECASE)  # [laugh], [sigh] ... Nano speaks these


def _clean_text(text: str) -> str:
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"https?://\S+", "", text)
    text = re.sub(r"[*_#`~]+", "", text)  # markdown emphasis is not something to read aloud
    text = re.sub(r"[ \t]+", " ", text)
    return text.strip()


def _sentences(paragraph: str) -> List[str]:
    merged: List[str] = []
    for part in (p for p in re.split(r"(?<=[.!?…])\s+", paragraph) if p):
        if merged and _ABBREVIATION.search(merged[-1]):
            merged[-1] = f"{merged[-1]} {part}"  # "Dr. Smith" is one sentence
        else:
            merged.append(part)
    return merged


def _pack(pieces: List[str], limit: int) -> List[str]:
    out: List[str] = []
    current = ""
    for piece in pieces:
        if current and len(current) + 1 + len(piece) > limit:
            out.append(current)
            current = piece
        else:
            current = f"{current} {piece}".strip()
    if current:
        out.append(current)
    return out


def _split_long(sentence: str) -> List[str]:
    if len(sentence) <= HARD_SPLIT_CHARS:
        return [sentence]
    pieces: List[str] = []
    for clause in re.split(r"(?<=[,;:])\s+", sentence):
        pieces.extend([clause] if len(clause) <= HARD_SPLIT_CHARS else _pack(clause.split(), CHUNK_CHARS))
    return _pack(pieces, CHUNK_CHARS)


def _pause_after(chunk: str, paragraph_break: bool) -> float:
    if paragraph_break:
        return 0.65
    end = chunk.rstrip()[-1:]
    if end in ("?", "!"):
        return 0.38
    if end in (".", "…"):
        return 0.32
    if end in (",", ";", ":"):
        return 0.18
    return 0.22


def plan_chunks(text: str) -> List[Tuple[str, float]]:
    """(chunk, pause after it) pairs. Several short sentences share a chunk so
    the model phrases them together, the way a person reads a paragraph."""
    paragraphs = [p for p in (" ".join(b.split()) for b in re.split(r"\n\s*\n", _clean_text(text))) if p]
    plan: List[Tuple[str, float]] = []
    for p_index, paragraph in enumerate(paragraphs):
        units = [u for s in _sentences(paragraph) for u in _split_long(s)]
        chunks = [c for c in _pack(units, CHUNK_CHARS) if re.search(r"\w", _TAG.sub("", c))]
        for c_index, chunk in enumerate(chunks):
            paragraph_break = c_index == len(chunks) - 1 and p_index < len(paragraphs) - 1
            plan.append((chunk, _pause_after(chunk, paragraph_break)))
    return plan


# --------------------------------------------------------------------------- synthesis

def _trim(audio: np.ndarray) -> np.ndarray:
    import librosa

    if audio.size == 0:
        return audio
    _, (start, end) = librosa.effects.trim(audio, top_db=40, frame_length=1024, hop_length=256)
    pad = int(0.03 * SR)
    return _fade_edges(audio[max(0, start - pad):min(audio.size, end + pad)].copy())


def _render_chunk(model, conds, text: str) -> np.ndarray:
    words = len(re.findall(r"[A-Za-z0-9']+", _TAG.sub("", text))) or 1
    expected = words / WORDS_PER_SECOND
    best: Optional[Tuple[float, np.ndarray]] = None

    # Upstream sampling defaults first; a steadier, lower temperature only
    # when the first take is clearly wrong.
    for temperature in (0.8, 0.6):
        model.conds = conds
        wav = model.generate(text, temperature=temperature, top_p=0.95, top_k=1000, repetition_penalty=1.2)
        audio = _trim(wav.squeeze(0).detach().cpu().numpy().astype(np.float32, copy=True))
        seconds = audio.size / SR
        score = abs(math.log(max(seconds, 0.05) / expected))
        if best is None or score < best[0]:
            best = (score, audio)
        if abs(seconds - expected) <= 1.5 or 0.5 <= seconds / expected <= 2.0:
            break
        log.warning("chunk came back %.1fs for ~%.1fs of text; regenerating: %r", seconds, expected, text[:60])
    return best[1]


def _master(audio: np.ndarray) -> np.ndarray:
    try:
        import pyloudnorm as pyln

        if audio.size > SR // 2:
            loudness = pyln.Meter(SR).integrated_loudness(audio.astype(np.float64))
            if math.isfinite(loudness):
                audio = audio * (10 ** ((TARGET_LUFS - loudness) / 20))
    except Exception as exc:
        log.warning("loudness normalisation skipped: %s", exc)
    peak = float(np.max(np.abs(audio))) if audio.size else 0.0
    if peak > PEAK_CEILING:
        audio = audio * (PEAK_CEILING / peak)
    return audio.astype(np.float32)


def synthesize(voice_dir: str, text: str, output_path: str) -> dict:
    import torch

    model = engine.require()
    plan = plan_chunks(text)
    if not plan:
        raise RecordingRejected("There is nothing to say in that text.")

    started = time.time()
    parts: List[np.ndarray] = [np.zeros(int(0.06 * SR), dtype=np.float32)]
    with engine.lock, torch.inference_mode():
        conds = engine.conditionals(voice_dir)
        for chunk, pause in plan:
            parts.append(_render_chunk(model, conds, chunk))
            parts.append(np.zeros(int(pause * SR), dtype=np.float32))
    parts[-1] = np.zeros(int(0.3 * SR), dtype=np.float32)  # a natural tail, not the last inter-chunk pause

    audio = _master(np.concatenate(parts))
    _write_wav(output_path, audio)

    duration = audio.size / SR
    taken = time.time() - started
    log.info("synthesized %.1fs of audio in %.1fs (%d chunks, %.2fx realtime)",
             duration, taken, len(plan), duration / max(taken, 1e-3))
    return {"duration": round(duration, 3), "chunks": len(plan), "seconds_taken": round(taken, 2)}


# --------------------------------------------------------------------------- http

@asynccontextmanager
async def lifespan(_app: FastAPI):
    threading.Thread(target=engine.load, name="model-load", daemon=True).start()
    yield


app = FastAPI(title="Voice clone TTS (Chatterbox-Nano)", version="1.0.0", lifespan=lifespan)


class PrepareRequest(BaseModel):
    input_path: str
    voice_dir: str


class SynthesizeRequest(BaseModel):
    voice_dir: str
    text: str
    output_path: str


class ForgetRequest(BaseModel):
    voice_dir: str


def _fail(code: str, message: str) -> dict:
    return {"success": False, "code": code, "error": message}


@app.get("/health")
def health():
    return {
        "status": "healthy",
        "model": "chatterbox-nano",
        "model_ready": engine.model is not None,
        "loading": not engine.ready.is_set(),
        "device": engine.device,
        "loaded_in": engine.loaded_in,
        "error": engine.error,
    }


@app.post("/voices/prepare")
def prepare(req: PrepareRequest):
    try:
        input_path = _inside_storage(req.input_path)
        voice_dir = _inside_storage(req.voice_dir)
        if not os.path.isfile(input_path):
            return _fail("missing", "The uploaded recording is missing.")
        engine.require()

        report = prepare_reference(input_path, voice_dir)

        import torch

        conds_path = os.path.join(voice_dir, "conds.pt")
        with engine.lock, torch.inference_mode():
            if os.path.exists(conds_path):
                os.remove(conds_path)  # a re-prepare must not reuse the old voice
            engine.forget(voice_dir)
            engine.conditionals(voice_dir)

        return {"success": True, **report}
    except RecordingRejected as exc:
        return _fail("too_short", str(exc))
    except EngineUnavailable as exc:
        return _fail("engine_unavailable", str(exc))
    except PermissionError as exc:
        return _fail("forbidden", str(exc))
    except ValueError as exc:
        return _fail("decode_failed", f"The file could not be read as audio ({exc}).")
    except AssertionError:
        return _fail("too_short", "Not enough clear speech in that recording. Upload 15-30 seconds of talking.")
    except Exception as exc:
        log.exception("prepare failed")
        return _fail("error", f"{type(exc).__name__}: {exc}")


@app.post("/synthesize")
def synthesize_endpoint(req: SynthesizeRequest):
    try:
        if len(req.text) > MAX_TEXT_CHARS:
            return _fail("too_long", f"Text is limited to {MAX_TEXT_CHARS} characters per request.")
        voice_dir = _inside_storage(req.voice_dir)
        output_path = _inside_storage(req.output_path)
        if not os.path.isdir(voice_dir):
            return _fail("missing", "This voice no longer exists.")

        result = synthesize(voice_dir, req.text, output_path)
        return {"success": True, "audio_path": output_path, **result}
    except RecordingRejected as exc:
        return _fail("empty_text", str(exc))
    except EngineUnavailable as exc:
        return _fail("engine_unavailable", str(exc))
    except (PermissionError, FileNotFoundError) as exc:
        return _fail("missing", str(exc))
    except Exception as exc:
        log.exception("synthesis failed")
        return _fail("error", f"{type(exc).__name__}: {exc}")


@app.post("/voices/forget")
def forget(req: ForgetRequest):
    try:
        engine.forget(_inside_storage(req.voice_dir))
    except PermissionError as exc:
        return _fail("forbidden", str(exc))
    return {"success": True}
