import asyncio
import logging
import subprocess
import json
import os
import sys
import tempfile

logger = logging.getLogger(__name__)

class VideoEditingService:
    def __init__(self):
        self.video_editing_script = "/app/resources/scripts/video_editing.py"
    
    async def edit(self, video_path: str, audio_path: str = None,
                  output_path: str = None, edit_type: str = "face_crop",
                  settings: dict = None, face_data: dict = None) -> dict:
        """Edit video with various effects"""
        try:
            logger.info(f"Starting video editing: {edit_type}")
            
            if not output_path:
                output_path = os.path.join(
                    tempfile.gettempdir(),
                    f"edited_video_{int(asyncio.get_event_loop().time())}.mp4"
                )
            
            # Normalize edit type names between underscore and hyphen forms
            normalized_edit_type = edit_type.replace('_', '-')

            # Check if video editing script exists
            if not os.path.exists(self.video_editing_script):
                # Fallback to moviepy
                return await self._edit_with_moviepy(
                    video_path, audio_path, output_path, edit_type, settings, face_data
                )
            
            # Build command based on edit type
            cmd = [
                sys.executable,
                self.video_editing_script,
                normalized_edit_type,
                video_path,
                "--output", output_path
            ]
            
            # Add type-specific parameters
            if normalized_edit_type in ["face_crop", "face-crop"]:
                cmd.extend(["--face-data", json.dumps(face_data or {})])
                if settings.get("smooth_transitions"):
                    cmd.append("--smooth-transitions")
                if settings.get("focus_main_speaker"):
                    cmd.append("--focus-main-speaker")
                if settings.get("padding"):
                    cmd.extend(["--padding", str(settings["padding"])])
            
            elif normalized_edit_type == "auto-edit" and audio_path:
                cmd.extend(["--audio-path", audio_path])
                if settings.get("target_length"):
                    cmd.extend(["--target-length", str(settings["target_length"])])
                if settings.get("pace"):
                    cmd.extend(["--pace", settings["pace"]])
                if settings.get("style"):
                    cmd.extend(["--style", settings["style"]])
            
            elif normalized_edit_type == "combine-audio" and audio_path:
                cmd.extend(["--audio-path", audio_path])
                if settings.get("video_volume"):
                    cmd.extend(["--video-volume", str(settings["video_volume"])])
                if settings.get("audio_volume"):
                    cmd.extend(["--audio-volume", str(settings["audio_volume"])])
                if settings.get("fade_duration"):
                    cmd.extend(["--fade-duration", str(settings["fade_duration"])])
            
            logger.info(f"Video editing command: {cmd}")
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            
            stdout, stderr = await process.communicate()
            stdout_str = stdout.decode(errors='replace')
            stderr_str = stderr.decode(errors='replace')
            
            logger.info(f"Video editing script exit code: {process.returncode}")
            logger.info(f"Video editing script stdout: {repr(stdout_str)}")
            logger.info(f"Video editing script stderr: {repr(stderr_str)}")
            
            if process.returncode != 0:
                logger.error(f"Video editing script error: {stderr_str}")
                return {
                    "success": False,
                    "error": stderr_str or f"Video editing script failed with exit code {process.returncode}"
                }
            
            if not stdout_str.strip():
                logger.error("Video editing script produced no stdout")
                return {
                    "success": False,
                    "error": stderr_str or "Video editing script produced no stdout"
                }
            
            # Extract JSON from stdout (may contain debug/progress messages)
            result = None
            try:
                result = json.loads(stdout_str)
            except json.JSONDecodeError:
                # Try to extract JSON object from the output
                import re
                json_match = re.search(r'\{.*\}', stdout_str, re.DOTALL)
                if json_match:
                    try:
                        result = json.loads(json_match.group(0))
                    except json.JSONDecodeError as e:
                        logger.error(f"Failed to parse extracted JSON from video editing script output: {str(e)}")
                        return {
                            "success": False,
                            "error": f"Invalid JSON output from video editing script: {str(e)}; stdout={repr(stdout_str)}; stderr={repr(stderr_str)}"
                        }
                else:
                    logger.error(f"Failed to parse video editing script output as JSON: no JSON object found")
                    return {
                        "success": False,
                        "error": f"Invalid JSON output from video editing script: no JSON object found; stdout={repr(stdout_str)}; stderr={repr(stderr_str)}"
                    }
            
            return {
                "success": True,
                "output_path": output_path
            }
            
        except Exception as e:
            logger.error(f"Video editing error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
    
    async def _edit_with_moviepy(self, video_path: str, audio_path: str = None,
                               output_path: str = None, edit_type: str = "face_crop",
                               settings: dict = None, face_data: dict = None) -> dict:
        """Fallback video editing using moviepy"""
        try:
            from moviepy.editor import VideoFileClip, AudioFileClip, CompositeVideoClip
            
            # Load video
            video = VideoFileClip(video_path)
            
            if edit_type == "combine_audio" and audio_path:
                # Combine with audio
                audio = AudioFileClip(audio_path)
                
                # Match durations
                if audio.duration > video.duration:
                    audio = audio.subclip(0, video.duration)
                elif video.duration > audio.duration:
                    video = video.subclip(0, audio.duration)
                
                # Combine
                final_video = video.set_audio(audio)
                
            elif edit_type == "auto_edit":
                # Simple auto edit - speed up slightly
                final_video = video.fx(lambda v: v.speedx(1.1))
                
            else:
                # Default - just copy
                final_video = video
            
            # Write output
            final_video.write_videofile(
                output_path,
                codec='libx264',
                audio_codec='aac',
                fps=video.fps,
                preset='medium'
            )
            
            # Cleanup
            video.close()
            if 'audio' in locals():
                audio.close()
            final_video.close()
            
            return {
                "success": True,
                "output_path": output_path
            }
            
        except Exception as e:
            logger.error(f"MoviePy editing error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
