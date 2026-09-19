#!/usr/bin/env python3
"""
Video transcription script using faster-whisper
"""

import sys
import json
import argparse
import logging
import os
import time
import shutil
import tempfile
import subprocess
from pathlib import Path
from faster_whisper import WhisperModel

# Try to import psutil for memory monitoring
try:
    import psutil
    HAS_PSUTIL = True
except ImportError:
    HAS_PSUTIL = False

# Setup logging to stderr so it doesn't interfere with JSON output
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - [TRANSCRIBE.PY] - %(levelname)s - %(message)s',
    stream=sys.stderr
)
logger = logging.getLogger(__name__)

def check_ffmpeg():
    """Check if FFmpeg is available"""
    try:
        result = subprocess.run(['ffmpeg', '-version'], capture_output=True, timeout=5)
        logger.info(f"FFmpeg check: return code {result.returncode}")
        return result.returncode == 0
    except Exception as e:
        logger.error(f"FFmpeg check failed: {str(e)}")
        return False

def extract_audio(video_path, output_audio_path):
    """Extract audio from video file to reduce memory footprint
    
    Transcribing audio-only is much more memory efficient than loading
    the entire video file into memory.
    """
    try:
        # Resolve a single output path and use it consistently for the ffmpeg
        # target, the size check, and the return value. Previously ffmpeg wrote
        # to a `.replace('.wav', '.ogg')` path while the size check ran against
        # the original path, which could raise and abort transcription.
        resolved_audio_path = output_audio_path.replace('.wav', '.ogg')

        logger.info(f"Extracting audio from video to {resolved_audio_path}...")
        logger.info(f"This reduces memory usage significantly")
        
        cmd = [
            'ffmpeg',
            '-i', video_path,
            '-vn',  # No video
            '-acodec', 'libopus',  # Opus codec (efficient compression)
            '-b:a', '32k',          # 32 kbps bitrate – good for speech, reduces size dramatically
            '-ar', '16000',          # 16kHz (optimal for Whisper)
            '-ac', '1',              # Mono
            '-y',                    # Overwrite output
            resolved_audio_path
        ]
        
        result = subprocess.run(
            cmd,
            capture_output=True,
            timeout=600,  # 10 minutes max
            text=True
        )
        
        if result.returncode != 0:
            logger.error(f"FFmpeg extraction failed: {result.stderr}")
            return None
        
        if not os.path.exists(resolved_audio_path):
            logger.error(f"FFmpeg reported success but output is missing: {resolved_audio_path}")
            return None
        
        audio_size_mb = os.path.getsize(resolved_audio_path) / (1024 * 1024)
        logger.info(f"Audio extracted successfully: {audio_size_mb:.2f} MB")
        return resolved_audio_path
        
    except Exception as e:
        logger.error(f"Error extracting audio: {str(e)}", exc_info=True)
        return None

def get_memory_info():
    """Get current memory usage"""
    if not HAS_PSUTIL:
        return {'rss_mb': 'unavailable', 'vms_mb': 'unavailable'}
    
    try:
        process = psutil.Process(os.getpid())
        memory_info = process.memory_info()
        return {
            'rss_mb': memory_info.rss / (1024 * 1024),  # Resident Set Size
            'vms_mb': memory_info.vms / (1024 * 1024),  # Virtual Memory Size
        }
    except:
        return {'rss_mb': 'error', 'vms_mb': 'error'}

CHUNK_SECONDS = int(os.environ.get("WHISPER_CHUNK_SECONDS", "600"))


def probe_duration(path):
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=nw=1:nk=1", path],
            capture_output=True, text=True, timeout=60,
        )
        return float(out.stdout.strip() or 0)
    except Exception:
        return 0.0


class _Seg:
    """A segment shifted onto the full-file clock (faster-whisper's are read-only)."""
    def __init__(self, seg, offset):
        self.start = seg.start + offset
        self.end = seg.end + offset
        self.text = seg.text
        self.words = [_Word(w, offset) for w in (seg.words or [])]


class _Word:
    def __init__(self, w, offset):
        self.word = w.word
        self.start = w.start + offset
        self.end = w.end + offset


class _Info:
    def __init__(self, language, probability, duration):
        self.language = language
        self.language_probability = probability
        self.duration = duration


def _run(model, source, word_timestamps):
    segs, info = model.transcribe(
        source,
        beam_size=1,
        language="en",
        without_timestamps=False,
        word_timestamps=word_timestamps,
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 500},
        chunk_length=30,
    )
    return list(segs), info


def transcribe_chunked(model, source, word_timestamps):
    total = probe_duration(source)
    if total <= CHUNK_SECONDS * 1.2:
        segs, info = _run(model, source, word_timestamps)
        return segs, info

    import gc
    tmpdir = tempfile.mkdtemp(prefix="whisper_chunks_")
    out = []
    lang, prob = "en", 1.0
    try:
        start = 0.0
        n = 0
        while start < total - 0.5:
            piece = os.path.join(tmpdir, f"c{n:03d}.ogg")
            subprocess.run(
                ["ffmpeg", "-v", "error", "-y", "-ss", f"{start:.3f}", "-t", str(CHUNK_SECONDS),
                 "-i", source, "-vn", "-ac", "1", "-ar", "16000", "-c:a", "libopus", "-b:a", "32k", piece],
                check=True, timeout=600,
            )
            segs, info = _run(model, piece, word_timestamps)
            lang, prob = info.language, info.language_probability
            out.extend(_Seg(sg, start) for sg in segs)
            logger.info(f"Chunk {n} ({start:.0f}s-{min(total, start + CHUNK_SECONDS):.0f}s): {len(segs)} segments")
            os.remove(piece)
            del segs, info
            gc.collect()
            start += CHUNK_SECONDS
            n += 1
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)
    return out, _Info(lang, prob, total)


def transcribe_video(video_path, word_timestamps=False):
    """Transcribe video using faster-whisper"""
    try:
        logger.info(f"Starting transcribe_video()")
        logger.info(f"Video path: {video_path}")
        
        # Check file size
        try:
            logger.info(f"Checking if video file exists...")
            if not os.path.exists(video_path):
                raise Exception(f"Video file does not exist: {video_path}")
            
            logger.info(f"Video file exists, getting size...")
            file_size_mb = os.path.getsize(video_path) / (1024 * 1024)
            logger.info(f"Video file size: {file_size_mb:.2f} MB")
        except Exception as file_err:
            logger.error(f"File check error: {str(file_err)}", exc_info=True)
            raise
        
        # Extract audio from video (much more memory efficient)
        audio_path = None
        try:
            # Create temp audio file in /tmp
            # Unique per run: a fixed name collided when two transcriptions ran
            # at once (the per-clip word-timing pass runs this script too).
            audio_path = f"/tmp/extracted_audio_{os.getpid()}_{int(time.time() * 1000)}.ogg"
            if os.path.exists(audio_path):
                os.remove(audio_path)
            
            logger.info("Extracting audio from video for memory efficiency...")
            extracted = extract_audio(video_path, audio_path)
            if extracted:
                logger.info("Using extracted audio for transcription")
                # Use the path actually written by ffmpeg
                audio_path = extracted
                transcribe_source = extracted
            else:
                logger.warning("Audio extraction failed, falling back to video")
                transcribe_source = video_path
        except Exception as extract_err:
            logger.error(f"Audio extraction error: {str(extract_err)}")
            logger.info("Falling back to transcribing video directly")
            transcribe_source = video_path
        
        # Log initial memory
        try:
            logger.info(f"Getting memory info...")
            mem = get_memory_info()
            logger.info(f"Memory before model load: RSS={mem['rss_mb']}, VMS={mem['vms_mb']}")
        except Exception as mem_err:
            logger.error(f"Memory check error: {str(mem_err)}", exc_info=True)
            # Don't fail on memory check, just log
        
        # Load the Whisper model
        logger.info("Loading Whisper model (base, int8 quantized for better stability)...")
        try:
            logger.info("Importing faster_whisper...")
            from faster_whisper import WhisperModel as FWModel
            logger.info("Creating WhisperModel instance with base model...")
            model = FWModel("base", compute_type="int8")  # base model ~142MB, int8 reduces size
            logger.info("Whisper model loaded successfully")
        except Exception as model_err:
            logger.error(f"Failed to load Whisper model: {str(model_err)}", exc_info=True)
            raise
        
        # Log memory after model load
        try:
            mem = get_memory_info()
            logger.info(f"Memory after model load: RSS={mem['rss_mb']}, VMS={mem['vms_mb']}")
        except:
            pass  # Don't fail on memory check
        
        # Transcribe the video with optimized settings
        logger.info("Starting transcription...")
        try:
            logger.info(f"Calling model.transcribe() with beam_size=1 (memory optimized)...")
            # beam_size=1 is faster and uses less memory
            # language='en' skips language detection to save memory
            # CHUNKED. faster-whisper decodes the whole file into float32 up
            # front: ~240 MB for an hour, ~660 MB for a 3-hour stream, and on
            # the 3.6 GB Docker VM that got the process OOM-killed (code -9)
            # on projects 194 and 198. Ten-minute pieces keep memory flat for
            # any length; timings are shifted back onto the full clock.
            segments, info = transcribe_chunked(model, transcribe_source, word_timestamps)
            logger.info("Transcription completed, converting segments...")
        except Exception as trans_err:
            logger.error(f"Failed during transcription: {str(trans_err)}", exc_info=True)
            raise
        finally:
            # Clean up extracted audio file
            if audio_path and os.path.exists(audio_path):
                try:
                    logger.info("Cleaning up extracted audio file...")
                    os.remove(audio_path)
                except:
                    pass  # Don't fail if cleanup fails
        
        # Log memory after transcription
        try:
            mem = get_memory_info()
            logger.info(f"Memory after transcription: RSS={mem['rss_mb']}, VMS={mem['vms_mb']}")
        except:
            pass  # Don't fail on memory check
        
        # Convert segments to the expected format
        logger.info("Converting segments to JSON format...")
        transcription_data = {
            "language": info.language,
            "language_probability": info.language_probability,
            "duration": info.duration,
            "segments": []
        }
        
        segment_count = 0
        for segment in segments:
            words = []
            if word_timestamps and segment.words:
                for w in segment.words:
                    word_text = w.word.strip()
                    if word_text:
                        words.append({
                            "word": word_text,
                            "start": w.start,
                            "end": w.end,
                        })

            transcription_data["segments"].append({
                "id": len(transcription_data["segments"]),
                "start": segment.start,
                "end": segment.end,
                "text": segment.text.strip(),
                "words": words  # populated only when --word-timestamps is passed
            })
            segment_count += 1
        
        logger.info(f"Converted {segment_count} segments")
        
        # Clean up model to free memory
        try:
            import gc
            del model
            del segments
            del info
            gc.collect()
            logger.info("Memory cleaned up after transcription")
        except:
            pass  # Don't fail on cleanup
        
        return transcription_data
        
    except Exception as e:
        logger.error(f"Error transcribing video: {str(e)}", exc_info=True)
        return None

def main():
    logger.info("=== Transcribe Script Starting ===")
    logger.info(f"Python version: {sys.version}")
    logger.info(f"Script arguments: {sys.argv}")
    logger.info(f"psutil available: {HAS_PSUTIL}")
    
    # Check FFmpeg
    logger.info("Checking for FFmpeg...")
    ffmpeg_available = check_ffmpeg()
    logger.info(f"FFmpeg available: {ffmpeg_available}")
    if not ffmpeg_available:
        logger.error("FFmpeg is not available! Video processing requires FFmpeg.")
    
    try:
        parser = argparse.ArgumentParser(description="Transcribe video using faster-whisper")
        parser.add_argument("video_path", help="Path to the video file")
        parser.add_argument(
            "--word-timestamps",
            action="store_true",
            help="Emit per-word timings in each segment's `words` array (for karaoke captions)"
        )
        args = parser.parse_args()

        logger.info(f"Video path argument: {args.video_path}")
        logger.info(f"Word timestamps: {args.word_timestamps}")
        
        # Check if video file exists
        if not Path(args.video_path).exists():
            logger.error(f"Video file not found: {args.video_path}")
            print(f"Video file not found: {args.video_path}", file=sys.stderr)
            sys.exit(1)
        
        logger.info("Video file exists, starting transcription...")
        
        # Transcribe the video
        result = transcribe_video(args.video_path, word_timestamps=args.word_timestamps)
        
        if result:
            logger.info("Transcription successful, outputting JSON...")
            print(json.dumps(result, indent=2))
            logger.info("=== Transcribe Script Completed Successfully ===")
            sys.stderr.flush()
            sys.stdout.flush()
        else:
            logger.error("Transcription returned None/empty result")
            print("Transcription failed", file=sys.stderr)
            sys.stderr.flush()
            sys.stdout.flush()
            sys.exit(1)
    except Exception as e:
        logger.error(f"Main error: {str(e)}", exc_info=True)
        sys.stderr.flush()
        sys.exit(1)

if __name__ == "__main__":
    main()
