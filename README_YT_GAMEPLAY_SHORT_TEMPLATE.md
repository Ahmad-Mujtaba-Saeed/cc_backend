# YT + Gameplay Short — New Template Implementation Guide

**For:** Copilot / Developer implementing this template into the existing Social Video Maker project  
**Status:** New template — does not yet exist. Build from scratch, following project conventions exactly.  
**Reference n8n Workflow:** `ShortForm_Video_Factory__YT___Gameplay_.json`

---

## Table of Contents

1. [What This Template Does](#1-what-this-template-does)
2. [How It Fits Into The Existing Project](#2-how-it-fits-into-the-existing-project)
3. [Input Options](#3-input-options)
4. [The Intelligent Clip Selection Algorithm](#4-the-intelligent-clip-selection-algorithm)
5. [Gameplay Video Options](#5-gameplay-video-options)
6. [Complete Step-by-Step Workflow](#6-complete-step-by-step-workflow)
7. [Files To Create](#7-files-to-create)
8. [Files To Modify](#8-files-to-modify)
9. [New Services — Full Implementation Specs](#9-new-services--full-implementation-specs)
10. [Python FastAPI Endpoints To Add](#10-python-fastapi-endpoints-to-add)
11. [Frontend Form Fields](#11-frontend-form-fields)
12. [Database / Model Changes](#12-database--model-changes)
13. [Temp File Lifecycle & Cleanup](#13-temp-file-lifecycle--cleanup)
14. [Storage Layout](#14-storage-layout)
15. [Environment Variables](#15-environment-variables)
16. [Error Handling Patterns](#16-error-handling-patterns)
17. [Pusher Progress Steps](#17-pusher-progress-steps)
18. [Full Data Flow Diagram](#18-full-data-flow-diagram)

---

## 1. What This Template Does

> **Update 2026-07-06 — multi-short output, gameplay toggle, silence editing.**
> The template now produces **multiple shorts per run** (user-selectable `clip_count`, 1–6,
> default **4**), each from a distinct non-overlapping "complete story" clip. Every clip is
> also **edited**, not just cut: `ClipEditPlanService` detects the empty parts inside the
> selected range (silence / dead air / music-only holes, via transcript gaps) and the Python
> `/cut-video-segments` endpoint cuts and concatenates only the speech blocks; caption
> timings are remapped onto the edited timeline. The gameplay bottom panel is now optional
> (`gameplay_enabled` checkbox, default on) — when off, the main clip fills the full
> 1080×1920 frame. Outputs land at `projects/{id}/output_{n}.mp4` with per-clip thumbnails,
> are listed in `processing_state.output_videos`, and are served to the frontend as
> `output_videos` on the project resource (`output_path` still points at the first video).

Takes a YouTube video (long-form, any length) OR a user-uploaded video file and converts it into 9:16 short-form vertical videos by:

1. Downloading the source video (RapidAPI download for YT URL, or saving the upload directly)
2. Transcribing the video (RapidAPI YouTube transcriber for YT URLs; **Faster-Whisper** locally for uploads or as fallback)
3. Using an **intelligent multi-pass grading algorithm** to find the N best non-overlapping 15–60s clips from the full transcript — this handles videos of ANY length, including 2+ hour videos
4. Building an edit plan per clip that skips the silent/empty parts, then cutting the kept ranges from the source video
5. Compositing each clip with a gameplay video in split-screen layout (top 60% = main clip, bottom 40% = gameplay) — or full-frame when the gameplay panel is disabled
6. Burning in karaoke-style word-level captions (timings remapped to the edited clip)
7. Generating a per-clip thumbnail and cleaning up temp files

The final output is one or more 9:16 (1080×1920) MP4s, identical format to all other templates.

---

## 2. How It Fits Into The Existing Project

Follow **every** existing convention without exception.

### Template type string
```
yt_gameplay_short
```

### Class naming
```
YTGameplayShortProcessor  (in backend/modules/Project/Processors/)
```

### Registration
- Add to `TemplateProcessorFactory.php` — same pattern as the other three processors
- Add to template config array / validation rules wherever other templates are listed
- Add to frontend template selector

### Queue
Same queue: `video-processing`. No changes to queue config.

### Storage
All temp work goes under the project's storage directory:
```
/storage/app/public/projects/{project_id}/
```
A `tmp/` subfolder within that project directory holds intermediate files (NOT the global `/data/tmp/` used in the n8n workflow — adapt to the project's path structure). Cleanup happens at the end.

---

## 3. Input Options

The user can provide the source video in **one of two ways**. The frontend must show both options with a toggle/radio.

### Option A — YouTube URL
- Field: `main_url` (string, required if mode = `youtube_url`)
- Accepts: standard `youtube.com/watch?v=`, `youtu.be/`, and `youtube.com/shorts/` URLs
- Video ID extraction regex: `/(?:v=|youtu\.be\/|shorts\/)([\w-]{11})/`
- The backend will use RapidAPI **only** to download the video file
- After download, the video is transcribed locally using Faster-Whisper (same as upload mode)

### Option B — Direct Video Upload
- Field: `video_file` (file upload, required if mode = `upload`)
- Accepted formats: mp4, mov, avi, mkv, webm
- Max size: 500MB (matches existing PHP upload config)
- The backend will use **Faster-Whisper** locally to transcribe it (same service used in `YT Automation Short` — reuse `VideoTranscriptionService`)
- No RapidAPI calls needed for this path — transcription is local for both modes

### Settings common to both options

| Field | Type | Options | Default | Notes |
|---|---|---|---|---|
| `clip_count` | enum | `1`–`6` | `4` | How many shorts to render from one source video |
| `gameplay_enabled` | checkbox | on/off | `true` | Off = main clip fills the full 9:16 frame |
| `gameplay_source` | enum | `backend_library`, `custom_upload` | `backend_library` | See Section 5; only shown when gameplay is enabled |
| `gameplay_file` | file | mp4/mov/webm | — | Only shown if `gameplay_source = custom_upload` |
| `caption_template` | enum | `modern_karaoke`, `classic_block`, `minimal_clean` | `modern_karaoke` | Reuse existing captions |
| `caption_position` | enum | `top_section`, `bottom_section` | `top_section` | Honored via ASS margin overrides: with gameplay, top = on the main panel above the split, bottom = on the gameplay panel; without gameplay, top = upper-middle, bottom = lower third |

---

## 4. The Intelligent Clip Selection Algorithm

This is the most important part of the template. The n8n workflow sends the entire transcript to GPT directly, but that breaks on long videos. You must implement a **multi-pass hierarchical grading system** that works on videos of any length.

### Why naive approach fails

A 2-hour video transcript can contain ~20,000+ words. Sending that to GPT in one shot:
- Exceeds context windows
- Produces poor results (GPT loses focus on long inputs)
- Is slow and expensive

### The Algorithm: Hierarchical Elimination with Weighted Scoring

#### Step 1 — Segment the transcript into windows

Divide the full transcript into overlapping time windows. Window size and overlap depend on total video duration:

```php
private function calculateWindowStrategy(float $totalDurationSeconds): array
{
    if ($totalDurationSeconds <= 600) {          // ≤10 min
        return ['window_seconds' => 120, 'overlap_seconds' => 30, 'max_windows' => 5];
    } elseif ($totalDurationSeconds <= 1800) {   // ≤30 min
        return ['window_seconds' => 300, 'overlap_seconds' => 60, 'max_windows' => 6];
    } elseif ($totalDurationSeconds <= 3600) {   // ≤1 hour
        return ['window_seconds' => 600, 'overlap_seconds' => 120, 'max_windows' => 6];
    } elseif ($totalDurationSeconds <= 7200) {   // ≤2 hours
        return ['window_seconds' => 1200, 'overlap_seconds' => 180, 'max_windows' => 6];
    } else {                                      // >2 hours
        return ['window_seconds' => 1800, 'overlap_seconds' => 300, 'max_windows' => 6];
    }
}
```

Build windows by scanning through the transcript segments array, grouping segments by time window, with overlap so no good clip gets split across a boundary.

Cap at 6 windows maximum regardless of video length — if needed, sample windows evenly across the full duration rather than only the beginning.

#### Step 2 — Phase 1: Score each window with GPT

For each window (run these calls **in parallel** using Laravel's concurrent HTTP or async jobs):

**System prompt:**
```
You are an expert viral short-form video analyst. Your job is to evaluate a section of transcript 
and identify the SINGLE best 20–60 second clip candidate within it.

Scoring criteria (weight in parentheses):
- Hook strength: Does it open with a statement that makes people want to keep listening? (30%)
- Emotional resonance: Does it create curiosity, surprise, laughter, shock, or strong feeling? (25%)
- Standalone coherence: Can this clip be understood with zero prior context from the video? (20%)
- Specificity: Does it contain concrete facts, numbers, stories, or examples? (15%)
- Audio-only viability: Does it work without visuals ("as you can see" = disqualifying)? (10%)

DISQUALIFY any segment containing: introductions, outros, sponsor reads, calls to subscribe, 
excessive filler words, or incomplete thoughts.

Return ONLY valid JSON. No markdown. No explanation outside the JSON.
```

**User prompt per window:**
```
Video section from {window_start}s to {window_end}s (out of {total_duration}s total).

Transcript segments:
{segments formatted as "[23.4s]: text of segment"}

Find the best clip. Return:
{
  "start_seconds": 23.4,
  "end_seconds": 58.1,
  "subtitle": "exact words in this clip",
  "highlight_words": ["word1", "word2", "word3"],
  "score": 87,
  "score_breakdown": {
    "hook_strength": 28,
    "emotional_resonance": 22,
    "standalone_coherence": 18,
    "specificity": 12,
    "audio_viability": 7
  },
  "reason": "one sentence explaining why this clip works"
}

Score must be 0–100. If no good clip exists in this section, return score: 0.
```

#### Step 3 — Phase 2: Filter and re-rank top candidates

After all Phase 1 calls return:

1. Filter out any candidate with `score < 40` 
2. If fewer than 2 candidates remain after filtering, lower threshold to 20 and retry
3. Sort remaining candidates by score descending
4. Take the **top 3 candidates**

#### Step 4 — Phase 3: Final selection call

Send the top 3 candidates to GPT in a single call for head-to-head comparison:

**System prompt:**
```
You are choosing the absolute best short-form video clip from finalists. 
Compare them holistically and pick ONE winner.
```

**User prompt:**
```
Here are the top {N} clip candidates from a video. Choose the single best one for a viral short.

{For each candidate:}
CANDIDATE {i}: Score {score} | {start}s – {end}s
Transcript: "{subtitle}"
Why it was selected: {reason}
Score breakdown: {score_breakdown}

Return ONLY:
{
  "winner_index": 0,
  "final_start_seconds": 23.4,
  "final_end_seconds": 58.1,
  "subtitle": "exact words of winning clip",
  "highlight_words": ["most", "important", "words"],
  "final_score": 87,
  "reason": "why this beats the others"
}
```

#### Step 5 — Validate output

```php
private function validateClipSelection(array $selection, float $totalDuration): void
{
    $duration = $selection['final_end_seconds'] - $selection['final_start_seconds'];
    
    if ($duration < 15) throw new \Exception("Selected clip too short: {$duration}s (min 15s)");
    if ($duration > 65) throw new \Exception("Selected clip too long: {$duration}s (max 65s)");
    if ($selection['final_start_seconds'] < 0) throw new \Exception("Clip starts before video");
    if ($selection['final_end_seconds'] > $totalDuration) throw new \Exception("Clip ends after video");
    if (empty($selection['subtitle'])) throw new \Exception("No subtitle text in selection");
}
```

#### Implementation class

```php
// backend/modules/Project/Services/ClipSelectionService.php
class ClipSelectionService
{
    public function findBestClip(array $transcriptSegments, float $totalDurationSeconds): array
    {
        $strategy = $this->calculateWindowStrategy($totalDurationSeconds);
        $windows = $this->buildWindows($transcriptSegments, $strategy);
        $phase1Results = $this->scoreWindowsParallel($windows, $totalDurationSeconds);
        $topCandidates = $this->filterAndRank($phase1Results, 3);
        $winner = $this->runFinalSelection($topCandidates);
        $this->validateClipSelection($winner, $totalDurationSeconds);
        return $winner;
    }
    
    // ... all private methods described above
}
```

**GPT model:** `gpt-4o-mini` (same as all other templates)  
**Temperature:** `0.3` (lower = more consistent selection, less creative hallucination)  
**max_tokens:** `500` per Phase 1 call, `800` for Phase 3 call

---

## 5. Gameplay Video Options

### Option A — Backend Library (default)

The project's backend storage already contains a library of gameplay videos. These are stored at:
```
/storage/app/public/gameplay/
```

When the user selects `backend_library`, the processor picks a random gameplay video from this directory.

```php
// backend/modules/Project/Services/GameplayVideoService.php

public function getRandomFromLibrary(): string
{
    $gameplayDir = storage_path('app/public/gameplay');
    $files = glob($gameplayDir . '/*.mp4');
    
    if (empty($files)) {
        throw new \Exception('No gameplay videos found in backend library at: ' . $gameplayDir);
    }
    
    return $files[array_rand($files)];
}
```

The `gameplay/` directory must exist in storage. If it doesn't, throw a clear error: `"Gameplay library is empty. Please upload gameplay videos to storage/app/public/gameplay/ or choose custom upload."`

### Option B — Custom Upload

The user uploads their own gameplay video via the frontend form.

- Field name: `gameplay_file`
- Only shown when `gameplay_source = custom_upload`
- Saved to: `/storage/app/public/projects/{project_id}/gameplay_source.mp4`
- Max size: 500MB
- Accepted: mp4, mov, avi, mkv, webm

The processor uses this path directly as the gameplay video — no random selection.

### Gameplay video processing (both options)

Regardless of source, the gameplay video must be:
1. **Looped** to match the duration of the main clip (if gameplay is shorter than clip)
2. **Cropped/scaled** to exactly 1080×768 (the bottom 40% of a 1080×1920 frame)

This is done via FFmpeg in the Python service. See Section 10 for the endpoint spec.

---

## 6. Complete Step-by-Step Workflow

```
STEP 1 — VALIDATE INPUT (progress: 5%)
├─ If mode = youtube_url:
│  ├─ Extract video ID from URL
│  ├─ Validate video ID format (11 chars, alphanumeric+dash)
│  └─ Generate job_id = project_id (use existing project ID as job identifier)
├─ If mode = upload:
│  ├─ Verify uploaded file exists at storage path
│  ├─ Verify file is readable and non-zero size
│  └─ Check file extension is in allowed list
├─ Validate gameplay_source and gameplay_file (if custom)
└─ Validate caption_template setting

STEP 2 — DOWNLOAD / PREPARE SOURCE VIDEO (progress: 10–30%)
├─ If mode = youtube_url:
│  ├─ Init download via RapidAPI (download only — NO transcript API):
│  │  GET youtube-info-download-api.p.rapidapi.com/ajax/download.php
│  │  Params: format=480, url={yt_url}, audio_quality=128, no_merge=false, allow_extended_duration=false
│  │  Returns: { progress_url: "...", title: "..." }
│  ├─ Poll progress_url every 10 seconds (max 20 attempts = 200s timeout)
│  │  Response: { progress: 0–1000, download_url: "..." }
│  │  Ready when: progress >= 1000 AND download_url is present
│  └─ Stream-download video file to:
│     /storage/app/public/projects/{project_id}/source_video.mp4
│     IMPORTANT: Use Laravel HTTP with stream=true to avoid memory overflow on large files
│     $response = Http::withOptions(['stream' => true, 'timeout' => 300])->get($downloadUrl);
│     Write in chunks: while (!$body->eof()) { fwrite($handle, $body->read(8192)); }
├─ If mode = upload:
│  └─ Source video already saved at storage path by ProjectController — verify it exists and is > 0 bytes
└─ Set source_video_path in processing_state

STEP 3 — TRANSCRIBE VIDEO LOCALLY (progress: 30–45%)
│
│  BOTH modes use the same transcription path — Faster-Whisper on your server.
│  Do NOT use RapidAPI transcript API. It is inaccurate. Transcribe the actual video file.
│
├─ Call VideoTranscriptionService (existing service — reuse exactly as-is)
│  ├─ Input: source_video_path from Step 2
│  ├─ Internally: extracts audio track via FFmpeg → temp WAV/MP3
│  ├─ Runs Faster-Whisper model (Python /transcribe endpoint)
│  └─ Returns segments in standard format: [ { text, start, end }, ... ]
│     where start/end are absolute seconds from start of video
├─ Build fullText: join all segment.text with spaces
├─ Extract totalDuration: last segment's end value
│  Fallback: if last segment has no end, use FFprobe to get video duration
└─ Store: segments[], fullText, totalDuration in processing_state

STEP 4 — SELECT BEST CLIP (progress: 45–60%)
├─ Call ClipSelectionService->findBestClip(segments, totalDuration)
│  └─ Runs multi-pass GPT algorithm (see Section 4)
│     Phase 1: Score windows in parallel
│     Phase 2: Filter, rank, take top 3
│     Phase 3: Final head-to-head selection
├─ Result: { final_start_seconds, final_end_seconds, subtitle, highlight_words, reason }
└─ Store selection in processing_state

STEP 5 — PREPARE GAMEPLAY VIDEO (progress: 55–60%)
├─ If gameplay_source = backend_library:
│  └─ Call GameplayVideoService->getRandomFromLibrary()
├─ If gameplay_source = custom_upload:
│  └─ Use uploaded gameplay_file path from project settings
└─ Store gameplay_source_path in processing_state

STEP 6 — CUT MAIN CLIP (progress: 60–65%)
├─ Call Python endpoint /cut-video-clip with:
│  - source_path: source_video_path
│  - start_seconds: final_start_seconds
│  - end_seconds: final_end_seconds
│  - output_path: /projects/{project_id}/tmp/main_clip.mp4
├─ FFmpeg command:
│  ffmpeg -ss {start} -i {source} -t {duration} -c copy {output} -y
│  (Use -ss before -i for fast seeking, -t for duration)
└─ Verify output file exists and has non-zero size

STEP 7 — LOOP GAMEPLAY VIDEO (progress: 65–68%)
├─ Call Python endpoint /loop-video with:
│  - source_path: gameplay_source_path
│  - target_duration_seconds: clip_duration (end - start)
│  - output_path: /projects/{project_id}/tmp/gameplay_loop.mp4
├─ FFmpeg strategy:
│  - If gameplay is shorter: use -stream_loop -1 to loop, then trim to target duration
│  - If gameplay is longer: just trim to target duration
│  - Scale to 1080×768 (bottom panel dimensions)
└─ Verify output

STEP 8 — BUILD KARAOKE CAPTIONS (progress: 68–72%)
│
│  Use the existing WordLevelCaptionService EXACTLY as other templates do.
│  Do NOT reinvent caption logic inline. Call the service directly.
│
├─ PART A: Build word timings array from transcript segments
│  ├─ Filter transcript segments within the clip window:
│  │  $clipSegments = array_filter($segments, fn($s) =>
│  │      floatval($s['end']) > $finalStartSeconds &&
│  │      floatval($s['start']) < $finalEndSeconds
│  │  );
│  ├─ For each segment, interpolate per-word timings:
│  │  - words = explode(' ', trim($segment['text']))
│  │  - timePerWord = (seg.end - seg.start) / count(words)
│  │  - For each word at index i:
│  │      wordStart = seg.start + (i * timePerWord)
│  │      wordEnd   = seg.start + ((i + 1) * timePerWord)
│  ├─ CRITICAL — timestamps must be relative to clip start (not absolute video time):
│  │      wordStart = wordStart - $finalStartSeconds  (clamp to >= 0)
│  │      wordEnd   = wordEnd   - $finalStartSeconds
│  └─ Result: $wordTimings = [
│         ['word' => 'Hello',   'start' => 0.0,  'end' => 0.42],
│         ['word' => 'world',   'start' => 0.42, 'end' => 0.85],
│         ...
│     ]
│     This is the exact format WordLevelCaptionService->generateKaraokeCaptions() expects.
│
├─ PART B: Call WordLevelCaptionService
│  ├─ $captionService = app(WordLevelCaptionService::class)  (or inject via constructor)
│  ├─ $captionsRelativePath = "projects/{$projectId}/tmp/captions.ass"
│  │  (This is a Storage::disk('public') relative path — the service writes to storage/app/public/)
│  ├─ $captionTemplate = $this->project->settings['caption_template'] ?? 'modern_karaoke'
│  │  Valid values: 'modern_karaoke' | 'classic_block' | 'minimal_clean'
│  │  These map exactly to the templates defined in WordLevelCaptionService::getCaptionTemplate()
│  └─ $success = $captionService->generateKaraokeCaptions(
│         $wordTimings,
│         $captionsRelativePath,
│         $captionTemplate
│     );
│     Throws if $success === false: "Caption generation failed — check word timings array"
│
├─ PART C: Resolve the full absolute path for FFmpeg
│  ├─ The Python /compose-short endpoint needs the ABSOLUTE server path (Docker volume path)
│  ├─ PHP absolute: storage_path('app/public/' . $captionsRelativePath)
│  │   = /var/www/storage/app/public/projects/{id}/tmp/captions.ass
│  └─ Python absolute (translate for Docker volume):
│      str_replace('/var/www/storage', '/app/storage', $phpAbsolutePath)
│      = /app/storage/app/public/projects/{id}/tmp/captions.ass
│     Store this translated path in processing_state['tmp']['captions_ass']
│     and pass it to ShortComposerService
│
└─ caption_position setting maps to ASS alignment:
   'top_section'    → the service's template alignment handles position automatically;
                      pass caption_position through settings to ShortComposerService
                      so the Python endpoint can optionally offset the ASS overlay Y position
   'bottom_section' → same — Python endpoint adjusts the ass filter's y offset if needed
                      (see /compose-short endpoint for how caption_position is used)

STEP 9 — COMPOSE FINAL SHORT (progress: 72–85%)
├─ Call Python endpoint /compose-short with:
│  - main_clip: /projects/{project_id}/tmp/main_clip.mp4
│  - gameplay_clip: /projects/{project_id}/tmp/gameplay_loop.mp4
│  - captions_ass: /projects/{project_id}/tmp/captions.ass
│  - output_path: /projects/{project_id}/output.mp4
│  - aspect_ratio: 9:16
├─ FFmpeg filter_complex:
│  [0:v] scale=1080:1152:force_original_aspect_ratio=increase, crop=1080:1152, setsar=1, fps=30 [top]
│  [1:v] scale=1080:768:force_original_aspect_ratio=increase, crop=1080:768, setsar=1, fps=30 [bottom]
│  [top][bottom] vstack=inputs=2 [stacked]
│  [stacked] ass={captions_ass_path} [out]
│  Map [out] + audio from input 0
│  Output: libx264, preset fast, crf 22, aac 128k, movflags +faststart
├─ Final dimensions: 1080×1920 (1152 top + 768 bottom = 1920) ✓
└─ Verify output file

STEP 10 — GENERATE THUMBNAIL (progress: 85–87%)
├─ Extract single frame at midpoint of clip: (duration / 2) seconds
├─ Resize to 1080×1920
└─ Save as /projects/{project_id}/thumbnail.jpg
   (Reuse existing thumbnail generation approach)

STEP 11 — UPLOAD TO R2 (progress: 87–95%)
├─ Use existing R2UploadService
├─ Key: shorts/yt_gameplay_{project_id}_{timestamp}.mp4
└─ Store r2_url and r2_key in project

STEP 12 — CLEANUP TEMP FILES (progress: 95–98%)
├─ Delete all files in /projects/{project_id}/tmp/:
│  - source_video.mp4 (large — delete immediately after clip is cut)
│  - main_clip.mp4
│  - gameplay_loop.mp4
│  - captions.ass
│  (Keep: output.mp4, thumbnail.jpg — these are the deliverables)
└─ Log cleanup result (non-fatal if some files already gone)

STEP 13 — COMPLETE (progress: 100%)
├─ Update project status to 'completed'
├─ Store output_path, thumbnail_path, r2_url, r2_key in project
└─ Send Pusher completion notification
```

---

## 7. Files To Create

### New PHP files

```
backend/modules/Project/Processors/YTGameplayShortProcessor.php
backend/modules/Project/Services/ClipSelectionService.php
backend/modules/Project/Services/GameplayVideoService.php
backend/modules/Project/Services/YTVideoDownloadService.php
backend/modules/Project/Services/VideoClipCutterService.php
backend/modules/Project/Services/ShortComposerService.php
```

### New Python endpoint additions (in existing app.py)

Add these endpoints to `backend/docker/python/app.py`:
- `POST /cut-video-clip`
- `POST /loop-video`
- `POST /compose-short`
- `POST /write-file` (if not already present — was used in n8n workflow)

### Frontend

```
frontend/components/templates/YTGameplayShortForm.tsx  (or .jsx)
```

Add form option to the existing template selector component.

---

## 8. Files To Modify

### `backend/modules/Project/Processors/TemplateProcessorFactory.php`
Add case for `yt_gameplay_short`:
```php
case 'yt_gameplay_short':
    return new YTGameplayShortProcessor($project, $pusherService);
```

### `backend/modules/Project/Http/Controllers/ProjectController.php`
Add `yt_gameplay_short` to the allowed template types validation rule.

### `backend/modules/Project/Models/Project.php` (or migration)
No new columns needed. The `settings` JSON column already handles template-specific config. The `processing_state` JSON column handles intermediate data. The `video_path` column stores the source upload path.

### `backend/docker/python/app.py`
Add three new endpoint functions (see Section 10).

### `frontend/...` (template selector)
Add `yt_gameplay_short` as a selectable template with its form component.

---

## 9. New Services — Full Implementation Specs

### `YTVideoDownloadService.php`

**Purpose:** Download a YouTube video via RapidAPI to local storage. This service handles **download only** — transcription is always done locally via `VideoTranscriptionService` after the file is on disk.

```php
class YTVideoDownloadService
{
    private string $rapidApiKey;
    private string $rapidApiHost = 'youtube-info-download-api.p.rapidapi.com';

    public function initDownload(string $ytUrl): array
    {
        // GET /ajax/download.php
        // Params: format=480, add_info=0, url={ytUrl}, audio_quality=128,
        //         allow_extended_duration=false, no_merge=false, audio_language=en
        // Headers: x-rapidapi-host, x-rapidapi-key (from config('services.rapidapi'))
        // Returns: { progress_url: "...", title: "...", ... }
        // Throw if progress_url missing: "RapidAPI did not return a progress URL"
    }

    public function pollUntilReady(string $progressUrl, int $maxAttempts = 20, int $pollIntervalSeconds = 10): string
    {
        // Poll $progressUrl (plain HTTP GET, no special headers needed) every $pollIntervalSeconds
        // Check: $response['progress'] >= 1000
        // Return $response['download_url'] when ready
        // Throw if maxAttempts exceeded: "Video download timed out after " . ($maxAttempts * $pollIntervalSeconds) . "s — try again or use upload mode"
    }

    public function streamToStorage(string $downloadUrl, string $outputAbsolutePath): void
    {
        // MUST use streaming to avoid loading large videos into memory
        // $response = Http::withOptions(['stream' => true, 'timeout' => 300])->get($downloadUrl);
        // $body = $response->toPsrResponse()->getBody();
        // $handle = fopen($outputAbsolutePath, 'wb');
        // while (!$body->eof()) { fwrite($handle, $body->read(8192)); }
        // fclose($handle);
        // Verify: filesize($outputAbsolutePath) > 0
        // Throw if empty: "Downloaded file is empty — download may have failed"
    }
}
```

### `ClipSelectionService.php`

Full implementation as described in Section 4. Key method signatures:

```php
class ClipSelectionService
{
    public function findBestClip(array $transcriptSegments, float $totalDurationSeconds): array
    // Returns: [final_start_seconds, final_end_seconds, subtitle, highlight_words, reason, final_score]

    private function calculateWindowStrategy(float $totalDurationSeconds): array
    // Returns: [window_seconds, overlap_seconds, max_windows]

    private function buildWindows(array $segments, array $strategy): array
    // Returns array of windows, each: [start_time, end_time, segments[]]

    private function scoreWindowsParallel(array $windows, float $totalDuration): array
    // Runs GPT calls for all windows — use Pool or sequential with async if Pool unavailable
    // Returns array of scored candidates

    private function buildPhase1Prompt(array $windowSegments, float $windowStart, float $windowEnd, float $totalDuration): string

    private function buildPhase3Prompt(array $topCandidates): string

    private function filterAndRank(array $candidates, int $topN): array
    // Filter score < 40, sort desc, take topN

    private function runFinalSelection(array $topCandidates): array

    private function callOpenAI(string $systemPrompt, string $userPrompt, float $temperature = 0.3): array
    // Uses same OpenAI client as other services, gpt-4o-mini, max_tokens=500

    private function validateClipSelection(array $selection, float $totalDuration): void
}
```

### `GameplayVideoService.php`

```php
class GameplayVideoService
{
    private string $libraryPath;

    public function __construct()
    {
        $this->libraryPath = storage_path('app/public/gameplay');
    }

    public function getRandomFromLibrary(): string
    {
        // glob for *.mp4 in libraryPath
        // throw if empty: "Gameplay library is empty..."
        // return random file path
    }

    public function getLibraryList(): array
    {
        // Returns array of available gameplay video filenames (for frontend preview)
    }

    public function libraryExists(): bool
    {
        return is_dir($this->libraryPath) && count(glob($this->libraryPath . '/*.mp4')) > 0;
    }
}
```

### `VideoClipCutterService.php`

```php
class VideoClipCutterService
{
    private PythonAIService $pythonService;

    public function cut(string $sourcePath, float $startSeconds, float $endSeconds, string $outputPath): string
    {
        // Call Python /cut-video-clip endpoint
        // duration = endSeconds - startSeconds
        // Returns output path on success
        // Throws on failure
    }
}
```

### `ShortComposerService.php`

```php
class ShortComposerService
{
    private PythonAIService $pythonService;

    public function compose(
        string $mainClipPath,
        string $gameplayClipPath,
        string $captionsAssPath,
        string $outputPath,
        array $settings = []
    ): string
    {
        // Call Python /compose-short endpoint
        // settings: aspect_ratio (default 9:16), caption_position
        // Returns final video output path
    }
}
```

---

## 10. Python FastAPI Endpoints To Add

Add these to `backend/docker/python/app.py`. Follow the exact same pattern as existing endpoints (`/apply-vibrate`, `/assemble-scenes`, etc.).

### `POST /cut-video-clip`

```python
class CutVideoClipRequest(BaseModel):
    source_path: str
    start_seconds: float
    end_seconds: float
    output_path: str
    project_id: int

@app.post("/cut-video-clip")
async def cut_video_clip(request: CutVideoClipRequest):
    """
    Cut a segment from a video using fast seeking.
    Uses -ss before -i for fast keyframe seeking, then -t for duration.
    """
    os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
    duration = request.end_seconds - request.start_seconds
    
    cmd = [
        'ffmpeg',
        '-ss', str(request.start_seconds),
        '-i', request.source_path,
        '-t', str(duration),
        '-c', 'copy',          # Stream copy = fast, no re-encode
        '-avoid_negative_ts', 'make_zero',
        request.output_path,
        '-y'
    ]
    
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    
    if result.returncode != 0 or not os.path.exists(request.output_path):
        raise HTTPException(status_code=500, detail=f"FFmpeg error: {result.stderr[:500]}")
    
    file_size = os.path.getsize(request.output_path)
    if file_size == 0:
        raise HTTPException(status_code=500, detail="Output file is empty")
    
    return {"success": True, "output_path": request.output_path, "file_size": file_size, "duration": duration}
```

### `POST /loop-video`

```python
class LoopVideoRequest(BaseModel):
    source_path: str
    target_duration_seconds: float
    output_width: int = 1080
    output_height: int = 768     # Bottom panel: 40% of 1920
    output_path: str
    project_id: int

@app.post("/loop-video")
async def loop_video(request: LoopVideoRequest):
    """
    Loop a video to match target duration, then scale to target dimensions.
    If source is shorter than target, loop it. If longer, trim it.
    """
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
        '-stream_loop', '-1',           # Loop indefinitely at input level
        '-i', request.source_path,
        '-t', str(request.target_duration_seconds),
        '-vf', scale_filter,
        '-an',                           # Drop audio from gameplay
        '-c:v', 'libx264',
        '-preset', 'fast',
        '-crf', '23',
        request.output_path,
        '-y'
    ]
    
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    
    if result.returncode != 0:
        raise HTTPException(status_code=500, detail=f"Loop/scale error: {result.stderr[:500]}")
    
    return {"success": True, "output_path": request.output_path}
```

### `POST /compose-short`

```python
class ComposeShortRequest(BaseModel):
    main_clip_path: str
    gameplay_clip_path: str
    captions_ass_path: str
    output_path: str
    project_id: int
    aspect_ratio: str = "9:16"
    caption_position: str = "top_section"   # top_section | bottom_section

@app.post("/compose-short")
async def compose_short(request: ComposeShortRequest):
    """
    Compose final 9:16 short:
    - Top panel (60%): 1080×1152 — main clip
    - Bottom panel (40%): 1080×768 — gameplay
    - Karaoke captions burned in via ASS file
    - Audio from main clip only
    Final output: 1080×1920
    """
    os.makedirs(os.path.dirname(request.output_path), exist_ok=True)
    
    # Escape ASS path for FFmpeg filter
    ass_escaped = request.captions_ass_path.replace('\\', '/').replace(':', '\\:')
    
    filter_complex = (
        "[0:v]scale=1080:1152:force_original_aspect_ratio=increase,"
        "crop=1080:1152,setsar=1,fps=30[top];"
        "[1:v]scale=1080:768:force_original_aspect_ratio=increase,"
        "crop=1080:768,setsar=1,fps=30[bottom];"
        "[top][bottom]vstack=inputs=2[stacked];"
        f"[stacked]ass={ass_escaped}[out]"
    )
    
    cmd = [
        'ffmpeg',
        '-i', request.main_clip_path,
        '-i', request.gameplay_clip_path,
        '-filter_complex', filter_complex,
        '-map', '[out]',
        '-map', '0:a:0',            # Audio from main clip
        '-c:v', 'libx264',
        '-preset', 'fast',
        '-crf', '22',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-movflags', '+faststart',
        request.output_path,
        '-y'
    ]
    
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
    
    if result.returncode != 0 or not os.path.exists(request.output_path):
        raise HTTPException(status_code=500, detail=f"Compose error: {result.stderr[:1000]}")
    
    return {
        "success": True,
        "output_path": request.output_path,
        "file_size": os.path.getsize(request.output_path)
    }
```

### `POST /write-file` (add if not already present)

```python
class WriteFileRequest(BaseModel):
    path: str
    content: str

@app.post("/write-file")
async def write_file(request: WriteFileRequest):
    """Write text content (e.g. ASS subtitle file) to disk."""
    os.makedirs(os.path.dirname(request.path), exist_ok=True)
    with open(request.path, 'w', encoding='utf-8') as f:
        f.write(request.content)
    return {"success": True, "path": request.path}
```

---

## 11. Frontend Form Fields

The frontend form for this template must show these fields. Follow the exact same UI patterns as the existing template forms.

```
Template: YT + Gameplay Short
─────────────────────────────

SOURCE VIDEO
  [○] YouTube URL   [○] Upload Video File   ← radio toggle

  If YouTube URL:
    [input] YouTube URL
    Placeholder: "https://www.youtube.com/watch?v=..."
    Validation: must contain valid YouTube video ID

  If Upload Video File:
    [file drop zone] Upload Video
    Accepted: mp4, mov, avi, mkv, webm — max 500MB

GAMEPLAY VIDEO
  [○] Use from Library   [○] Upload Custom Gameplay   ← radio toggle

  If Upload Custom Gameplay:
    [file drop zone] Upload Gameplay Video
    Accepted: mp4, mov, webm — max 500MB
    Note: "This will loop to match your clip length"

CAPTION STYLE
  [dropdown] Caption Template
  Options: Modern Karaoke (default) | Classic Block | Minimal Clean

CAPTION POSITION (in main clip area)
  [dropdown] Caption Position
  Options: Upper Area (default) | Lower Area
```

### Settings object sent to backend

```json
{
  "input_mode": "youtube_url",           // or "upload"
  "main_url": "https://youtube.com/...", // if youtube_url mode
  "gameplay_source": "backend_library",  // or "custom_upload"
  "caption_template": "modern_karaoke",
  "caption_position": "top_section"
}
```

---

## 12. Database / Model Changes

**No new columns needed.** All data fits in existing columns:

| Column | What's stored for this template |
|---|---|
| `template_type` | `"yt_gameplay_short"` |
| `video_path` | Path to uploaded source video (upload mode) OR null (YT URL mode) |
| `output_path` | Final composed short MP4 |
| `thumbnail_path` | Thumbnail JPG |
| `r2_url` | Final Cloudflare R2 URL |
| `r2_key` | R2 storage key |
| `settings` | JSON: input_mode, main_url, gameplay_source, caption_template, caption_position |
| `processing_state` | JSON: transcript segments, totalDuration, selected clip data, paths to temp files |

### `processing_state` shape during processing

```json
{
  "transcript_segments": [...],
  "total_duration": 3720.5,
  "full_text": "...",
  "video_title": "Title from RapidAPI download init (or filename for upload mode)",
  "source_video_path": "/projects/42/source_video.mp4",
  "selected_clip": {
    "final_start_seconds": 245.3,
    "final_end_seconds": 287.1,
    "subtitle": "exact transcript text",
    "highlight_words": ["word1", "word2"],
    "reason": "Strong hook about...",
    "final_score": 84
  },
  "gameplay_source_path": "/storage/app/public/gameplay/minecraft.mp4",
  "tmp": {
    "main_clip": "/projects/42/tmp/main_clip.mp4",
    "gameplay_loop": "/projects/42/tmp/gameplay_loop.mp4",
    "captions_ass": "/projects/42/tmp/captions.ass"
  }
}
```

---

## 13. Temp File Lifecycle & Cleanup

**Critical:** Source videos can be very large (480p YT downloads can be 200–800MB). Clean up aggressively.

### Cleanup timing

| File | When to delete |
|---|---|
| `source_video.mp4` | Immediately after `main_clip.mp4` is successfully cut (Step 6) — don't wait for end |
| `main_clip.mp4` | After compose step completes |
| `gameplay_loop.mp4` | After compose step completes |
| `captions.ass` | After compose step completes |

### Cleanup implementation

```php
private function cleanupTempFiles(int $projectId): void
{
    $tmpDir = storage_path("app/public/projects/{$projectId}/tmp");
    
    if (!is_dir($tmpDir)) return;
    
    $files = glob($tmpDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);  // @ suppresses warning if already gone
        }
    }
    
    @rmdir($tmpDir);  // Remove dir only if now empty
    
    Log::info("Cleanup complete for project {$projectId}");
}
```

### Early cleanup of source video (inside processor)

```php
// After Step 6 (cut main clip) succeeds:
$sourceVideoPath = $this->project->processing_state['source_video_path'] ?? null;
if ($sourceVideoPath && file_exists($sourceVideoPath)) {
    unlink($sourceVideoPath);
    Log::info("Deleted large source video early: {$sourceVideoPath}");
}
```

### Cleanup on failure

The `process()` method must have a try/catch that calls `cleanupTempFiles()` even on failure. Don't leave large files on disk if processing errors out.

```php
public function process(): void
{
    try {
        // ... all steps
    } catch (\Exception $e) {
        $this->cleanupTempFiles($this->project->id);
        throw $e;  // Re-throw so job handles retry/failure
    }
}
```

---

## 14. Storage Layout

```
/storage/app/public/
├── gameplay/                          ← Backend gameplay library
│   ├── minecraft_parkour.mp4
│   ├── subway_surfers.mp4
│   └── gta_driving.mp4
│
└── projects/
    └── {project_id}/
        ├── source_video.mp4           ← YT download or uploaded file (TEMP — deleted after clip cut)
        ├── gameplay_source.mp4        ← Custom uploaded gameplay (if that mode)
        ├── tmp/
        │   ├── main_clip.mp4          ← Cut clip from source (TEMP)
        │   ├── gameplay_loop.mp4      ← Looped/scaled gameplay (TEMP)
        │   └── captions.ass           ← ASS subtitle file (TEMP)
        ├── output.mp4                 ← FINAL VIDEO (keep)
        └── thumbnail.jpg             ← FINAL THUMBNAIL (keep)
```

### Storage path helper

PHP path: `storage_path('app/public/projects/' . $projectId . '/tmp/')`  
Python path (via Docker volume): `/app/storage/app/public/projects/{project_id}/tmp/`  
Public URL: `asset('storage/projects/' . $projectId . '/output.mp4')`

---

## 15. Environment Variables

Add these to `.env` and `docker-compose.yml` environment sections:

```env
# RapidAPI (for YT video download ONLY — transcription is done locally via Faster-Whisper)
RAPIDAPI_KEY=your_rapidapi_key_here
RAPIDAPI_DOWNLOAD_HOST=youtube-info-download-api.p.rapidapi.com

# Gameplay library path (optional override, defaults to storage/app/public/gameplay)
GAMEPLAY_LIBRARY_PATH=
```

The existing `OPENAI_API_KEY`, `CLOUDFLARE_R2_*`, `PUSHER_*`, and `PYTHON_AI_SERVICE_URL` env vars are all reused — no changes needed.

### Config file

Add to `config/services.php`:

```php
'rapidapi' => [
    'key' => env('RAPIDAPI_KEY'),
    'download_host' => env('RAPIDAPI_DOWNLOAD_HOST', 'youtube-info-download-api.p.rapidapi.com'),
    // NOTE: No transcript_host — transcription is always done locally via Faster-Whisper
],
```

---

## 16. Error Handling Patterns

Follow the exact same error pattern used in existing processors. Each step should push a descriptive error to Pusher before throwing.

```php
private function failWithError(string $step, string $message, \Exception $e): never
{
    $this->pusherService->sendStatus(
        $this->project->id,
        'failed',
        "Failed at step '{$step}': {$message}"
    );
    
    $this->project->update([
        'status' => 'failed',
        'error_message' => $message,
        'failed_step' => $step,
    ]);
    
    throw $e;
}
```

### Step-specific error messages for user display

| Step | User-facing error message |
|---|---|
| Validate | "Invalid YouTube URL — could not extract video ID" |
| Download Video | "Video download failed or timed out. Please try again or use the upload option." |
| Transcribe | "Video transcription failed. The video may be silent, corrupted, or in an unsupported format." |
| Select Clip | "Could not find a suitable clip in this video. The content may not contain a strong standalone segment." |
| Cut Clip | "Video cutting failed. The source file may be corrupted." |
| Compose | "Final video composition failed. Please try again." |
| Upload | "Upload to storage failed. Please try again." |

---

## 17. Pusher Progress Steps

Send these exactly — frontend uses the `step` field for UI display.

```php
// Use existing PusherService methods
$this->pusherService->sendStepProgress($projectId, 'validate_input',      1,  13, 5);
$this->pusherService->sendStepProgress($projectId, 'download_video',       2,  13, 20);
$this->pusherService->sendStepProgress($projectId, 'transcribe_video',     3,  13, 42);
$this->pusherService->sendStepProgress($projectId, 'select_best_clip',     4,  13, 57);
$this->pusherService->sendStepProgress($projectId, 'prepare_gameplay',     5,  13, 62);
$this->pusherService->sendStepProgress($projectId, 'cut_main_clip',        6,  13, 66);
$this->pusherService->sendStepProgress($projectId, 'loop_gameplay',        7,  13, 69);
$this->pusherService->sendStepProgress($projectId, 'build_captions',       8,  13, 73);
$this->pusherService->sendStepProgress($projectId, 'compose_short',        9,  13, 84);
$this->pusherService->sendStepProgress($projectId, 'generate_thumbnail',   10, 13, 87);
$this->pusherService->sendStepProgress($projectId, 'upload_to_r2',         11, 13, 94);
$this->pusherService->sendStepProgress($projectId, 'cleanup',              12, 13, 97);
$this->pusherService->sendStepProgress($projectId, 'completed',            13, 13, 100);
```

---

## 18. Full Data Flow Diagram

```
Frontend Form
    │
    │ POST /api/projects
    │ {
    │   template_type: "yt_gameplay_short",
    │   settings: { input_mode, main_url OR video_file,
    │               gameplay_source, caption_template, ... }
    │ }
    ▼
ProjectController@store()
    ├─ Validate template type
    ├─ Create Project (status: pending)
    ├─ Save uploaded files (video_file, gameplay_file) to storage
    └─ Dispatch ProcessVideoJob → Redis
    
Redis Queue
    └─ ProcessVideoJob picked up by worker
    
ProcessVideoJob@handle()
    └─ TemplateProcessorFactory → YTGameplayShortProcessor
    
YTGameplayShortProcessor@process()
    │
    ├─[A: YT URL mode]─────────────────────────────────────────────────┐
    │                                                                    │
    │  YTVideoDownloadService (download ONLY — no transcript API)        │
    │  └─ RapidAPI (download-api): Init → poll → download_url           │
    │       │                                                            │
    │       └─ HTTP stream download → source_video.mp4                  │
    │                                                                    │
    ├─[B: Upload mode]──────────────────────────────────────────────────┤
    │                                                                    │
    │  source_video already saved at storage path by controller          │
    │                                                                    │
    └─────────────────────────────────────┬────────────────────────────┘
                                          │
                         [both paths: transcribe locally]
                                          │
                                          ▼
    VideoTranscriptionService (existing, reused — SAME for both modes)
    ├─ Python /transcribe: Faster-Whisper on your server
    └─ Returns segments[] [{text, start, end}], totalDuration
    ClipSelectionService@findBestClip(segments, totalDuration)
        │
        ├─ calculateWindowStrategy() → window size based on duration
        ├─ buildWindows() → 4–6 overlapping time windows
        ├─ Phase 1: scoreWindowsParallel()
        │  └─ For each window: OpenAI gpt-4o-mini → scored candidate
        ├─ Phase 2: filterAndRank() → top 3 candidates
        └─ Phase 3: runFinalSelection() → single winner
        
    VideoClipCutterService@cut()
        └─ Python /cut-video-clip
           └─ FFmpeg: -ss {start} -i source -t {dur} -c copy → main_clip.mp4
           
    [Delete source_video.mp4 immediately after cut]
    
    GameplayVideoService
    ├─ getRandomFromLibrary() [library mode]
    └─ use uploaded path [custom mode]
    
    VideoClipCutterService (reused for loop)
    └─ Python /loop-video
       └─ FFmpeg: -stream_loop -1 -i gameplay -t {dur} + scale 1080×768 → gameplay_loop.mp4
    
    WordLevelCaptionService@generateKaraokeCaptions() (existing service, reused)
    ├─ Input: $wordTimings (built from transcript segments in clip window, timestamps relative to clip start)
    ├─ Input: storage relative path → "projects/{id}/tmp/captions.ass"
    ├─ Input: $captionTemplate from project settings ('modern_karaoke' | 'classic_block' | 'minimal_clean')
    └─ Writes ASS file to storage/app/public/projects/{id}/tmp/captions.ass
    
    ShortComposerService@compose()
    └─ Python /compose-short
       └─ FFmpeg filter_complex:
          [top: 1080×1152] + [bottom: 1080×768]
          vstack → 1080×1920
          ass captions burned in
          → output.mp4
    
    R2UploadService (existing, reused)
    └─ Upload output.mp4 → Cloudflare R2 → r2_url
    
    cleanupTempFiles()
    └─ rm tmp/main_clip.mp4, tmp/gameplay_loop.mp4, tmp/captions.ass
    
    Project updated: status=completed, r2_url, output_path, thumbnail_path
    
    PusherService → Frontend WebSocket
    └─ {status: "completed", r2_url: "...", progress: 100}
    
Frontend
    └─ Display video player + download button
```

---

## Quick Reference — What's Reused vs What's New

| Component | Status | Notes |
|---|---|---|
| `ProcessVideoJob` | **Reuse** | No changes |
| `TemplateProcessorFactory` | **Modify** | Add one case |
| `ProjectController` | **Modify** | Add template to validation |
| `R2UploadService` | **Reuse** | Identical |
| `PusherService` | **Reuse** | Identical |
| `WordLevelCaptionService` | **Reuse directly** | Called exactly as other templates — pass wordTimings[], storage relative path, and caption_template string |
| `VideoTranscriptionService` | **Reuse** | Used for BOTH modes (YT URL and upload) — transcription is always local |
| `PythonAIService` | **Reuse** | Add calls for new endpoints |
| `ClipSelectionService` | **NEW** | Multi-pass GPT grading algorithm |
| `YTVideoDownloadService` | **NEW** | RapidAPI download + poll only — no transcript API |
| `GameplayVideoService` | **NEW** | Library random + custom path |
| `VideoClipCutterService` | **NEW** | Wraps Python /cut-video-clip |
| `ShortComposerService` | **NEW** | Wraps Python /compose-short |
| `YTGameplayShortProcessor` | **NEW** | Main orchestrator |
| Python `/cut-video-clip` | **NEW** | FFmpeg fast seek cut |
| Python `/loop-video` | **NEW** | FFmpeg loop + scale |
| Python `/compose-short` | **NEW** | FFmpeg split-screen + ASS burn |

---

## Critical Implementation Notes

1. **Storage paths in Python must use the Docker volume path** (`/app/storage/...`), not the PHP path (`/var/www/storage/...`). Both map to the same physical directory. When passing paths from PHP to Python, convert: `str_replace('/var/www/', '/app/', $phpPath)`. **Check how existing services do this path translation and follow the same pattern exactly.**

2. **ASS subtitle timestamps must be relative to clip start**, not absolute video timestamps. Before building the `$wordTimings` array that you pass to `WordLevelCaptionService`, subtract `$finalStartSeconds` from every word's `start` and `end` value. Clamp to `max(0, ...)`. If you pass absolute timestamps, captions will be blank for the entire clip duration.

3. **Transcription is always local via Faster-Whisper — for both input modes.** Do not call any RapidAPI transcript endpoint. RapidAPI is used only to download the video file. After the file is on disk (`source_video.mp4`), pass it to `VideoTranscriptionService` the same way `YTAutomationShortProcessor` does. This applies to the YT URL path too.

4. **`WordLevelCaptionService` is called identically to how other templates use it.** Do not reinvent ASS generation inline. The service already handles all three caption templates (`modern_karaoke`, `classic_block`, `minimal_clean`) with proper styling, highlight effects, and timing. Study how an existing processor (e.g. `AIImageShortsProcessor`) calls it and follow the exact same call pattern.

5. **The multi-pass clip selection makes several OpenAI API calls** (up to 6 for Phase 1 + 1 for Phase 3 = 7 calls per project). This is acceptable and necessary for quality on long videos. Document the cost in code comments.

6. **RapidAPI polling can take 30–180 seconds** for video download initialization. The 10-second poll interval with 20 max attempts gives 200 seconds total. Ensure Laravel's queue `timeout` config is at least 600 seconds for the `video-processing` queue.

7. **The gameplay library path must exist before the template is usable.** Add a validation check in `GameplayVideoService` and surface a clear error message rather than a generic 500.

8. **FFmpeg `-c copy` for clip cutting is intentional** — it's 10–100× faster than re-encoding and produces identical quality. The re-encode happens only in the final compose step.

9. **The gameplay video has its audio stripped** (`-an` flag) in `/loop-video`. Final short audio comes exclusively from the main clip.

10. **Delete `source_video.mp4` immediately after the cut step succeeds**, not at the end. This file can be 200–800MB for a 480p download of a long video. Keeping it on disk until final cleanup wastes significant space, especially if multiple jobs run concurrently.
