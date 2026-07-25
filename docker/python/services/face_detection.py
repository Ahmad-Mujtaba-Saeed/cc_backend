import asyncio
import logging
import subprocess
import json
import os
import cv2
import numpy as np

logger = logging.getLogger(__name__)

class FaceDetectionService:
    def __init__(self):
        self.face_detection_script = "/app/resources/scripts/face_detection.py"
    
    async def detect(self, video_path: str, detect_objects: bool = True) -> dict:
        """Detect faces and objects in video"""
        try:
            logger.info(f"Starting face detection for {video_path}")
            
            # Check if face detection script exists
            if not os.path.exists(self.face_detection_script):
                # Fallback to OpenCV face detection
                return await self._detect_with_opencv(video_path, detect_objects)
            
            # Use Python script
            # Determine command: detect-objects if detect_objects=True, otherwise just detect
            command = "detect-objects" if detect_objects else "detect"
            
            cmd = [
                "python3",
                self.face_detection_script,
                command,
                video_path
            ]
            
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            
            stdout, stderr = await process.communicate()
            stdout_str = stdout.decode(errors='replace')
            stderr_str = stderr.decode(errors='replace')
            
            logger.info(f"Face detection script exit code: {process.returncode}")
            logger.info(f"Face detection script stdout: {repr(stdout_str)}")
            logger.info(f"Face detection script stderr: {repr(stderr_str)}")
            
            if process.returncode != 0:
                logger.error(f"Face detection script error: {stderr_str}")
                return {
                    "success": False,
                    "error": stderr_str
                }
            
            if not stdout_str.strip():
                logger.error("Face detection script produced no output")
                return {
                    "success": False,
                    "error": "No output from face detection script"
                }
            
            # Extract JSON payload from stdout, ignoring any warnings or log lines.
            start_brace = stdout_str.find('{')
            end_brace = stdout_str.rfind('}')
            if start_brace == -1 or end_brace == -1 or end_brace < start_brace:
                logger.error("No JSON found in face detection script output")
                return {
                    "success": False,
                    "error": "No JSON in face detection script output"
                }
            json_part = stdout_str[start_brace:end_brace + 1]
            result = json.loads(json_part)
            return {
                "success": True,
                "faces": result.get("faces", []),
                "objects": result.get("objects") if detect_objects else [],
                "tracks": result.get("tracks")
            }
            
        except Exception as e:
            logger.error(f"Face detection error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
    
    async def _detect_with_opencv(self, video_path: str, detect_objects: bool) -> dict:
        """Fallback face detection using OpenCV"""
        try:
            # Load face cascade
            face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
            
            # Open video
            cap = cv2.VideoCapture(video_path)
            faces = []
            frame_count = 0
            
            while True:
                ret, frame = cap.read()
                if not ret:
                    break
                
                # Convert to grayscale
                gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
                
                # Detect faces
                detected_faces = face_cascade.detectMultiScale(gray, 1.1, 4)
                
                # Store face data
                for (x, y, w, h) in detected_faces:
                    face_data = {
                        "timestamp": frame_count / cap.get(cv2.CAP_PROP_FPS),
                        "x": x / frame.shape[1],
                        "y": y / frame.shape[0],
                        "width": w / frame.shape[1],
                        "height": h / frame.shape[0],
                        "confidence": 0.8  # OpenCV doesn't provide confidence
                    }
                    faces.append(face_data)
                
                frame_count += 1
            
            cap.release()
            
            return {
                "success": True,
                "faces": faces,
                "objects": [],  # Skip object detection in fallback
                "tracks": {}
            }
            
        except Exception as e:
            logger.error(f"OpenCV face detection error: {str(e)}")
            return {
                "success": False,
                "error": str(e)
            }
