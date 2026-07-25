# Social Video Maker - Complete Workflow Documentation

## Table of Contents
1. [Project Architecture](#project-architecture)
2. [System Overview](#system-overview)
3. [Template Workflows](#template-workflows)
   - [YT Automation Short](#yt-automation-short)
   - [AI Image-Based Shorts](#ai-image-based-shorts)
   - [AI Horror Shorts](#ai-horror-shorts)
4. [Services & Functions](#services--functions)
5. [Docker Setup](#docker-setup)
6. [Data Flow & Connectivity](#data-flow--connectivity)
7. [Queue System](#queue-system)

---

## Project Architecture

### Folder Structure

```
backend/
├── modules/
│   └── Project/
│       ├── Processors/           # Template processors (orchestrators)
│       ├── Services/             # Business logic services
│       ├── Jobs/                 # Queue jobs
│       ├── Models/               # Eloquent models
│       ├── Routes/               # API endpoints
│       └── Http/
│           └── Controllers/      # API controllers
├── app/
│   └── Services/                 # Shared services (PythonAIService, etc.)
├── docker/
│   ├── php/
│   │   └── Dockerfile            # PHP 8.3-FPM container
│   ├── python/
│   │   ├── Dockerfile            # Python 3.11 container
│   │   └── app.py                # FastAPI backend (FFmpeg, image processing)
│   └── nginx/                    # Nginx reverse proxy
├── config/                       # Laravel configuration
├── storage/
│   ├── app/public/projects/      # Generated videos, images, segments
│   └── logs/                     # Laravel logs
└── docker-compose.yml            # Multi-container orchestration
```

---

## System Overview

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                       Frontend (Next.js)                         │
│                    (Dashboard / Create Form)                     │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                    HTTP POST /api/projects
                           │
┌──────────────────────────▼──────────────────────────────────────┐
│                   Backend (Laravel) - Port 8085                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ ProjectController (create, show, update)                   │ │
│  │ - Validates template settings                              │ │
│  │ - Creates Project model in DB                              │ │
│  │ - Dispatches ProcessVideoJob                               │ │
│  └────────────────────────────────────────────────────────────┘ │
│                           │                                       │
│                    Dispatch Job                                   │
│                           │                                       │
│  ┌────────────────────────▼────────────────────────────────────┐ │
│  │              ProcessVideoJob (Queue Worker)                 │ │
│  │  - Gets Project from DB                                    │ │
│  │  - Creates TemplateProcessor via Factory                   │ │
│  │  - Calls processor->process()                              │ │
│  │  - Updates Project status via Pusher WebSocket             │ │
│  └────────────────────────────────────────────────────────────┘ │
│                           │                                       │
│                    Multiple Paths                                 │
│    ┌───────────────┬──────────────┬──────────────┐              │
│    │               │              │              │              │
└────┼───────────────┼──────────────┼──────────────┼──────────────┘
     │               │              │              │
     ▼               ▼              ▼              ▼
 ┌─────┐        ┌─────┐        ┌─────┐      ┌────────┐
 │ YTA │        │ AIS │        │ AHS │      │ Other  │
 │ Processor   │ Processor   │ Processor  │Templates│
 └─────┘        └─────┘        └─────┘      └────────┘
     │               │              │              │
     └───────────────┼──────────────┼──────────────┘
                     │
         ┌───────────▼────────────┐
         │  Shared Services       │
         ├───────────────────────┤
         │ • TTSGenerationSvc     │
         │ • R2UploadService      │
         │ • PusherService        │
         │ • WordLevelCaptionSvc  │
         └───────────────┬────────┘
                         │
         ┌───────────────▼────────────┐
         │  PythonAIService (HTTP)    │
         │  POST http://ai:8000/...   │
         │  - apply-vibrate           │
         │  - assemble-scenes         │
         │  - detect-characters       │
         └───────────────┬────────────┘
                         │
         ┌───────────────▼────────────────┐
         │  Python (Docker) - Port 8000   │
         │  FastAPI App                   │
         ├────────────────────────────────┤
         │ • FFmpeg video processing      │
         │ • Character detection (YOLO)   │
         │ • Image effects (zoompan)      │
         │ • Video concatenation          │
         │ • Shared storage (/app/storage)│
         └────────────────────────────────┘
```

### Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Frontend** | Next.js, React, TypeScript | Dashboard UI, project management |
| **Backend** | Laravel 10, PHP 8.3 | API, business logic, queue processing |
| **Video Processing** | Python 3.11, FFmpeg | Advanced video/image manipulation |
| **AI/ML** | OpenAI (GPT-4o-mini), Fal AI (FLUX), YOLO | Script generation, image generation, character detection |
| **Container Orchestration** | Docker Compose | Multi-service deployment |
| **Storage** | Local (/var/www/storage) | Project files, segments, logs |
| **Queue Broker** | Redis | Job queue management |
| **Database** | MySQL 8.0 | Project, user, activity storage |
| **Real-time Updates** | Pusher | WebSocket progress updates |
| **Cloud Storage** | Cloudflare R2 | Final video uploads |
| **Web Server** | Nginx | Reverse proxy, static file serving |

---

## Template Workflows

### YT Automation Short

**Purpose:** Transform a user-uploaded video into an enhanced short-form video with:
- Auto-transcription
- AI script rewriting
- Text-to-speech replacement
- Face detection & zooming effects
- Automatic captions with word-level timings
- Auto-uploads to Cloudflare R2

**Template Configuration:**
```php
'aspect_ratio' => '9:16'        // Portrait
'requires_upload' => true       // User must upload video
'tts_voice' => 'am_michael'     // TTS voice selection
'rewrite_style' => 'professional' // Script rewriting style
```

**Step-by-Step Workflow:**

```
1. VALIDATE INPUT
   ├─ Check video file exists
   ├─ Verify aspect ratio support
   └─ Validate settings (TTS voice, rewrite style)

2. TRANSCRIBE VIDEO
   ├─ Extract audio from video
   ├─ Use Faster-Whisper model (Python)
   ├─ Generate SRT subtitle file
   └─ Extract transcript text

3. REWRITE SCRIPT
   ├─ Send transcript to OpenAI GPT-4o-mini
   ├─ Apply rewrite_style (professional/casual/energetic/etc.)
   ├─ Get improved narration script
   └─ Parse into segments

4. GENERATE TTS AUDIO
   ├─ Use Kokoro TTS model (Python)
   ├─ Generate audio with selected voice (am_michael/am_sarah/bm_george/bm_emma)
   ├─ Create WAV files with word-level timing data
   └─ Extract phoneme timings

5. DETECT FACES
   ├─ Send video frames to Python service
   ├─ Use MediaPipe face detection
   ├─ Get bounding boxes for each face
   ├─ Identify face regions for zooming
   └─ Calculate zoom centers

6. APPLY VIDEO EFFECTS
   ├─ Extract frames from original video
   ├─ Apply face-detection-guided zoom effects (3-sec segments)
   ├─ Scale video to target aspect ratio (9:16 = 1080x1920)
   ├─ Create video segments with zoompan filters
   └─ Save as MP4 files

7. GENERATE CAPTIONS
   ├─ Use word-level timing from TTS
   ├─ Create caption objects with start/end times
   ├─ Apply caption template (modern_karaoke/classic_block/minimal_clean)
   └─ Burn captions into video frames

8. ASSEMBLE VIDEO
   ├─ Concatenate video segments
   ├─ Mix with new TTS audio
   ├─ Overlay captions
   ├─ Ensure aspect ratio maintained
   ├─ Output final MP4 file
   └─ Generate thumbnail

9. UPLOAD TO R2
   ├─ Upload final video to Cloudflare R2
   ├─ Get signed URL
   ├─ Store R2 key & URL in Project model
   └─ Update project status to 'completed'
```

**Services Used:**
- `VideoTranscriptionService` → Uses Faster-Whisper for audio extraction & transcription
- `SRTGenerationService` → Generates SRT subtitles
- `ScriptRewriteService` → Calls OpenAI GPT-4o-mini
- `FaceDetectionService` → Calls Python /detect-faces endpoint
- `VideoEditingService` → Calls Python /video-edit endpoint
- `TTSGenerationService` → Calls Python /tts endpoint (Kokoro)
- `WordLevelCaptionService` → Creates captions with phoneme timings
- `PythonAIService` → HTTP intermediary to Python backend
- `R2UploadService` → Uploads to Cloudflare R2

**Data Models:**
```php
// Project model after completion
{
    'user_id' => 1,
    'template_type' => 'yt_automation_short',
    'title' => 'My Video Edit',
    'video_path' => '/projects/38/original_video.mp4',
    'output_path' => '/projects/38/output.mp4',
    'thumbnail_path' => '/projects/38/thumbnail.jpg',
    'aspect_ratio' => '9:16',
    'r2_url' => 'https://r2.example.com/video.mp4',
    'r2_key' => 'videos/video.mp4',
    'status' => 'completed',
    'progress' => 100,
    'settings' => [
        'tts_voice' => 'am_michael',
        'rewrite_style' => 'professional',
        'caption_template' => 'modern_karaoke',
    ],
    'processing_state' => [
        'transcription_text' => '...',
        'rewritten_script' => '...',
        'face_detections' => [...],
    ]
}
```

---

### AI Image-Based Shorts

**Purpose:** Generate complete short-form videos from scratch using:
- AI script generation (duration-based)
- Fal AI image generation (8-17 images)
- Character detection & vibrate effects
- Scene assembly with audio & captions
- Auto-uploads to R2

**Template Configuration:**
```php
'aspect_ratio' => '9:16'          // Portrait
'requires_upload' => false        // No user upload needed
'duration_seconds' => 15          // Duration (15/20/25/30/35)
'visual_style' => 'cartoon'       // Style selection
'tts_voice' => 'am_michael'       // TTS voice
'caption_template' => 'modern_karaoke'
```

**Duration to Image Count Mapping:**
```
15 seconds  →  8 images
20 seconds  →  10 images
25 seconds  →  13 images
30 seconds  →  15 images
35 seconds  →  17 images
```

**Step-by-Step Workflow:**

```
1. VALIDATE INPUT
   ├─ Check duration is valid (15/20/25/30/35)
   ├─ Validate visual_style
   └─ Verify TTS voice support

2. GENERATE SCRIPT
   ├─ Calculate word count based on duration
   │  (15s→45 words, 20s→65 words, 25s→85 words, etc.)
   ├─ Build AI prompt emphasizing:
   │  • Visual coherence
   │  • Logical progression
   │  • Consistent characters/settings
   ├─ Call OpenAI GPT-4o-mini with scene_count=8-12
   ├─ Response format: JSON with {"scenes": [{"image": "...", "narration": "..."}]}
   └─ Get 8-12 scene descriptions & narrations

3. PARSE SCRIPT
   ├─ Attempt JSON parsing first (new format)
   ├─ Fallback to regex parsing (legacy)
   └─ Extract image descriptions & narrations

4. GENERATE IMAGES
   ├─ Calculate sceneCount = imageCount from duration
   │  (Select 8-17 scenes from 12 total to fit duration)
   ├─ For each scene:
   │  ├─ Add consistency prefix to prompt (e.g., "Same scene, same character...")
   │  ├─ Append visual_style (e.g., "cartoon style")
   │  ├─ Call Fal AI FLUX Dev API with:
   │  │  • image_size: portrait_16_9 (9:16 aspect ratio)
   │  │  • model_name: flux-pro
   │  │  • num_inference_steps: 50
   │  └─ Receive image URL and save locally
   └─ Save all images to /projects/{id}/images/

5. DETECT CHARACTERS
   ├─ For each image, send to Python /detect-characters endpoint
   ├─ Use YOLO & MediaPipe models
   ├─ Get character bounding boxes
   ├─ Identify main character regions
   └─ Store character metadata

6. APPLY VIBRATE EFFECTS
   ├─ For each image (segment_index 0-17):
   │  ├─ Pass to Python /apply-vibrate endpoint with:
   │  │  • image path
   │  │  • character data (bounding boxes)
   │  │  • segment_index (for unique naming)
   │  │  • aspect_ratio (9:16)
   │  ├─ Python uses FFmpeg zoompan filter:
   │  │  • Scale to 1080x1920 (9:16)
   │  │  • Apply 3-sec zoom effect
   │  │  • Create MP4: scene_000.mp4, scene_001.mp4, ...
   │  └─ Receive segment path
   └─ Get all video segments in order

7. GENERATE TTS AUDIO
   ├─ Combine all narrations into single script
   ├─ Send to Python /tts endpoint (Kokoro model)
   ├─ Get audio WAV with word-level timings
   ├─ Calculate word durations & start times
   └─ Sync narration with video segments

8. ASSEMBLE SCENES
   ├─ Create FFmpeg concat demux file:
   │  file 'scene_000.mp4'
   │  file 'scene_001.mp4'
   │  ...
   ├─ Send to Python /assemble-scenes endpoint with:
   │  • scene list
   │  • settings (aspect_ratio: 9:16)
   │  • audio path
   ├─ Python uses FFmpeg:
   │  • Scale all segments to 1080x1920 (9:16)
   │  • Concatenate segments
   │  • Mix with audio
   │  • Create final video
   └─ Receive final video path

9. GENERATE CAPTIONS
   ├─ Use TTS word-level timing data
   ├─ Create caption objects with synchronized text
   ├─ Apply caption_template
   └─ Burn captions into video

10. UPLOAD TO R2
    ├─ Upload final video to Cloudflare R2
    ├─ Get signed URL
    ├─ Store R2 key & URL
    └─ Update project status to 'completed'
```

**Services Used:**
- `ScriptGenerationService` → OpenAI GPT-4o-mini with visual coherence emphasis
- `ImageGenerationService` → Fal AI FLUX Dev API
- `CharacterDetectionService` → Python YOLO/MediaPipe
- `CharacterVibrateService` → Python FFmpeg zoompan
- `SceneAssemblyService` → Python FFmpeg concatenation
- `TTSGenerationService` → Python Kokoro TTS
- `WordLevelCaptionService` → Caption generation with word-level sync
- `PythonAIService` → HTTP intermediary
- `R2UploadService` → Cloudflare R2 upload

**Data Models:**
```php
{
    'user_id' => 1,
    'template_type' => 'ai_image_based_shorts',
    'title' => 'Auto-Generated Story',
    'output_path' => '/projects/38/output.mp4',
    'aspect_ratio' => '9:16',
    'r2_url' => 'https://r2.example.com/video.mp4',
    'status' => 'completed',
    'settings' => [
        'duration_seconds' => 20,
        'visual_style' => 'cartoon',
        'tts_voice' => 'am_michael',
        'caption_template' => 'modern_karaoke',
    ],
    'processing_state' => [
        'script' => [...],
        'image_urls' => [...],
        'character_detections' => [...],
    ]
}
```

---

### AI Horror Shorts

**Purpose:** Generate horror-themed short videos with:
- Horror-specific AI script generation
- Cartoon/horror style images (Fal AI)
- Enhanced character animation effects
- Scene assembly with audio & captions
- Auto-uploads to R2

**Template Configuration:**
```php
'aspect_ratio' => '9:16'           // Portrait
'requires_upload' => false         // No user upload needed
'duration_seconds' => 15           // Duration (15/20/25/30/35)
'visual_style' => 'cartoon_horror' // Horror style
'tts_voice' => 'bm_george'         // Spooky voice
'caption_template' => 'modern_karaoke'
```

**Key Differences from AI Image-Based Shorts:**

1. **Enhanced Horror Prompts:**
   - System prompt emphasizes dread, tension, suspense
   - Examples of good horror progression
   - Consistency requirements for scary elements
   - Temperature: 0.5 (lower for more consistent horror themes)

2. **Visual Coherence Requirements:**
   - Logical progression within story
   - Consistent character/setting appearance
   - Building tension through scene transitions
   - Terrifying but coherent image sequences

3. **Story Structure:**
   - 8-12 related horror scenes
   - Each scene has image description + narration
   - Narrations scale with duration for proper pacing
   - Final scenes build to climax

**Step-by-Step Workflow:**

```
[Same as AI Image-Based Shorts, but with horror customizations]

2. GENERATE HORROR SCRIPT (CUSTOMIZED)
   ├─ Build system prompt with horror elements:
   │  • Emphasize VISUAL COHERENCE
   │  • Require consistent characters/settings
   │  • Include good vs bad progression examples
   │  • Set tone to 'horror' explicitly
   ├─ Lower temperature to 0.5 (vs default 0.7)
   ├─ Call OpenAI with horror-specific prompt
   └─ Get coherent horror scene sequence

4. GENERATE HORROR IMAGES (CUSTOMIZED)
   ├─ Add consistency prefix to each image:
   │  "Same scene, same character, continuing the story..."
   ├─ Use visual_style = 'cartoon_horror'
   │  (horror-specific art styles: gothic, cinematic, photo_realistic)
   ├─ Call Fal AI with enhanced prompts
   ├─ Ensure images form coherent visual narrative
   └─ Result: 8-17 cohesive horror images

6. APPLY VIBRATE EFFECTS (CUSTOMIZED)
   ├─ Use enhanced zoom strength for horror impact
   ├─ FFmpeg filters optimized for tension building
   ├─ Each image: 3-second segment with zoom animation
   └─ Create intense visual effects

[Rest is identical to AI Image-Based Shorts]
```

**Services Used:**
- `ScriptGenerationService` → OpenAI with HORROR-SPECIFIC prompts
- `ImageGenerationService` → Fal AI with horror styles
- `CharacterDetectionService` → Python YOLO/MediaPipe
- `CharacterVibrateService` → Enhanced vibrate effects for horror
- `SceneAssemblyService` → Python FFmpeg concatenation
- `TTSGenerationService` → Python Kokoro (spooky voice selection)
- `WordLevelCaptionService` → Caption generation
- `PythonAIService` → HTTP intermediary
- `R2UploadService` → Cloudflare R2 upload

**Data Models:**
```php
{
    'user_id' => 1,
    'template_type' => 'ai_horror_shorts',
    'title' => 'Haunted House Story',
    'output_path' => '/projects/38/output.mp4',
    'aspect_ratio' => '9:16',
    'r2_url' => 'https://r2.example.com/horror_video.mp4',
    'status' => 'completed',
    'settings' => [
        'duration_seconds' => 15,
        'visual_style' => 'cartoon_horror',
        'tone' => 'horror',
        'tts_voice' => 'bm_george',
        'caption_template' => 'modern_karaoke',
    ],
    'processing_state' => [
        'horror_script' => [...],  // Coherent horror narrative
        'image_urls' => [...],     // Horror images
        'character_detections' => [...],
    ]
}
```

---

## Services & Functions

### Core Service Layer

#### 1. PythonAIService (Backend → Python Bridge)

**Location:** `backend/app/Services/PythonAIService.php`

**Purpose:** HTTP intermediary between Laravel and Python backend

**Key Methods:**

```php
// Image Generation (Fal AI)
public function generateImages(
    array $prompts,
    string $aspectRatio = '9:16',
    string $style = 'cartoon'
): array

// Character Detection
public function detectCharacters(array $imagePaths): array

// Apply Vibrate Effects
public function applyVibrateEffect(
    array $imagePaths,
    array $characterData,
    array $options = []
): array
// options = [
//     'segment_index' => int,      // For unique filenames
//     'vibrate_strength' => float,
//     'aspect_ratio' => '9:16',    // Dynamic!
//     'project_id' => int
// ]

// Assemble Scenes
public function assembleScenes(
    array $scenes,
    string $audioPath,
    array $settings = []
): string
// settings = ['aspect_ratio' => '9:16']
```

**Image Size Mapping (Fal AI Official Parameters):**
```php
private $imageSizeMap = [
    '9:16' => 'portrait_16_9',   // Portrait
    '16:9' => 'landscape_16_9',  // Landscape
    '1:1' => 'square',           // Square
    '4:3' => 'landscape_4_3',    // Landscape 4:3
    '3:4' => 'portrait_4_3',     // Portrait 3:4
];
```

#### 2. ScriptGenerationService

**Location:** `backend/modules/Project/Services/ScriptGenerationService.php`

**Purpose:** Generate AI scripts using OpenAI GPT-4o-mini

**Key Methods:**

```php
public function generate(
    string $topic,
    string $style,
    string $tone,
    int $durationSeconds
): string

// Calculates word count based on duration
private function calculateWordCount(int $durationSeconds): int
// 15s → 45 words, 20s → 65 words, etc.

// Builds system prompt with quality/coherence requirements
private function buildSystemPrompt(
    string $style,
    string $tone
): string

// Builds user prompt with explicit requirements
private function buildPrompt(
    string $topic,
    int $wordCount,
    string $style
): string
```

**Output Format:** Strict JSON
```json
{
  "scenes": [
    {
      "image": "A dimly lit room with flickering lights",
      "narration": "Every time the lights flicker, something moves to a different position."
    }
  ]
}
```

**Parameters:**
- `temperature: 0.5` (Lower for consistency)
- `presence_penalty: 0.0` (No penalty)
- `max_tokens: 2000`

#### 3. ImageGenerationService

**Location:** `backend/modules/Project/Services/ImageGenerationService.php`

**Purpose:** Generate images using Fal AI FLUX Dev API

**Key Methods:**

```php
public function generateConcurrent(
    array $prompts,
    string $imageSize = 'portrait_16_9',
    string $style = 'cartoon'
): array

// Polls queue status for async generation
private function pollQueue(
    string $requestId,
    string $statusUrl
): array
```

**Fal AI Parameters:**
```php
[
    'prompt' => 'Dimly lit room, flickering lights, cartoon_horror style...',
    'model_name' => 'flux-pro',
    'image_size' => 'portrait_16_9',  // Fal AI preset
    'num_inference_steps' => 50,
    'num_images' => 1,
]
```

#### 4. CharacterDetectionService

**Location:** `backend/modules/Project/Services/CharacterDetectionService.php`

**Purpose:** Detect characters in images using YOLO & MediaPipe

**Key Methods:**

```php
public function detectInImages(array $imagePaths): array

// Returns:
[
    'image_path' => [
        'characters' => [
            [
                'x' => 100,
                'y' => 150,
                'width' => 200,
                'height' => 300,
                'confidence' => 0.95
            ]
        ]
    ]
]
```

#### 5. CharacterVibrateService

**Location:** `backend/modules/Project/Services/CharacterVibrateService.php`

**Purpose:** Apply vibrate/zoom effects to images using FFmpeg

**Key Methods:**

```php
public function applyVibrateEffect(
    array $imagePaths,
    array $characterData,
    array $options = []
): array

// Returns: ['/projects/38/segments/scene_000.mp4', ...]
```

**Python Endpoint Call:**
```
POST http://ai:8000/apply-vibrate
{
    "image_paths": [...],
    "character_data": [...],
    "options": {
        "project_id": 38,
        "segment_index": 0,
        "vibrate_strength": 0.08,
        "aspect_ratio": "9:16"
    }
}
```

#### 6. SceneAssemblyService

**Location:** `backend/modules/Project/Services/SceneAssemblyService.php`

**Purpose:** Concatenate video segments with audio

**Key Methods:**

```php
public function assemble(
    array $segments,
    string $audioPath,
    array $settings = []
): string

// Returns: '/projects/38/output.silent.mp4'
```

**Python Endpoint Call:**
```
POST http://ai:8000/assemble-scenes
{
    "scenes": [
        {"segment_path": "...", "order": 0},
        ...
    ],
    "audio_path": "...",
    "settings": {
        "project_id": 38,
        "aspect_ratio": "9:16"
    }
}
```

#### 7. TTSGenerationService

**Location:** `backend/modules/Project/Services/TTSGenerationService.php`

**Purpose:** Generate text-to-speech using Kokoro model (Python)

**Key Methods:**

```php
public function generate(
    string $text,
    string $voice = 'am_michael',
    array $settings = []
): array

// Returns:
[
    'audio_path' => '/projects/38/audio.wav',
    'duration' => 8.5,
    'word_timings' => [
        ['word' => 'Hello', 'start' => 0.0, 'end' => 0.5],
        ...
    ]
]
```

**Voice Options:**
- `am_michael` - Male American
- `am_sarah` - Female American
- `bm_george` - Male British (spooky)
- `bm_emma` - Female British

#### 8. WordLevelCaptionService

**Location:** `backend/modules/Project/Services/WordLevelCaptionService.php`

**Purpose:** Generate synchronized captions

**Key Methods:**

```php
public function generate(
    string $text,
    array $wordTimings,
    string $template = 'modern_karaoke',
    array $settings = []
): array

// Returns:
[
    'captions' => [
        [
            'text' => 'Hello world',
            'start_time' => 0.0,
            'end_time' => 1.0,
            'template' => 'modern_karaoke'
        ]
    ]
]
```

#### 9. R2UploadService

**Location:** `backend/modules/Project/Services/R2UploadService.php`

**Purpose:** Upload files to Cloudflare R2

**Key Methods:**

```php
public function upload(
    string $filePath,
    string $destinationKey,
    string $mimeType = 'video/mp4'
): array

// Returns:
[
    'success' => true,
    'url' => 'https://r2.example.com/videos/file.mp4',
    'key' => 'videos/file.mp4'
]
```

#### 10. PusherService

**Location:** `backend/app/Services/PusherService.php`

**Purpose:** Send real-time progress updates via WebSocket

**Key Methods:**

```php
public function sendStatus(
    int $projectId,
    string $status,
    string $message
): void

public function sendStepProgress(
    int $projectId,
    string $step,
    int $stepNumber,
    int $totalSteps,
    float $overallProgress
): void

public function sendProgress(
    int $projectId,
    int $progress
): void
```

**Data Flow:**
```
Backend (Laravel)
  └─ $pusherService->sendProgress(38, 50)
       └─ Pusher WebSocket API
            └─ Frontend (Next.js)
                 └─ Real-time progress update on UI
```

---

## Docker Setup

### docker-compose.yml Configuration

```yaml
version: "3.9"

services:
  # PHP Backend Container
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/var/www
      - shared_storage:/var/www/storage
    environment:
      - PYTHON_AI_SERVICE_URL=http://ai:8000
    dns:
      - 8.8.8.8      # Google DNS (for external API calls)
      - 1.1.1.1      # Cloudflare DNS
    networks:
      - app_net

  # Queue Worker Container (Same as app, different command)
  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: sh -c "sleep 20 && php artisan migrate --force && php artisan queue:work --queue=video-processing --sleep=1 --tries=3"
    volumes:
      - .:/var/www
      - shared_storage:/var/www/storage
    environment:
      - PYTHON_AI_SERVICE_URL=http://ai:8000
    dns:
      - 8.8.8.8
      - 1.1.1.1
    networks:
      - app_net

  # Python Backend Container (FastAPI)
  ai:
    build:
      context: .
      dockerfile: docker/python/Dockerfile
    volumes:
      - .:/app
      - shared_storage:/app/storage
    dns:
      - 8.8.8.8      # Required for Fal AI API calls
      - 1.1.1.1
    networks:
      - app_net

  # Nginx Reverse Proxy
  nginx:
    image: nginx:alpine
    ports:
      - "8086:80"
    volumes:
      - .:/var/www
      - shared_storage:/var/www/storage
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app
    networks:
      - app_net

  # MySQL Database
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: backend
      MYSQL_USER: backend
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    command: --default-authentication-plugin=mysql_native_password
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - app_net

  # Redis Queue Broker
  redis:
    image: redis:alpine
    networks:
      - app_net

volumes:
  mysql_data:
  shared_storage:    # Shared between PHP and Python containers
```

### PHP Dockerfile

```dockerfile
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip ffmpeg nodejs npm wget \
    build-essential \
    libzip-dev libpng-dev libonig-dev libxml2-dev \
    libsndfile1 libgl1 \
    tesseract-ocr poppler-utils \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install \
        pdo_mysql mbstring bcmath gd zip

# PHP Configuration
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www

# Install Composer dependencies
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www

RUN composer install --no-dev --optimize-autoloader

CMD ["php-fpm"]
```

**Key Tools & Libraries:**
- `ffmpeg` - Video processing
- `tesseract-ocr` - Optical character recognition
- `redis` - Queue driver
- `pdo_mysql` - Database driver
- `gd` - Image manipulation

### Python Dockerfile

```dockerfile
FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    ffmpeg \
    libsndfile1 \
    libgl1

RUN pip install --upgrade pip

RUN pip install --no-cache-dir \
    pytesseract pdf2image pillow \
    "numpy<2.0" \
    requests boto3 openai \
    soundfile ffmpeg-python opencv-python-headless \
    moviepy==1.0.3 imageio imageio-ffmpeg \
    faster-whisper ultralytics mediapipe \
    kokoro \
    fastapi uvicorn python-multipart

COPY . /app

CMD ["uvicorn", "docker.python.app:app", "--host", "0.0.0.0", "--port", "8000"]
```

**Key Libraries:**
- `fastapi` / `uvicorn` - Web framework
- `ffmpeg-python` - FFmpeg wrapper
- `opencv-python` - Image processing
- `ultralytics` - YOLO object detection
- `mediapipe` - Face detection
- `faster-whisper` - Audio transcription
- `kokoro` - Text-to-speech
- `pillow` - Image manipulation

### Python app.py - FastAPI Endpoints

**Location:** `backend/docker/python/app.py`

**Key Endpoints:**

```python
@app.post("/apply-vibrate")
async def apply_vibrate(request: ApplyVibrateRequest):
    """
    Apply zoompan effects to images, create 3-sec MP4 segments
    
    POST /apply-vibrate
    {
        "image_paths": ["path/to/image.jpg"],
        "character_data": [...],
        "options": {
            "project_id": 38,
            "segment_index": 0,
            "aspect_ratio": "9:16",
            "vibrate_strength": 0.08
        }
    }
    
    Returns: {"success": true, "animated_segments": ["path/to/scene_000.mp4"]}
    """

@app.post("/assemble-scenes")
async def assemble_scenes(request: AssembleScenesRequest):
    """
    Concatenate video segments with audio, add captions
    
    POST /assemble-scenes
    {
        "scenes": [{"segment_path": "...", "order": 0}],
        "audio_path": "...",
        "settings": {
            "aspect_ratio": "9:16",
            "project_id": 38
        }
    }
    
    Returns: {"success": true, "output_path": "path/to/output.mp4"}
    """

@app.post("/detect-characters")
async def detect_characters(request: DetectCharactersRequest):
    """
    Detect characters in images using YOLO & MediaPipe
    """

@app.post("/tts")
async def generate_tts(request: TTSRequest):
    """
    Generate text-to-speech using Kokoro model
    """

@app.post("/detect-faces")
async def detect_faces(request: DetectFacesRequest):
    """
    Detect faces in video using MediaPipe
    """

@app.post("/video-edit")
async def video_edit(request: VideoEditRequest):
    """
    Apply effects to video (zoom, pan, etc.)
    """
```

**Storage Path Translation:**

```
PHP Container:     /var/www/storage/app/public/projects/38/
                        ↓ (mounted as)
Python Container:  /app/storage/app/public/projects/38/

Both reference same physical directory via Docker volume
```

---

## Data Flow & Connectivity

### Request Flow Diagram

```
Frontend User Action
    ↓
"Create Project" Form Submission
    ↓
POST /api/projects (ProjectController@store)
    ├─ Validate settings
    ├─ Create Project model
    └─ Dispatch ProcessVideoJob
    ↓
ProcessVideoJob queued in Redis
    ↓
Queue Worker picks up job
    ├─ Load Project from DB
    ├─ Create Processor via TemplateProcessorFactory
    └─ Call $processor->process()
    ↓
Processor Logic (AIVideoProcessor / AIImageShortsProcessor / AIHorrorShortsProcessor)
    ├─ Service 1 (e.g., ScriptGenerationService)
    │  └─ OpenAI API call
    ├─ Service 2 (e.g., ImageGenerationService)
    │  └─ Fal AI API call
    ├─ Service 3 (e.g., PythonAIService)
    │  └─ HTTP POST to http://ai:8000/endpoint
    │     ├─ Python service processes
    │     └─ Returns result
    └─ ... more services
    ↓
Update Project status via Pusher
    └─ Real-time WebSocket update to Frontend
    ↓
Upload to R2 (final video)
    ↓
Project marked as 'completed'
    ↓
Frontend receives completion via Pusher
    └─ Display video & download link
```

### Service Communication Map

```
┌─────────────────────────────────┐
│   Laravel Backend (Port 8085)   │
├─────────────────────────────────┤
│ ProjectController               │
│  └─ ProcessVideoJob             │
│      └─ TemplateProcessorFactory│
│          └─ Processor           │
│             ├─ ScriptGenService │
│             │  └─ OpenAI API    │
│             ├─ ImageGenService  │
│             │  └─ Fal AI API    │
│             ├─ PythonAIService  │
│             │  └─ HTTP POST     │
│             │     (↓↓↓)         │
│             ├─ TTSService       │
│             ├─ CaptionService   │
│             └─ R2UploadService  │
└────────────┬────────────────────┘
             │ HTTP POST
             │ (to http://ai:8000)
┌────────────▼────────────────────┐
│  Python Backend (Port 8000)     │
├─────────────────────────────────┤
│ FastAPI app.py                  │
│  ├─ /apply-vibrate              │
│  │  ├─ Load image               │
│  │  ├─ FFmpeg zoompan filter    │
│  │  └─ Save segment MP4         │
│  ├─ /assemble-scenes            │
│  │  ├─ FFmpeg concat            │
│  │  ├─ Mix audio                │
│  │  └─ Final video              │
│  ├─ /detect-characters          │
│  │  ├─ YOLO detection           │
│  │  ├─ MediaPipe faces          │
│  │  └─ Return bounding boxes    │
│  ├─ /tts                        │
│  │  ├─ Kokoro model             │
│  │  └─ Generate audio           │
│  └─ /video-edit                 │
│     └─ Apply effects            │
└─────────────────────────────────┘
         ↓
   Shared Storage
  /var/www/storage/
  /app/storage
   (Same volume!)
```

### Storage Architecture

```
Shared Volume: shared_storage (Docker)

/storage/
├── app/
│   └── public/
│       └── projects/
│           ├── 38/
│           │   ├── original_video.mp4       (uploaded by user)
│           │   ├── images/
│           │   │   ├── image_001.png        (Fal AI generated)
│           │   │   ├── image_002.png
│           │   │   └── ...
│           │   ├── segments/
│           │   │   ├── scene_000.mp4        (FFmpeg vibrate)
│           │   │   ├── scene_001.mp4
│           │   │   └── ...
│           │   ├── audio.wav                (TTS generated)
│           │   ├── captions.vtt             (Caption file)
│           │   ├── output.mp4               (Final video)
│           │   └── thumbnail.jpg            (Thumbnail)
│           └── 39/
│               └── ...
└── logs/
    └── laravel.log                          (Processing logs)
```

---

## Queue System

### Redis Queue Architecture

```
┌─────────────────┐
│  Laravel App    │
│  (Port 8085)    │
└────────┬────────┘
         │
         │ dispatch()
         ▼
┌─────────────────────────┐
│   Redis Queue Broker    │
│  (Port 6379, Docker)    │
├─────────────────────────┤
│ Queue: video-processing │
│  │                      │
│  ├─ ProcessVideoJob {   │
│  │    project_id: 38    │
│  │  }                   │
│  │                      │
│  └─ ProcessVideoJob {   │
│     project_id: 39      │
│  }                      │
└────────────┬────────────┘
             │
             │ queue:work
             ▼
┌──────────────────────────┐
│  Queue Worker Container  │
│  (Separate PHP-FPM)      │
├──────────────────────────┤
│ Running:                 │
│ php artisan queue:work   │
│   --queue=video-processing
│   --sleep=1              │
│   --tries=3              │
│                          │
│ Processes jobs one by one│
│ (Sequential, reliable)   │
└──────────────────────────┘
```

### Job Lifecycle

```
1. USER CREATES PROJECT
   ├─ Frontend: POST /api/projects
   ├─ Backend: ProjectController@store()
   ├─ Create Project model in DB
   └─ Dispatch ProcessVideoJob
      └─ Add to Redis queue

2. WORKER PICKS UP JOB
   ├─ Queue monitor: "video-processing" queue not empty
   ├─ Worker: Pop job from queue
   └─ Execute: ProcessVideoJob->handle()

3. PROCESSING STARTS
   ├─ Load Project from DB
   ├─ Get template_type → AIHorrorShortsProcessor
   ├─ Create processor instance
   └─ Call processor->process()
      ├─ Loop through processing steps
      ├─ Send Pusher updates (real-time)
      └─ Update DB status

4. SUCCESS OR FAILURE
   ├─ If successful:
   │  ├─ Upload to R2
   │  ├─ Mark project as 'completed'
   │  └─ Final Pusher notification
   └─ If failed:
      ├─ Store error_message
      ├─ Store failed_step
      └─ Retry (up to 3 times)

5. JOB COMPLETE
   ├─ Remove from queue
   ├─ Frontend notified via Pusher
   └─ User can download video
```

### Command Reference

```bash
# Start queue worker (inside container)
docker exec backend-worker-1 php artisan queue:work --queue=video-processing --sleep=1 --tries=3

# Monitor queue
docker exec backend-worker-1 php artisan queue:monitor video-processing

# View logs (tail last 100 lines)
tail -100 /var/www/storage/logs/laravel.log

# Clear logs
truncate -s 0 /var/www/storage/logs/laravel.log

# Retry failed jobs
docker exec backend-worker-1 php artisan queue:retry all
```

---

## Troubleshooting

### Common Issues & Solutions

**Issue: "Could not resolve host: queue.fal.run"**
- **Cause:** Docker DNS not configured
- **Solution:** Add `dns` section to docker-compose.yml:
  ```yaml
  dns:
    - 8.8.8.8
    - 1.1.1.1
  ```
- **Fix:** `docker compose down && docker compose up -d --build`

**Issue: "Only last image appears in final video"**
- **Cause:** Hardcoded output filename for all segments (scene_000.mp4)
- **Solution:** Pass `segment_index` through entire chain
  - PHP → Python: `options['segment_index'] = $index`
  - Python: Use `scene_{index:03d}.mp4` in filename

**Issue: "Images stretched to 16:9 instead of 9:16"**
- **Cause:** FFmpeg not preserving aspect ratio
- **Solution:** Add scale filter with aspect ratio preservation:
  ```python
  scale_filter = f"scale={width}:{height}:force_original_aspect_ratio=decrease,pad={width}:{height}:(ow-iw)/2:(oh-ih)/2"
  ```

**Issue: "Script parser extracting 1 scene instead of 8-12"**
- **Cause:** GPT output format inconsistent (text instead of JSON)
- **Solution:** Force JSON output in prompts + lower temperature (0.5)
  - Parse JSON first, fallback to regex

**Issue: "Image sequence incoherent (unrelated images)"**
- **Cause:** No coherence requirements in image generation
- **Solution:** Add to prompts:
  - "Same character, same setting, continuing the story..."
  - System prompt emphasizing visual coherence
  - Set temperature to 0.5 for consistency

**Issue: "Aspect ratio hardcoded to 1080x1920"**
- **Cause:** FFmpeg filters hardcoded
- **Solution:** Make dynamic with helper function:
  ```python
  def calculate_dimensions_for_aspect_ratio(aspect_ratio: str) -> tuple:
      aspect_map = {
          '9:16': (1080, 1920),
          '16:9': (1920, 1080),
          '1:1': (1080, 1080),
      }
      return aspect_map.get(aspect_ratio, (1080, 1920))
  ```

---

## Summary

### Three Template Workflows

| Template | Input | AI Model | Output | Duration |
|----------|-------|----------|--------|----------|
| **YT Automation Short** | User video | Whisper (transcription), GPT-4o (rewrite), Kokoro (TTS) | Enhanced video with new narration, captions, face zoom effects | ~5-10 min per video |
| **AI Image-Based Shorts** | Text prompt | GPT-4o (script), Fal AI FLUX (images), YOLO/MediaPipe (detection), Kokoro (TTS) | Generated video from AI script & images with effects | ~3-8 min per video |
| **AI Horror Shorts** | Horror topic | GPT-4o (horror script), Fal AI FLUX (horror images), YOLO/MediaPipe (detection), Kokoro (TTS) | Horror-themed generated video with coherent scene progression | ~3-8 min per video |

### Core Technologies

- **Backend:** Laravel 10 (PHP 8.3)
- **Python Microservice:** FastAPI (Python 3.11)
- **Video Processing:** FFmpeg
- **AI/ML:** OpenAI GPT-4o-mini, Fal AI FLUX Dev, YOLO v8, MediaPipe
- **Audio:** Kokoro TTS, Faster-Whisper
- **Containers:** Docker Compose (PHP, Python, MySQL, Redis, Nginx)
- **Real-time:** Pusher WebSocket
- **Storage:** Cloudflare R2 (cloud), Local /storage (processing)

### Data Flow Summary

```
User Input → Project Created → Queue Job → Processor Factory → 
Specific Processor → Multiple Services → Python Backend → 
FFmpeg Processing → R2 Upload → Project Completed → 
User Notified (Pusher) → Download Video
```

---

## Additional Resources

- **Fal AI API Docs:** https://fal.ai/models/fal-ai/flux/dev/api
- **OpenAI API Docs:** https://platform.openai.com/docs/guides/gpt-4-turbo
- **FFmpeg Docs:** https://ffmpeg.org/ffmpeg-all.html
- **Laravel Queue Docs:** https://laravel.com/docs/11.x/queues
- **Docker Compose Docs:** https://docs.docker.com/compose/
- **Pusher Docs:** https://pusher.com/docs

