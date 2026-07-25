#!/usr/bin/env python3
"""
Video transcription script using faster-whisper
"""

import sys
import json
import argparse
import logging
import os
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
            audio_path = "/tmp/extracted_audio.ogg"
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
            segments, info = model.transcribe(
                transcribe_source,  # Use extracted audio if available
                beam_size=1,
                language="en",
                without_timestamps=False,
                word_timestamps=word_timestamps,  # Per-word timings for karaoke captions
                vad_filter=True,               # Enable voice activity detection to skip silence
                vad_parameters={"min_silence_duration_ms": 500},  # Silence threshold
                chunk_length=30               # Process in 30‑second chunks
            )
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
