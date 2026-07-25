# YT Automation Short Template - API Testing Guide

## 🚀 Base URL
```
http://localhost:8086/api
```

## 📋 Authentication
If your Laravel app uses authentication, include:
```
Authorization: Bearer {token}
```

## 🎯 Complete Workflow Testing

### 1. Create Project with AI Template
**POST** `/projects`

**Required:**
```json
{
  "name": "Test AI Video Project",
  "template_type": "yt_automation_short"
}
```

**Optional:**
```json
{
  "description": "Testing AI video processing",
  "settings": {
    "rewrite_style": "professional",
    "target_length": "similar", 
    "tone": "neutral",
    "purpose": "general",
    "tts_voice": "am_michael",
    "crop_to_faces": true,
    "smooth_transitions": true,
    "focus_main_speaker": true
  }
}
```

**Response:**
```json
{
  "success": true,
  "project": {
    "id": 1,
    "name": "Test AI Video Project",
    "template_type": "yt_automation_short",
    "status": "created",
    "settings": {...}
  }
}
```

---

### 2. Upload Video File
**POST** `/projects/{project_id}/upload-video`

**Required:**
- `video` (file) - Video file (mp4, mov, avi, mkv, webm)
- Max size: 500MB

**Optional:**
- `description` - Video description

**Response:**
```json
{
  "success": true,
  "video": {
    "id": 1,
    "filename": "video_12345.mp4",
    "path": "uploads/videos/video_12345.mp4",
    "size": 25000000,
    "duration": 120.5
  }
}
```

---

### 3. Start AI Video Processing
**POST** `/projects/{project_id}/process`

**Required:**
```json
{
  "video_id": 1
}
```

**Optional:**
```json
{
  "settings": {
    "rewrite_style": "energetic",
    "tts_voice": "am_sarah",
    "crop_to_faces": false
  }
}
```

**Response:**
```json
{
  "success": true,
  "job": {
    "id": "abc123-def456",
    "status": "queued",
    "estimated_time": 300,
    "steps": [
      {"step": "transcribe", "progress": 0},
      {"step": "write_srt", "progress": 0},
      {"step": "rewrite_script", "progress": 0},
      {"step": "generate_tts", "progress": 0},
      {"step": "detect_faces", "progress": 0},
      {"step": "edit_visuals", "progress": 0},
      {"step": "assemble_video", "progress": 0},
      {"step": "upload_r2", "progress": 0}
    ]
  }
}
```

---

### 4. Check Processing Status
**GET** `/projects/{project_id}/status`

**Response:**
```json
{
  "success": true,
  "status": "processing",
  "progress": 45,
  "current_step": "detect_faces",
  "steps": [
    {"step": "transcribe", "progress": 100},
    {"step": "write_srt", "progress": 100},
    {"step": "rewrite_script", "progress": 100},
    {"step": "generate_tts", "progress": 100},
    {"step": "detect_faces", "progress": 45},
    {"step": "edit_visuals", "progress": 0},
    {"step": "assemble_video", "progress": 0},
    {"step": "upload_r2", "progress": 0}
  ],
  "logs": [
    "Transcription completed successfully",
    "SRT subtitles generated",
    "Script rewritten with professional tone",
    "TTS audio generated with am_michael voice"
  ]
}
```

---

### 5. Get Final Result
**GET** `/projects/{project_id}/result`

**Response (when complete):**
```json
{
  "success": true,
  "status": "completed",
  "result": {
    "final_video": {
      "url": "https://ae9578173e462b254f638a182abf7af2.r2.cloudflarestorage.com/videos/final_video_12345.mp4",
      "path": "videos/final_video_12345.mp4",
      "duration": 118.2,
      "size": 45000000
    },
    "transcription": {
      "text": "This is the transcribed text from the video...",
      "segments": [
        {"start": 0.0, "end": 3.5, "text": "Hello everyone"},
        {"start": 3.5, "end": 7.2, "text": "welcome to our tutorial"}
      ]
    },
    "srt_file": {
      "url": "https://ae9578173e462b254f638a182abf7af2.r2.cloudflarestorage.com/subtitles/video_12345.srt",
      "path": "subtitles/video_12345.srt"
    },
    "rewritten_script": "This is the professionally rewritten script...",
    "tts_audio": {
      "url": "https://ae9578173e462b254f638a182abf7af2.r2.cloudflarestorage.com/audio/tts_12345.wav",
      "path": "audio/tts_12345.wav"
    },
    "face_detection": {
      "faces_detected": 3,
      "main_speaker": "face_1",
      "tracking_data": {...}
    },
    "processing_time": 285,
    "ai_settings_used": {
      "rewrite_style": "professional",
      "tts_voice": "am_michael",
      "crop_to_faces": true
    }
  }
}
```

---

## 🔧 Individual Service Testing

### Python AI Service Direct Testing
**Base URL:** `http://localhost:8000`

#### Health Check
**GET** `/health`

```json
{
  "status": "healthy",
  "services": {
    "transcription": true,
    "tts": true,
    "face_detection": true,
    "video_editing": true
  }
}
```

#### Transcription
**POST** `/transcribe`

**Required:**
```json
{
  "video_path": "uploads/videos/your_video.mp4",
  "language": "en"
}
```

#### TTS Generation
**POST** `/tts`

**Required:**
```json
{
  "text": "Hello, this is a test of the TTS system",
  "voice": "am_michael",
  "output_path": "uploads/audio/test_tts.wav",
  "word_timing": false
}
```

#### Face Detection
**POST** `/detect-faces`

**Required:**
```json
{
  "video_path": "uploads/videos/your_video.mp4",
  "detect_objects": true
}
```

#### Video Editing
**POST** `/edit-video`

**Required:**
```json
{
  "video_path": "uploads/videos/your_video.mp4",
  "output_path": "uploads/videos/edited_video.mp4",
  "edit_type": "face_crop",
  "settings": {
    "padding": 0.1,
    "smooth_transitions": true
  }
}
```

---

## 📝 Template Settings Reference

### AI Template Settings
| Setting | Type | Required | Default | Options |
|---------|------|----------|---------|---------|
| `rewrite_style` | string | No | professional | professional, casual, energetic, educational, entertaining |
| `target_length` | string | No | similar | shorter, similar, longer |
| `tone` | string | No | neutral | neutral, positive, serious, humorous |
| `purpose` | string | No | general | general, marketing, educational, storytelling, persuasive |
| `tts_voice` | string | No | am_michael | am_michael, am_sarah, bm_george, bm_emma |
| `crop_to_faces` | boolean | No | true | true, false |
| `smooth_transitions` | boolean | No | true | true, false |
| `focus_main_speaker` | boolean | No | true | true, false |

### Video Requirements
| Requirement | Value |
|-------------|-------|
| Max file size | 500MB |
| Supported formats | mp4, mov, avi, mkv, webm |
| Min duration | 10 seconds |
| Max duration | 10 minutes |

---

## 🚨 Error Handling

### Common Error Responses

**400 Bad Request:**
```json
{
  "success": false,
  "error": "Invalid file format. Supported formats: mp4, mov, avi, mkv, webm"
}
```

**413 Payload Too Large:**
```json
{
  "success": false,
  "error": "Video file too large (max 500MB)"
}
```

**503 Service Unavailable:**
```json
{
  "success": false,
  "error": "Python AI service is not available"
}
```

**500 Internal Server Error:**
```json
{
  "success": false,
  "error": "Processing failed: Transcription service error"
}
```

---

## 🧪 Quick Test Sequence

1. **Create Project**
   ```bash
   POST /api/projects
   ```

2. **Upload Video**
   ```bash
   POST /api/projects/1/upload-video
   ```

3. **Start Processing**
   ```bash
   POST /api/projects/1/process
   ```

4. **Check Status**
   ```bash
   GET /api/projects/1/status
   ```

5. **Get Result**
   ```bash
   GET /api/projects/1/result
   ```

---

## 📊 Monitoring

### Queue Status
**GET** `/queue/status`

```json
{
  "jobs": {
    "pending": 2,
    "processing": 1,
    "failed": 0,
    "completed": 15
  },
  "workers": {
    "active": 1,
    "total": 1
  }
}
```

### System Health
**GET** `/health`

```json
{
  "status": "healthy",
  "services": {
    "database": "connected",
    "redis": "connected", 
    "python_ai": "healthy",
    "r2_storage": "connected"
  }
}
```

---

## 🔑 Environment Variables Required

Make sure these are set in your `.env`:

```bash
# Python AI Service
PYTHON_AI_SERVICE_URL=http://ai:8000

# OpenAI
OPENAI_API_KEY=your_openai_key_here

# Cloudflare R2
AWS_ACCESS_KEY_ID=your_aws_key_here
AWS_SECRET_ACCESS_KEY=your_aws_secret_here
AWS_DEFAULT_REGION=auto
R2_BUCKET_NAME=your_bucket_name
R2_ENDPOINT=your_r2_endpoint
R2_URL=your_r2_url
```

---

## 🎯 Testing Tips

1. **Start Small:** Use a short video (30-60 seconds) for initial testing
2. **Check Health:** Verify all services are healthy before processing
3. **Monitor Progress:** Use the status endpoint to track processing
4. **Test Errors:** Try invalid files to see error handling
5. **Verify Output:** Check all generated files (video, audio, subtitles)

Your `yt_automation_short` template is now ready for comprehensive testing!
