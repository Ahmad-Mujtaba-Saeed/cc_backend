import asyncio
import logging
import subprocess
import json
import os
from pathlib import Path
import tempfile

logger = logging.getLogger(__name__)

class TranscriptionService:
    def __init__(self):
        self.transcribe_script = "/app/resources/scripts/transcribe.py"
    
    async def transcribe(self, video_path: str, language: str = "en", word_timestamps: bool = False) -> dict:
        """Transcribe video audio to text using faster-whisper"""
        try:
            logger.info(f"[TRANSCRIBE] Starting transcription")
            logger.info(f"[TRANSCRIBE] Video path: {video_path}")
            logger.info(f"[TRANSCRIBE] Language: {language}")
            logger.info(f"[TRANSCRIBE] Word timestamps: {word_timestamps}")
            logger.info(f"[TRANSCRIBE] Script path: {self.transcribe_script}")
            logger.info(f"[TRANSCRIBE] Script exists: {os.path.exists(self.transcribe_script)}")

            # Check if transcribe script exists
            if not os.path.exists(self.transcribe_script):
                logger.info(f"[TRANSCRIBE] Script not found, using fallback whisper method")
                # Fallback to direct whisper implementation
                return await self._transcribe_with_whisper(video_path, language, word_timestamps)

            # Use Python script
            cmd = [
                "python3",
                self.transcribe_script,
                video_path
            ]
            if word_timestamps:
                cmd.append("--word-timestamps")
            
            logger.info(f"[TRANSCRIBE] Running command: {' '.join(cmd)}")
            
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            
            logger.info(f"[TRANSCRIBE] Process started, PID: {process.pid}")
            
            stdout, stderr = await process.communicate()
            stdout_text = stdout.decode()
            stderr_text = stderr.decode()
            
            logger.info(f"[TRANSCRIBE] Process completed with return code: {process.returncode}")
            logger.info(f"[TRANSCRIBE] STDOUT length: {len(stdout_text)} bytes")
            logger.info(f"[TRANSCRIBE] STDERR length: {len(stderr_text)} bytes")
            
            if stderr_text:
                logger.info(f"[TRANSCRIBE] STDERR content (first 500 chars): {stderr_text[:500]}")
            
            # Filter out harmless Hugging Face hub warnings
            if "HF_TOKEN" in stderr_text or "unauthenticated requests" in stderr_text:
                # This is just a warning, not an error - ignore it
                logger.warning(f"[TRANSCRIBE] HF Hub warning (ignored): {stderr_text[:200]}")
                # Continue processing with stdout
            elif process.returncode != 0:
                logger.error(f"[TRANSCRIBE] Script execution failed with return code {process.returncode}")
                logger.error(f"[TRANSCRIBE] STDERR: {stderr_text}")
                return {
                    "success": False,
                    "error": f"Script failed with code {process.returncode}: {stderr_text}"
                }
            
            # Parse result
            logger.info(f"[TRANSCRIBE] Parsing JSON output...")
            if not stdout_text.strip():
                logger.error(f"[TRANSCRIBE] STDOUT is empty!")
                return {
                    "success": False,
                    "error": "Script produced no output"
                }
            
            try:
                result = json.loads(stdout_text)
                logger.info(f"[TRANSCRIBE] JSON parsed successfully")
                logger.info(f"[TRANSCRIBE] Result keys: {result.keys()}")
            except json.JSONDecodeError as e:
                logger.error(f"[TRANSCRIBE] JSON parse error: {str(e)}")
                logger.error(f"[TRANSCRIBE] STDOUT (first 500 chars): {stdout_text[:500]}")
                return {
                    "success": False,
                    "error": f"JSON parse error: {str(e)}"
                }

            segments = result.get("segments", [])
            text = " ".join([s.get("text", "").strip() for s in segments]).strip()

            # Duration: prefer the value reported by the model, otherwise fall
            # back to the end of the last segment so downstream clip selection
            # always receives a non-zero total duration.
            duration = result.get("duration", 0) or 0
            if not duration and segments:
                duration = segments[-1].get("end", 0) or 0
            
            logger.info(f"[TRANSCRIBE] Extracted {len(segments)} segments")
            logger.info(f"[TRANSCRIBE] Total text length: {len(text)} characters")
            logger.info(f"[TRANSCRIBE] Duration: {duration}s")

            return {
                "success": True,
                "segments": segments,
                "text": text,
                "duration": duration
            }
            
        except Exception as e:
            logger.error(f"[TRANSCRIBE] Exception occurred: {str(e)}", exc_info=True)
            return {
                "success": False,
                "error": f"Exception: {str(e)}"
            }
    
    async def _transcribe_with_whisper(self, video_path: str, language: str, word_timestamps: bool = False) -> dict:
        """Fallback transcription using whisper directly"""
        try:
            import whisper

            # Load model
            model = whisper.load_model("base")

            # Transcribe
            result = model.transcribe(video_path, language=language, word_timestamps=word_timestamps)

            # Format segments
            segments = []
            for segment in result["segments"]:
                words = [
                    {"word": w["word"].strip(), "start": w["start"], "end": w["end"]}
                    for w in segment.get("words", [])
                    if w.get("word", "").strip()
                ]
                segments.append({
                    "start": segment["start"],
                    "end": segment["end"],
                    "text": segment["text"],
                    "words": words
                })
            
            duration = 0
            if segments:
                duration = segments[-1].get("end", 0) or 0

            return {
                "success": True,
                "segments": segments,
                "text": result["text"],
                "duration": duration
            }
            
        except Exception as e:
            logger.error(f"Whisper transcription error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
