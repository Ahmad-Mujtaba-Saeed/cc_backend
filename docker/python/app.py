from fastapi import FastAPI, File, UploadFile, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List, Dict, Any
import os
import json
import asyncio
import logging
from pathlib import Path
import tempfile
import shutil

# Import AI service modules
try:
    from docker.python.services.transcription import TranscriptionService
    from docker.python.services.tts import TTSService
    from docker.python.services.face_detection import FaceDetectionService
    from docker.python.services.video_editing import VideoEditingService
    from docker.python.services.audio_separation import AudioSeparationService
except ImportError as e:
    print(f"Import error: {e}")
    # Fallback services
    TranscriptionService = lambda: None
    TTSService = lambda: None
    FaceDetectionService = lambda: None
    VideoEditingService = lambda: None
    AudioSeparationService = lambda: None

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(
    title="AI Video Processing API",
    description="Microservice for AI-powered video processing",
    version="1.0.0"
)

# CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Storage paths
SHARED_STORAGE = "/app/storage"
UPLOADS_DIR = os.path.join(SHARED_STORAGE, "uploads")
PROCESSED_DIR = os.path.join(SHARED_STORAGE, "processed")

# Ensure directories exist
os.makedirs(UPLOADS_DIR, exist_ok=True)
os.makedirs(PROCESSED_DIR, exist_ok=True)

# Pydantic models for requests/responses
class TranscriptionRequest(BaseModel):
    video_path: str
    language: Optional[str] = "en"
    word_timestamps: Optional[bool] = False  # per-word timings for karaoke captions

class TranscriptionResponse(BaseModel):
    success: bool
    segments: Optional[List[Dict[str, Any]]] = None
    text: Optional[str] = None
    duration: Optional[float] = None
    error: Optional[str] = None

class TTSRequest(BaseModel):
    text: str
    voice: str = "am_michael"
    output_path: str
    word_timing: bool = False

class TTSResponse(BaseModel):
    success: bool
    audio_path: Optional[str] = None
    word_timings: Optional[List[Dict[str, Any]]] = None
    error: Optional[str] = None

class SeparateVocalsRequest(BaseModel):
    input_path: str
    output_path: str

class SeparateVocalsResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    error: Optional[str] = None

class FaceDetectionRequest(BaseModel):
    video_path: str
    detect_objects: bool = True

class FaceDetectionResponse(BaseModel):
    success: bool
    faces: Optional[List[Dict[str, Any]]] = None
    objects: Optional[List[Dict[str, Any]]] = None
    tracks: Optional[Dict[str, Any]] = None
    error: Optional[str] = None

class VideoEditRequest(BaseModel):
    video_path: str
    face_data: Optional[Dict[str, Any]] = None
    audio_path: Optional[str] = None
    output_path: str
    edit_type: str  # "face_crop", "auto_edit", "combine_audio"
    settings: Optional[Dict[str, Any]] = {}

class VideoEditResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    error: Optional[str] = None

class HealthResponse(BaseModel):
    status: str
    services: Dict[str, bool]

# Initialize services
try:
    transcription_service = TranscriptionService()
    tts_service = TTSService()
    face_detection_service = FaceDetectionService()
    video_editing_service = VideoEditingService()
    audio_separation_service = AudioSeparationService()
except Exception as e:
    logger.error(f"Service initialization error: {e}")
    transcription_service = None
    tts_service = None
    face_detection_service = None
    video_editing_service = None
    audio_separation_service = None

@app.get("/health", response_model=HealthResponse)
async def health_check():
    """Health check endpoint"""
    return HealthResponse(
        status="healthy",
        services={
            "transcription": transcription_service is not None,
            "tts": tts_service is not None,
            "face_detection": face_detection_service is not None,
            "video_editing": video_editing_service is not None,
            "audio_separation": audio_separation_service is not None
        }
    )

@app.post("/transcribe", response_model=TranscriptionResponse)
async def transcribe_video(request: TranscriptionRequest):
    """Transcribe video audio to text"""
    try:
        logger.info(f"[API] /transcribe endpoint called")
        logger.info(f"[API] Request video_path: {request.video_path}")
        logger.info(f"[API] Request language: {request.language}")
        
        # Validate file exists
        video_path = os.path.join(SHARED_STORAGE, request.video_path)
        logger.info(f"[API] Full video path: {video_path}")
        logger.info(f"[API] Video file exists: {os.path.exists(video_path)}")
        
        if not os.path.exists(video_path):
            logger.error(f"[API] Video file not found at {video_path}")
            raise HTTPException(status_code=404, detail="Video file not found")
        
        logger.info(f"[API] Calling transcription_service.transcribe()")
        logger.info(f"[API] Word timestamps: {request.word_timestamps}")

        # Perform transcription
        result = await transcription_service.transcribe(
            video_path, request.language, bool(request.word_timestamps)
        )
        
        logger.info(f"[API] Transcription service returned: success={result.get('success')}")
        if not result.get("success"):
            logger.error(f"[API] Transcription failed: {result.get('error')}")
        else:
            logger.info(f"[API] Transcription succeeded, {len(result.get('segments', []))} segments")
        
        if result["success"]:
            return TranscriptionResponse(
                success=True,
                segments=result["segments"],
                text=result.get("text"),
                duration=result.get("duration", 0)
            )
        else:
            return TranscriptionResponse(
                success=False,
                error=result.get("error", "Unknown error")
            )
            
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"[API] Transcription exception: {str(e)}", exc_info=True)
        return TranscriptionResponse(
            success=False,
            error=f"Exception: {str(e)}"
        )

@app.post("/tts", response_model=TTSResponse)
async def generate_tts(request: TTSRequest):
    """Generate text-to-speech audio"""
    try:
        logger.info(f"Starting TTS for voice: {request.voice}")
        
        # Generate TTS
        result = await tts_service.generate(
            text=request.text,
            voice=request.voice,
            output_path=os.path.join(SHARED_STORAGE, request.output_path),
            word_timing=request.word_timing
        )
        
        if result["success"]:
            return TTSResponse(
                success=True,
                audio_path=request.output_path,
                word_timings=result.get("word_timings")
            )
        else:
            return TTSResponse(
                success=False,
                error=result.get("error", "Unknown error")
            )
            
    except Exception as e:
        logger.error(f"TTS error: {str(e)}")
        return TTSResponse(
            success=False,
            error=str(e)
        )

@app.post("/separate-vocals", response_model=SeparateVocalsResponse)
async def separate_vocals(request: SeparateVocalsRequest):
    """
    Strip vocals from an audio file, returning the background (no-vocals) stem.
    Resolves paths against shared storage, like the other endpoints.
    """
    try:
        if audio_separation_service is None:
            return SeparateVocalsResponse(success=False, error="separation service not available")

        input_path = request.input_path
        if not os.path.isabs(input_path):
            input_path = os.path.join(SHARED_STORAGE, input_path)

        output_path = request.output_path
        if not os.path.isabs(output_path):
            output_path = os.path.join(SHARED_STORAGE, output_path)

        if not os.path.exists(input_path):
            return SeparateVocalsResponse(success=False, error=f"Input audio not found: {input_path}")

        logger.info(f"separate-vocals: {input_path} -> {output_path}")
        result = await audio_separation_service.separate_background(input_path, output_path)

        if result.get("success"):
            return SeparateVocalsResponse(success=True, output_path=result.get("output_path", output_path))
        return SeparateVocalsResponse(success=False, error=result.get("error", "Unknown error"))

    except Exception as e:
        logger.error(f"separate-vocals error: {str(e)}")
        return SeparateVocalsResponse(success=False, error=str(e))


@app.post("/detect-faces", response_model=FaceDetectionResponse)
async def detect_faces(request: FaceDetectionRequest):
    """Detect faces and objects in video"""
    try:
        logger.info(f"Starting face detection for: {request.video_path}")
        
        # Validate file exists
        video_path = os.path.join(SHARED_STORAGE, request.video_path)
        if not os.path.exists(video_path):
            raise HTTPException(status_code=404, detail="Video file not found")
        
        # Perform face detection
        result = await face_detection_service.detect(
            video_path=video_path,
            detect_objects=request.detect_objects
        )
        
        if result["success"]:
            return FaceDetectionResponse(
                success=True,
                faces=result["faces"],
                objects=result.get("objects"),
                tracks=result.get("tracks")
            )
        else:
            return FaceDetectionResponse(
                success=False,
                error=result.get("error", "Unknown error")
            )
            
    except Exception as e:
        logger.error(f"Face detection error: {str(e)}")
        return FaceDetectionResponse(
            success=False,
            error=str(e)
        )

@app.post("/edit-video", response_model=VideoEditResponse)
async def edit_video(request: VideoEditRequest):
    """Edit video with various effects"""
    try:
        logger.info(f"Starting video edit: {request.edit_type}")
        
        # Validate input files
        video_path = os.path.join(SHARED_STORAGE, request.video_path)
        if not os.path.exists(video_path):
            raise HTTPException(status_code=404, detail="Video file not found")
        
        audio_path = None
        if request.audio_path:
            audio_path = os.path.join(SHARED_STORAGE, request.audio_path)
            if not os.path.exists(audio_path):
                raise HTTPException(status_code=404, detail="Audio file not found")
        
        output_path = os.path.join(SHARED_STORAGE, request.output_path)
        
        # Perform video editing
        logger.info(f"Edit-video request: edit_type={request.edit_type}, video_path={request.video_path}, output_path={request.output_path}, face_data_present={request.face_data is not None}, settings={request.settings}")
        
        result = await video_editing_service.edit(
            video_path=video_path,
            audio_path=audio_path,
            output_path=output_path,
            edit_type=request.edit_type,
            settings=request.settings,
            face_data=request.face_data
        )
        logger.info(f"Edit-video result: {result}")
        
        if result["success"]:
            return VideoEditResponse(
                success=True,
                output_path=request.output_path
            )
        else:
            return VideoEditResponse(
                success=False,
                error=result.get("error", "Unknown error")
            )
            
    except Exception as e:
        logger.error(f"Video editing error: {str(e)}")
        return VideoEditResponse(
            success=False,
            error=str(e)
        )

# ─── Helper Functions ──────────────────────────────────────────────────────

def calculate_dimensions_for_aspect_ratio(aspect_ratio: str, base_dimension: int = 1080) -> tuple:
    """
    Calculate width and height based on aspect ratio string.
    Returns (width, height) tuple.
    
    Supported ratios: 9:16, 16:9, 1:1, 4:3, 3:4
    Base dimension (1080) is used as reference, scaled to appropriate size.
    """
    aspect_map = {
        '9:16': (1080, 1920),      # Portrait
        '16:9': (1920, 1080),      # Landscape
        '1:1': (1080, 1080),       # Square
        '4:3': (1440, 1080),       # Landscape 4:3
        '3:4': (1080, 1440),       # Portrait 3:4
    }
    return aspect_map.get(aspect_ratio, (1080, 1920))  # Default to 9:16

# ─── AI Image Shorts: Pydantic models ───────────────────────────────────────

class DetectCharactersRequest(BaseModel):
    image_paths: List[str]

class DetectCharactersResponse(BaseModel):
    success: bool
    characters: Optional[List[Dict[str, Any]]] = None
    error: Optional[str] = None

class ApplyVibrateRequest(BaseModel):
    image_paths: List[str]
    character_data: Optional[List[Dict[str, Any]]] = None
    options: Optional[Dict[str, Any]] = {}

class ApplyVibrateResponse(BaseModel):
    success: bool
    animated_segments: Optional[List[str]] = None
    error: Optional[str] = None

class SceneDefinition(BaseModel):
    segment_path: str
    order: int

class AssembleScenesRequest(BaseModel):
    scenes: List[SceneDefinition]
    audio_path: str
    output_path: str
    settings: Optional[Dict[str, Any]] = {}

class AssembleScenesResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    error: Optional[str] = None

# ─── AI Image Shorts: Endpoints ──────────────────────────────────────────────

@app.post("/detect-characters", response_model=DetectCharactersResponse)
async def detect_characters(request: DetectCharactersRequest):
    """
    Detect human/character bounding boxes in a list of images.
    Uses OpenCV DNN (if available) or returns placeholder bboxes so the
    pipeline can always proceed.
    """
    try:
        logger.info(f"detect-characters: processing {len(request.image_paths)} images")
        results = []

        for img_path in request.image_paths:
            # Resolve path (images land in PHP storage which is mounted at /app/storage)
            resolved = img_path
            if not os.path.exists(resolved):
                # Try interpreting as relative to shared storage
                resolved = os.path.join(SHARED_STORAGE, img_path)

            if not os.path.exists(resolved):
                logger.warning(f"detect-characters: image not found at {resolved}, using placeholder")
                results.append({
                    "image_path": img_path,
                    "bounding_boxes": [],
                    "has_character": False
                })
                continue

            try:
                import cv2
                img = cv2.imread(resolved)
                h, w = img.shape[:2] if img is not None else (1792, 1024)

                # Use a simple heuristic: treat the central 60% of the image
                # as the "character region" – good enough for vibrate masking
                char_x = int(w * 0.2)
                char_y = int(h * 0.1)
                char_w = int(w * 0.6)
                char_h = int(h * 0.8)

                results.append({
                    "image_path": img_path,
                    "bounding_boxes": [
                        {"x": char_x, "y": char_y, "width": char_w, "height": char_h, "confidence": 0.75}
                    ],
                    "has_character": True
                })
            except Exception as cv_err:
                logger.warning(f"detect-characters: cv2 failed ({cv_err}), using placeholder")
                results.append({
                    "image_path": img_path,
                    "bounding_boxes": [],
                    "has_character": False
                })

        return DetectCharactersResponse(success=True, characters=results)

    except Exception as e:
        logger.error(f"detect-characters error: {str(e)}")
        return DetectCharactersResponse(success=False, error=str(e))


@app.post("/apply-vibrate", response_model=ApplyVibrateResponse)
async def apply_vibrate(request: ApplyVibrateRequest):
    """
    Create short animated video clips from still images by applying a subtle
    ken-burns / vibrate pan-zoom effect using FFmpeg.
    Returns paths to per-scene .mp4 segments.
    """
    try:
        logger.info(f"apply-vibrate: processing {len(request.image_paths)} images")

        opts = request.options or {}
        # Cinematic documentary motion: `motion` picks the camera move,
        # `scare` fires a brief decaying impact shake (never a constant buzz),
        # `duration` renders the segment at the scene's real narration length.
        strength = float(opts.get("vibrate_strength", 0.0))
        zoom_strength = float(opts.get("zoom_strength", 0.12))
        zoom_effect = (opts.get("zoom_effect") or "zoom_in")
        motion = opts.get("motion")
        if motion not in ("push_in", "pull_back", "drift_left", "drift_right"):
            # Legacy callers only send zoom_effect — map it onto the new moves.
            motion = "pull_back" if zoom_effect == "zoom_out" else "push_in"
        scare = bool(opts.get("scare", strength > 0))
        seg_duration = max(1.0, min(20.0, float(opts.get("duration", 3.0))))

        # Get aspect ratio from options and calculate dimensions dynamically
        aspect_ratio = opts.get("aspect_ratio", "9:16")
        width, height = calculate_dimensions_for_aspect_ratio(aspect_ratio)
        logger.info(f"apply-vibrate: using aspect ratio {aspect_ratio} → {width}x{height}")
        
        segments_dir = os.path.join(SHARED_STORAGE, "app", "public", "projects",
                                    str((request.options or {}).get("project_id", "temp")),
                                    "segments")
        os.makedirs(segments_dir, exist_ok=True)

        animated_segments = []

        for idx, img_path in enumerate(request.image_paths):
            resolved = img_path
            if not os.path.exists(resolved):
                resolved = os.path.join(SHARED_STORAGE, img_path)

            if not os.path.exists(resolved):
                logger.warning(f"apply-vibrate: image not found at {resolved}, skipping")
                continue

            # Use segment_index from options if provided (for proper numbering when processing one image at a time)
            # Otherwise use the loop index
            segment_idx = (request.options or {}).get("segment_index", idx)
            segment_path = os.path.join(segments_dir, f"scene_{segment_idx:03d}.mp4")
            
            logger.info(f"apply-vibrate: creating segment with index {segment_idx} for image {img_path}")

            # Cinematic slow camera moves computed per-frame with zoompan.
            #
            # Two structural fixes over the old effect:
            #  - the source is pre-scaled to 2x the output and CROP-FILLED (no
            #    black bars), so zoompan's integer-pixel crop steps land on
            #    half-pixels of the output — the old visible stepping jitter
            #    ("cheap vibration") is gone;
            #  - shake is a brief exponentially-decaying impact burst on the
            #    scripted scare beat only, never a constant whole-frame buzz.
            fps = 30
            duration_frames = max(fps, int(round(seg_duration * fps)))
            up_w, up_h = width * 2, height * 2

            # Subtle documentary travel (8%–16%); a scare beat pushes harder.
            z_amt = min(0.16, max(0.08, zoom_strength * 1.4))
            if scare:
                z_amt = min(0.22, z_amt * 1.6)
            progress = f"(on/{duration_frames})"

            # Start slightly zoomed so pans/shake always have crop margin.
            z_base = 1.06 if scare else 1.02
            cx = "iw/2-(iw/zoom/2)"
            cy = "ih/2-(ih/zoom/2)"

            if motion == "pull_back":
                z_expr = f"{z_base + z_amt:.4f}-{z_amt:.4f}*{progress}"
            elif motion in ("drift_left", "drift_right"):
                z_expr = f"{z_base + 0.06:.4f}"
                travel = f"(iw-iw/zoom)"
                cx = (f"{travel}*(1-{progress})" if motion == "drift_left"
                      else f"{travel}*{progress}")
            else:  # push_in
                z_expr = f"{z_base:.4f}+{z_amt:.4f}*{progress}"

            # Legacy focal hints keep working for old callers.
            if zoom_effect == "in_from_top":
                cy = "0"
            elif zoom_effect == "in_from_bottom":
                cy = "ih-(ih/zoom)"

            if scare:
                # Impact hit: strong for ~0.25s, gone by ~0.7s. Pixel values are
                # in 2x-source space (half of this on screen).
                amp = min(30.0, max(10.0, strength * 260.0))
                decay = f"exp(-on/{fps * 0.22:.2f})"
                extra_x = f"+{amp:.1f}*{decay}*sin(on*5.3)"
                extra_y = f"+{amp * 0.8:.1f}*{decay}*cos(on*4.1)"
            else:
                # Barely-there handheld breathing so stills feel alive.
                extra_x = "+4.0*sin(on*0.11)"
                extra_y = "+3.0*cos(on*0.09)"

            # NOTE: zoompan defaults its output size to hd720 (1280x720, landscape).
            # Without an explicit `s=`, it would squeeze the 9:16 framing into a
            # landscape box. Pin it to the target dimensions to preserve aspect.
            zoompan_filter = (
                f"zoompan="
                f"z='{z_expr}':"
                f"x='{cx}{extra_x}':"
                f"y='{cy}{extra_y}':"
                f"d={duration_frames}:s={width}x{height}:fps={fps}"
            )

            # Fill the frame (center-crop the overflow) instead of padding with
            # black bars, then feed zoompan a 2x supersampled source.
            scale_filter = (
                f"scale={up_w}:{up_h}:force_original_aspect_ratio=increase,"
                f"crop={up_w}:{up_h}"
            )

            cmd = [
                "ffmpeg", "-y",
                "-loop", "1",
                "-i", resolved,
                "-vf", f"{scale_filter},{zoompan_filter}",
                "-t", f"{seg_duration:.3f}",
                "-r", str(fps),
                "-c:v", "libx264",
                "-pix_fmt", "yuv420p",
                "-preset", "fast",
                segment_path
            ]

            logger.info(f"apply-vibrate: running ffmpeg for scene {idx}")
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await proc.communicate()

            if proc.returncode != 0:
                logger.error(f"apply-vibrate ffmpeg failed for scene {segment_idx}: {stderr.decode(errors='replace')}")
                # Fallback: a static clip at the scene's real duration, crop-filled
                # at output size (not the 2x supersample the animated path uses).
                fallback_scale = (
                    f"scale={width}:{height}:force_original_aspect_ratio=increase,"
                    f"crop={width}:{height}"
                )
                fallback_cmd = [
                    "ffmpeg", "-y",
                    "-loop", "1",
                    "-i", resolved,
                    "-vf", fallback_scale,
                    "-t", f"{seg_duration:.3f}",
                    "-r", str(fps),
                    "-c:v", "libx264",
                    "-pix_fmt", "yuv420p",
                    "-preset", "fast",
                    segment_path
                ]
                proc2 = await asyncio.create_subprocess_exec(
                    *fallback_cmd,
                    stdout=asyncio.subprocess.PIPE,
                    stderr=asyncio.subprocess.PIPE
                )
                await proc2.communicate()

            if os.path.exists(segment_path):
                animated_segments.append(segment_path)
                logger.info(f"apply-vibrate: segment ready at {segment_path}")
            else:
                logger.error(f"apply-vibrate: segment not created for scene {idx}")

        if not animated_segments:
            return ApplyVibrateResponse(success=False, error="No animated segments could be created")

        return ApplyVibrateResponse(success=True, animated_segments=animated_segments)

    except Exception as e:
        logger.error(f"apply-vibrate error: {str(e)}")
        return ApplyVibrateResponse(success=False, error=str(e))


@app.post("/assemble-scenes", response_model=AssembleScenesResponse)
async def assemble_scenes(request: AssembleScenesRequest):
    """
    Concatenate scene segments (video clips) in order, then replace their
    audio track with the generated narration audio using FFmpeg.
    """
    try:
        logger.info(f"assemble-scenes: assembling {len(request.scenes)} scenes")

        output_path = request.output_path
        if not os.path.isabs(output_path):
            output_path = os.path.join(SHARED_STORAGE, output_path)

        os.makedirs(os.path.dirname(output_path), exist_ok=True)

        audio_path = request.audio_path
        if not os.path.isabs(audio_path):
            audio_path = os.path.join(SHARED_STORAGE, audio_path)

        # Sort scenes by order
        sorted_scenes = sorted(request.scenes, key=lambda s: s.order)
        segment_paths = [s.segment_path for s in sorted_scenes]

        # Verify all segments exist
        missing = [p for p in segment_paths if not os.path.exists(p)]
        if missing:
            logger.warning(f"assemble-scenes: missing segments {missing}")
            segment_paths = [p for p in segment_paths if os.path.exists(p)]

        if not segment_paths:
            return AssembleScenesResponse(success=False, error="No valid video segments to assemble")

        # Create a concat file for FFmpeg
        concat_file = output_path + ".concat.txt"
        with open(concat_file, "w") as f:
            for seg in segment_paths:
                f.write(f"file '{seg}'\n")

        # Step 1: Concatenate all segments into a silent merged video
        silent_output = output_path + ".silent.mp4"
        
        # Get aspect ratio from settings and calculate dimensions dynamically
        aspect_ratio = (request.settings or {}).get("aspect_ratio", "9:16")
        width, height = calculate_dimensions_for_aspect_ratio(aspect_ratio)
        logger.info(f"assemble-scenes: using aspect ratio {aspect_ratio} → {width}x{height}")
        
        # Ensure consistent aspect ratio during concatenation
        # Scale all segments with dynamic dimensions while preserving aspect ratio and centering
        scale_filter = f"scale={width}:{height}:force_original_aspect_ratio=decrease,pad={width}:{height}:(ow-iw)/2:(oh-ih)/2"
        
        concat_cmd = [
            "ffmpeg", "-y",
            "-f", "concat",
            "-safe", "0",
            "-i", concat_file,
            "-vf", scale_filter,
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-preset", "fast",
            "-an",
            silent_output
        ]

        logger.info("assemble-scenes: concatenating segments")
        proc = await asyncio.create_subprocess_exec(
            *concat_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await proc.communicate()

        if proc.returncode != 0 or not os.path.exists(silent_output):
            error_msg = stderr.decode(errors='replace')
            logger.error(f"assemble-scenes concat failed: {error_msg}")
            return AssembleScenesResponse(success=False, error=f"Scene concatenation failed: {_ffmpeg_error_tail(error_msg)}")

        # Optional background music, mixed UNDER the narration.
        settings = request.settings or {}
        music_path = settings.get("background_music_path")
        if music_path and not os.path.isabs(music_path):
            music_path = os.path.join(SHARED_STORAGE, music_path)
        try:
            music_volume = float(settings.get("music_volume", 0.15))
        except (TypeError, ValueError):
            music_volume = 0.15
        music_volume = max(0.0, min(1.0, music_volume))
        has_music = bool(music_path) and os.path.exists(music_path)
        if music_path and not has_music:
            logger.warning(f"assemble-scenes: background music not found at {music_path}, skipping")

        # Step 2: Mix narration audio onto the silent video
        if os.path.exists(audio_path):
            if has_music:
                # Loop/trim the music to the video length, lower its volume, and
                # mix it under the narration. `-shortest` on the music input
                # combined with amix duration=first keeps output bound to the
                # narration/video length. Narration stays at full volume.
                logger.info(f"assemble-scenes: mixing narration with background music (vol={music_volume})")
                filter_complex = (
                    f"[1:a]volume=1.0[narr];"
                    f"[2:a]volume={music_volume},aloop=loop=-1:size=2e9[bg];"
                    f"[narr][bg]amix=inputs=2:duration=first:dropout_transition=2[aout]"
                )
                mix_cmd = [
                    "ffmpeg", "-y",
                    "-i", silent_output,
                    "-i", audio_path,
                    "-i", music_path,
                    "-filter_complex", filter_complex,
                    "-map", "0:v:0",
                    "-map", "[aout]",
                    "-c:v", "copy",
                    "-c:a", "aac",
                    "-shortest",
                    output_path
                ]
            else:
                mix_cmd = [
                    "ffmpeg", "-y",
                    "-i", silent_output,
                    "-i", audio_path,
                    "-map", "0:v:0",
                    "-map", "1:a:0",
                    "-c:v", "copy",
                    "-c:a", "aac",
                    "-shortest",
                    output_path
                ]
                logger.info("assemble-scenes: mixing narration audio")
            proc2 = await asyncio.create_subprocess_exec(
                *mix_cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout2, stderr2 = await proc2.communicate()

            if proc2.returncode != 0 or not os.path.exists(output_path):
                logger.error(f"assemble-scenes audio mix failed: {stderr2.decode(errors='replace')}")
                # Fallback: just use the silent version
                os.rename(silent_output, output_path)
            else:
                # Cleanup silent intermediate
                if os.path.exists(silent_output):
                    os.remove(silent_output)
        else:
            logger.warning(f"assemble-scenes: audio file not found at {audio_path}, using silent video")
            os.rename(silent_output, output_path)

        # Cleanup concat file
        if os.path.exists(concat_file):
            os.remove(concat_file)

        if not os.path.exists(output_path):
            return AssembleScenesResponse(success=False, error="Output video was not created")

        logger.info(f"assemble-scenes: done → {output_path}")
        return AssembleScenesResponse(success=True, output_path=output_path)

    except Exception as e:
        logger.error(f"assemble-scenes error: {str(e)}")
        return AssembleScenesResponse(success=False, error=str(e))


@app.post("/upload")
async def upload_file(file: UploadFile = File(...)):
    """Upload file to shared storage"""
    try:
        # Generate unique filename
        file_extension = os.path.splitext(file.filename)[1]
        filename = f"{file.filename}_{int(asyncio.get_event_loop().time())}{file_extension}"
        file_path = os.path.join(UPLOADS_DIR, filename)
        
        # Save file
        with open(file_path, "wb") as buffer:
            content = await file.read()
            buffer.write(content)
        
        return {
            "success": True,
            "filename": filename,
            "path": f"uploads/{filename}",
            "size": os.path.getsize(file_path)
        }
        
    except Exception as e:
        logger.error(f"Upload error: {str(e)}")
        return {
            "success": False,
            "error": str(e)
        }

@app.get("/files/{file_path:path}")
async def get_file(file_path: str):
    """Serve files from shared storage"""
    try:
        full_path = os.path.join(SHARED_STORAGE, file_path)
        if not os.path.exists(full_path):
            raise HTTPException(status_code=404, detail="File not found")
        
        return FileResponse(full_path)
        
    except Exception as e:
        logger.error(f"File serving error: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.delete("/files/{file_path:path}")
async def delete_file(file_path: str):
    """Delete file from shared storage"""
    try:
        full_path = os.path.join(SHARED_STORAGE, file_path)
        if not os.path.exists(full_path):
            raise HTTPException(status_code=404, detail="File not found")
        
        os.remove(full_path)
        return {"success": True, "message": "File deleted"}
        
    except Exception as e:
        logger.error(f"File deletion error: {str(e)}")
        return {"success": False, "error": str(e)}


# ─── YT + Gameplay Short: Pydantic models ──────────────────────────────────

class CutVideoClipRequest(BaseModel):
    source_path: str
    start_seconds: float
    end_seconds: float
    output_path: str
    project_id: int

class CutVideoClipResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    duration: Optional[float] = None
    file_size: Optional[int] = None
    error: Optional[str] = None
    detail: Optional[str] = None

class ClipSegmentRange(BaseModel):
    start_seconds: float
    end_seconds: float

class CutVideoSegmentsRequest(BaseModel):
    source_path: str
    segments: List[ClipSegmentRange]
    output_path: str
    project_id: int

class LoopVideoRequest(BaseModel):
    source_path: str
    target_duration_seconds: float
    output_width: int = 1080
    output_height: int = 768
    output_path: str
    project_id: int

class LoopVideoResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    error: Optional[str] = None
    detail: Optional[str] = None

class ComposeShortRequest(BaseModel):
    main_clip_path: str
    # Optional: when omitted the main clip fills the whole 9:16 frame
    # (gameplay bottom panel disabled by the user).
    gameplay_clip_path: Optional[str] = None
    captions_ass_path: str
    output_path: str
    project_id: int
    aspect_ratio: str = "9:16"
    caption_position: str = "top_section"

class ComposeShortResponse(BaseModel):
    success: bool
    output_path: Optional[str] = None
    file_size: Optional[int] = None
    error: Optional[str] = None
    detail: Optional[str] = None

class GenerateThumbnailRequest(BaseModel):
    video_path: str
    time_seconds: float = 2.0
    output_path: str
    width: int = 1080
    height: int = 1920
    project_id: int

class GenerateThumbnailResponse(BaseModel):
    success: bool
    thumbnail_path: Optional[str] = None
    error: Optional[str] = None
    detail: Optional[str] = None

# ─── YT + Gameplay Short: Endpoints ─────────────────────────────────────────

def _ffmpeg_error_tail(stderr: str, max_len: int = 600) -> str:
    """Extract the meaningful part of FFmpeg stderr.

    FFmpeg prints its version + full `configuration:` banner first, which is
    well over 500 chars, so naive head-truncation hides the real error. The
    actual failure is at the *end* of stderr, so we drop the banner/build lines
    and return the tail.
    """
    if not stderr:
        return "(no ffmpeg output)"
    skip_prefixes = ('ffmpeg version', '  built with', '  configuration:', '  lib')
    lines = [
        ln for ln in stderr.splitlines()
        if ln.strip() and not ln.startswith(skip_prefixes)
    ]
    tail = "\n".join(lines[-8:]) if lines else stderr
    return tail[-max_len:]


def _to_local_path(path: str) -> str:
    """Map a PHP-container path to this container's namespace.

    The PHP app/worker containers mount the project at /var/www and pass
    absolute paths (e.g. /var/www/storage/app/public/...) to this service,
    which mounts the same host files at /app. When such a path doesn't resolve
    here, rewrite the /var/www prefix to /app so it points at the shared host
    file. Paths that already exist (both roots mounted, or running outside
    Docker) are returned unchanged. Safe for output paths too: the file won't
    exist yet, so we remap whenever /app is present.
    """
    if not path or os.path.exists(path):
        return path
    if path.startswith("/var/www/") and os.path.isdir("/app"):
        return "/app/" + path[len("/var/www/"):]
    return path



@app.post("/cut-video-clip", response_model=CutVideoClipResponse)
async def cut_video_clip(request: CutVideoClipRequest):
    """
    Cut a segment from a video using fast seeking.
    Uses -ss before -i for fast keyframe seeking, then -t for duration.
    """
    try:
        request.source_path = _to_local_path(request.source_path)
        request.output_path = _to_local_path(request.output_path)
        logger.info(f"cut-video-clip: cutting {request.start_seconds}s to {request.end_seconds}s from {request.source_path}")

        if not os.path.exists(request.source_path):
            raise HTTPException(status_code=404, detail=f"Source video not found: {request.source_path}")

        os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
        duration = request.end_seconds - request.start_seconds

        # Fast path: stream-copy (10-100x faster, no quality loss). This fails
        # when the source codecs can't be muxed into MP4 — e.g. YouTube
        # downloads that are actually VP9/Opus (WebM) despite the .mp4 name.
        # In that case we fall back to re-encoding into H.264/AAC.
        copy_cmd = [
            'ffmpeg', '-y',
            '-ss', str(request.start_seconds),
            '-i', request.source_path,
            '-t', str(duration),
            '-c', 'copy',
            '-avoid_negative_ts', 'make_zero',
            request.output_path,
        ]
        reencode_cmd = [
            'ffmpeg', '-y',
            '-ss', str(request.start_seconds),
            '-i', request.source_path,
            '-t', str(duration),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-c:a', 'aac', '-b:a', '192k',
            '-avoid_negative_ts', 'make_zero',
            request.output_path,
        ]

        async def run_ffmpeg(cmd):
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                limit=256 * 1024
            )
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=300)
            ok = proc.returncode == 0 and os.path.exists(request.output_path) \
                and os.path.getsize(request.output_path) > 0
            return ok, stderr.decode(errors='replace')

        ok, err = await run_ffmpeg(copy_cmd)
        if not ok:
            logger.warning(
                "cut-video-clip: stream copy failed, retrying with re-encode. "
                f"stream-copy error tail: {_ffmpeg_error_tail(err)}"
            )
            ok, err = await run_ffmpeg(reencode_cmd)

        if not ok:
            tail = _ffmpeg_error_tail(err)
            logger.error(f"cut-video-clip: FFmpeg error (full): {err}")
            raise HTTPException(status_code=500, detail=f"FFmpeg error: {tail}")

        file_size = os.path.getsize(request.output_path)
        if file_size == 0:
            raise HTTPException(status_code=500, detail="Output file is empty")
        
        logger.info(f"cut-video-clip: clip created, size={file_size}, duration={duration}s")
        return CutVideoClipResponse(
            success=True,
            output_path=request.output_path,
            duration=duration,
            file_size=file_size
        )
        
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="Video cut operation timed out")
    except Exception as e:
        logger.error(f"cut-video-clip error: {str(e)}")
        return CutVideoClipResponse(
            success=False,
            error=str(e),
            detail=str(e)
        )


@app.post("/cut-video-segments", response_model=CutVideoClipResponse)
async def cut_video_segments(request: CutVideoSegmentsRequest):
    """
    Cut multiple sub-ranges from a video and concatenate them into one clip.

    Used to skip the empty parts (silence/dead air) inside a selected clip.
    Always re-encodes: concatenating at arbitrary (non-keyframe) points can't
    be stream-copied. Each range uses input seeking (-ss before -i), which is
    frame-accurate when re-encoding and avoids decoding the whole source.
    """
    try:
        request.source_path = _to_local_path(request.source_path)
        request.output_path = _to_local_path(request.output_path)

        segments = [s for s in request.segments if s.end_seconds > s.start_seconds]
        if not segments:
            raise HTTPException(status_code=422, detail="No valid segments provided")
        if len(segments) > 40:
            raise HTTPException(status_code=422, detail="Too many segments (max 40)")

        if not os.path.exists(request.source_path):
            raise HTTPException(status_code=404, detail=f"Source video not found: {request.source_path}")

        os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
        total_duration = sum(s.end_seconds - s.start_seconds for s in segments)
        logger.info(
            f"cut-video-segments: {len(segments)} ranges, {total_duration:.2f}s total "
            f"from {request.source_path}"
        )

        cmd = ['ffmpeg', '-y']
        for s in segments:
            cmd += ['-ss', str(s.start_seconds), '-t', str(s.end_seconds - s.start_seconds), '-i', request.source_path]

        n = len(segments)
        chains = []
        for i in range(n):
            chains.append(f"[{i}:v:0]setpts=PTS-STARTPTS[v{i}]")
            chains.append(f"[{i}:a:0]asetpts=PTS-STARTPTS[a{i}]")
        pairs = ''.join(f"[v{i}][a{i}]" for i in range(n))
        filter_complex = ';'.join(chains) + f";{pairs}concat=n={n}:v=1:a=1[outv][outa]"

        cmd += [
            '-filter_complex', filter_complex,
            '-map', '[outv]',
            '-map', '[outa]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-c:a', 'aac', '-b:a', '192k',
            '-movflags', '+faststart',
            request.output_path,
        ]

        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=256 * 1024
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=600)

        if proc.returncode != 0 or not os.path.exists(request.output_path) \
                or os.path.getsize(request.output_path) == 0:
            err = stderr.decode(errors='replace')
            logger.error(f"cut-video-segments: FFmpeg error (full): {err}")
            raise HTTPException(status_code=500, detail=f"FFmpeg error: {_ffmpeg_error_tail(err)}")

        file_size = os.path.getsize(request.output_path)
        logger.info(f"cut-video-segments: clip created, size={file_size}, duration={total_duration:.2f}s")
        return CutVideoClipResponse(
            success=True,
            output_path=request.output_path,
            duration=total_duration,
            file_size=file_size
        )

    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="Video segment cut timed out")
    except Exception as e:
        logger.error(f"cut-video-segments error: {str(e)}")
        return CutVideoClipResponse(
            success=False,
            error=str(e),
            detail=str(e)
        )


@app.post("/loop-video", response_model=LoopVideoResponse)
async def loop_video(request: LoopVideoRequest):
    """
    Loop a video to match target duration, then scale to target dimensions.
    If source is shorter than target, loop it. If longer, trim it.
    """
    try:
        request.source_path = _to_local_path(request.source_path)
        request.output_path = _to_local_path(request.output_path)
        logger.info(f"loop-video: looping to {request.target_duration_seconds}s, scaling to {request.output_width}x{request.output_height}")

        if not os.path.exists(request.source_path):
            raise HTTPException(status_code=404, detail=f"Gameplay source not found: {request.source_path}")

        os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
        w = request.output_width
        h = request.output_height
        
        scale_filter = (
            f"scale={w}:{h}:force_original_aspect_ratio=increase,"
            f"crop={w}:{h},"
            f"setsar=1,fps=30"
        )
        
        cmd = [
            'ffmpeg',
            '-stream_loop', '-1',
            '-i', request.source_path,
            '-t', str(request.target_duration_seconds),
            '-vf', scale_filter,
            '-an',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            request.output_path,
            '-y'
        ]
        
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=256 * 1024
        )
        
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=600)
        
        if proc.returncode != 0 or not os.path.exists(request.output_path):
            error_msg = stderr.decode(errors='replace')
            logger.error(f"loop-video: FFmpeg error (full): {error_msg}")
            raise HTTPException(status_code=500, detail=f"FFmpeg error: {_ffmpeg_error_tail(error_msg)}")
        
        logger.info(f"loop-video: looped video created at {request.output_path}")
        return LoopVideoResponse(
            success=True,
            output_path=request.output_path
        )
        
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="Loop video operation timed out")
    except Exception as e:
        logger.error(f"loop-video error: {str(e)}")
        return LoopVideoResponse(
            success=False,
            error=str(e),
            detail=str(e)
        )


@app.post("/compose-short", response_model=ComposeShortResponse)
async def compose_short(request: ComposeShortRequest):
    """
    Compose final 9:16 short (1080×1920):
    - With gameplay: top panel (60%) 1080×1152 main clip, bottom panel (40%)
      1080×768 gameplay, stacked.
    - Without gameplay (gameplay_clip_path omitted): main clip fills the full
      1080×1920 frame.
    - Karaoke captions burned in via ASS file; audio from main clip only.
    """
    try:
        request.main_clip_path = _to_local_path(request.main_clip_path)
        if request.gameplay_clip_path:
            request.gameplay_clip_path = _to_local_path(request.gameplay_clip_path)
        request.captions_ass_path = _to_local_path(request.captions_ass_path)
        request.output_path = _to_local_path(request.output_path)

        with_gameplay = bool(request.gameplay_clip_path)
        logger.info(
            f"compose-short: composing {request.aspect_ratio} "
            f"({'split with gameplay' if with_gameplay else 'fullscreen, no gameplay'}) "
            f"with captions at {request.caption_position}"
        )

        inputs_to_check = [("main clip", request.main_clip_path),
                           ("captions", request.captions_ass_path)]
        if with_gameplay:
            inputs_to_check.insert(1, ("gameplay clip", request.gameplay_clip_path))
        for label, p in inputs_to_check:
            if not os.path.exists(p):
                raise HTTPException(status_code=404, detail=f"Compose input missing ({label}): {p}")

        os.makedirs(os.path.dirname(request.output_path), exist_ok=True)

        # Escape ASS path for FFmpeg filter
        ass_escaped = request.captions_ass_path.replace('\\', '/').replace(':', '\\:')

        if with_gameplay:
            filter_complex = (
                "[0:v]scale=1080:1152:force_original_aspect_ratio=increase,"
                "crop=1080:1152,setsar=1,fps=30[top];"
                "[1:v]scale=1080:768:force_original_aspect_ratio=increase,"
                "crop=1080:768,setsar=1,fps=30[bottom];"
                "[top][bottom]vstack=inputs=2[stacked];"
                f"[stacked]ass={ass_escaped}[out]"
            )
            input_args = ['-i', request.main_clip_path, '-i', request.gameplay_clip_path]
        else:
            filter_complex = (
                "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,"
                "crop=1080:1920,setsar=1,fps=30[full];"
                f"[full]ass={ass_escaped}[out]"
            )
            input_args = ['-i', request.main_clip_path]

        cmd = [
            'ffmpeg',
            *input_args,
            '-filter_complex', filter_complex,
            '-map', '[out]',
            '-map', '0:a:0',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '22',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            request.output_path,
            '-y'
        ]
        
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=256 * 1024
        )
        
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=600)
        
        if proc.returncode != 0 or not os.path.exists(request.output_path):
            error_msg = stderr.decode(errors='replace')
            logger.error(f"compose-short: FFmpeg error (full): {error_msg}")
            raise HTTPException(status_code=500, detail=f"FFmpeg error: {_ffmpeg_error_tail(error_msg)}")
        
        file_size = os.path.getsize(request.output_path)
        
        logger.info(f"compose-short: final short created, size={file_size}, dimensions=1080x1920")
        return ComposeShortResponse(
            success=True,
            output_path=request.output_path,
            file_size=file_size
        )
        
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="Compose operation timed out")
    except Exception as e:
        logger.error(f"compose-short error: {str(e)}")
        return ComposeShortResponse(
            success=False,
            error=str(e),
            detail=str(e)
        )


@app.post("/generate-thumbnail", response_model=GenerateThumbnailResponse)
async def generate_thumbnail(request: GenerateThumbnailRequest):
    """
    Generate a thumbnail from a video at specified time.
    """
    try:
        request.video_path = _to_local_path(request.video_path)
        request.output_path = _to_local_path(request.output_path)
        logger.info(f"generate-thumbnail: extracting frame at {request.time_seconds}s")

        os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
        
        cmd = [
            'ffmpeg',
            '-ss', str(request.time_seconds),
            '-i', request.video_path,
            '-vframes', '1',
            '-vf', f"scale={request.width}:{request.height}:force_original_aspect_ratio=decrease,pad={request.width}:{request.height}:(ow-iw)/2:(oh-ih)/2",
            '-q:v', '3',
            request.output_path,
            '-y'
        ]
        
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=256 * 1024
        )
        
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=60)
        
        if proc.returncode != 0 or not os.path.exists(request.output_path):
            error_msg = stderr.decode(errors='replace')
            logger.warning(f"generate-thumbnail: FFmpeg warning: {_ffmpeg_error_tail(error_msg)}")
            # Non-fatal, return success anyway
        
        logger.info(f"generate-thumbnail: thumbnail created at {request.output_path}")
        return GenerateThumbnailResponse(
            success=True,
            thumbnail_path=request.output_path
        )
        
    except Exception as e:
        logger.warning(f"generate-thumbnail error (non-fatal): {str(e)}")
        # Non-fatal - return success anyway
        return GenerateThumbnailResponse(
            success=True,
            thumbnail_path=request.output_path
        )

# ---------------------------------------------------------------------------
# Explainer asset pipeline (copilot.md §8): smart crop + duplicate-look guard.
# ---------------------------------------------------------------------------

class SmartCropRequest(BaseModel):
    image_path: str

class SmartCropResponse(BaseModel):
    success: bool
    fx: Optional[float] = None
    fy: Optional[float] = None
    error: Optional[str] = None

class ImagePhashRequest(BaseModel):
    image_paths: List[str]

class ImagePhashResponse(BaseModel):
    success: bool
    hashes: Optional[List[Optional[str]]] = None
    error: Optional[str] = None


def _spectral_saliency_focus(image_path: str):
    """Focal point (fx, fy in 0..1) via spectral-residual saliency.

    The classic Hou/Zhang spectral residual, implemented directly on numpy
    FFTs — cv2.saliency lives in opencv-contrib, which this container doesn't
    ship, and the algorithm is ~10 lines anyway. The focus is the weighted
    centroid of the squared saliency map (squared so one strong subject beats
    diffuse texture), which is what object-position/crop origins want.
    """
    import cv2
    import numpy as np

    img = cv2.imread(image_path)
    if img is None:
        raise ValueError("unreadable image")
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    small = cv2.resize(gray, (128, 128)).astype(np.float32)

    f = np.fft.fft2(small)
    log_amp = np.log1p(np.abs(f))
    phase = np.angle(f)
    residual = log_amp - cv2.blur(log_amp, (3, 3))
    sal = np.abs(np.fft.ifft2(np.exp(residual) * np.exp(1j * phase))) ** 2
    sal = cv2.GaussianBlur(sal.astype(np.float32), (11, 11), 2.5)

    w = sal ** 2
    total = float(w.sum())
    if total <= 0:
        return 0.5, 0.5
    ys, xs = np.mgrid[0:128, 0:128]
    fx = float((w * xs).sum() / total) / 127.0
    fy = float((w * ys).sum() / total) / 127.0
    return min(1.0, max(0.0, fx)), min(1.0, max(0.0, fy))


def _dhash_hex(image_path: str) -> str:
    """64-bit difference hash (dHash) as 16 hex chars — PIL only."""
    from PIL import Image

    with Image.open(image_path) as im:
        im = im.convert("L").resize((9, 8), Image.LANCZOS)
        px = list(im.getdata())
    bits = 0
    for row in range(8):
        for col in range(8):
            left = px[row * 9 + col]
            right = px[row * 9 + col + 1]
            bits = (bits << 1) | (1 if left > right else 0)
    return f"{bits:016x}"


@app.post("/smart-crop", response_model=SmartCropResponse)
async def smart_crop(request: SmartCropRequest):
    """Saliency focal point of an image (explainer §8: object-position +
    mask_wipe_circle origin)."""
    try:
        path = _to_local_path(request.image_path)
        if not os.path.exists(path):
            return SmartCropResponse(success=False, error="Image not found")
        fx, fy = await asyncio.to_thread(_spectral_saliency_focus, path)
        return SmartCropResponse(success=True, fx=round(fx, 4), fy=round(fy, 4))
    except Exception as e:
        logger.warning(f"smart-crop error (non-fatal): {e}")
        return SmartCropResponse(success=False, error=str(e))


@app.post("/image-phash", response_model=ImagePhashResponse)
async def image_phash(request: ImagePhashRequest):
    """Perceptual (difference) hashes for a batch of images — the explainer's
    duplicate-look guard compares hamming distances on the PHP side."""
    try:
        hashes: List[Optional[str]] = []
        for p in request.image_paths[:64]:
            try:
                hashes.append(await asyncio.to_thread(_dhash_hex, _to_local_path(p)))
            except Exception as e:
                logger.info(f"image-phash: skipping {p}: {e}")
                hashes.append(None)
        return ImagePhashResponse(success=True, hashes=hashes)
    except Exception as e:
        logger.warning(f"image-phash error (non-fatal): {e}")
        return ImagePhashResponse(success=False, error=str(e))


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
