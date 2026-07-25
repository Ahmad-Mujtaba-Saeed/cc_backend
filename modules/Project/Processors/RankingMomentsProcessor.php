<?php

namespace Modules\Project\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Contracts\YoutubeDownloaderInterface;
use Modules\Project\Services\ClipEditPlanService;
use Modules\Project\Services\ClipSelectionService;
use Modules\Project\Services\RapidApiTranscriptionService;
use Modules\Project\Services\VideoClipCutterService;
use Modules\Project\Services\VideoTranscriptionService;
use Modules\Project\Services\WordLevelCaptionService;
use Modules\Project\Services\YoutubeDownloaderFactory;
use App\Services\PythonAIService;
use Symfony\Component\Process\Process;

/**
 * RankingMomentsProcessor — "Top Moments Ranking" template.
 *
 * Turns one YouTube video (or upload) into a single 9:16 countdown-style
 * ranking short: the N best moments are auto-selected, ranked by score,
 * and played back-to-back building up to the #1 moment. Visual identity:
 *
 *   - Big bold user title pinned to the top, words cycling through vivid
 *     colours ("RANKING THE TOP 5 BEST MOMENTS")
 *   - A vertical rank rail on the LEFT (1..N stacked), every number its own
 *     colour; the current clip's number is enlarged, the rest are dimmed
 *   - A star rating out of 5 for each moment (★★★★½ 4.5/5) shown under the
 *     title for the whole clip
 *   - A rank-reveal "pop" at each clip start: giant #N + its stars punch in
 *     centre-screen for ~1.7s
 *   - One-word-at-a-time pop captions timed with Whisper word timestamps
 *     measured on each cut clip's own audio (always in sync)
 *
 * All overlays are hand-built ASS (libass is the proven text renderer in
 * this codebase) burned in a single ffmpeg pass together with the captions.
 *
 * Constraints: the finished short never exceeds 60 seconds — each moment is
 * trimmed to a per-moment budget of (60 − intro) / N — and every rank number
 * shows exactly ONE continuous event (no montages, no silence-skip stitching).
 *
 * Workflow:
 * 1. Validate input (title required)
 * 2. Download/prepare source video (admin-selected provider w/ key failover)
 * 3. Transcribe (RapidAPI for YouTube; local Faster-Whisper for uploads)
 * 4. Select the N best single-event moments, trim each to the per-moment
 *    budget, rank them 1..N by score, map ranks to stars
 * 5. Render: cut each contiguous moment → normalize 1080×1920 → per-clip
 *    Whisper word timings → intro card → concat (countdown order) →
 *    captions.ass + overlay.ass burn (hard-capped at 60s) → thumbnail
 * 6. Cleanup
 */
class RankingMomentsProcessor extends AbstractVideoProcessor
{
    private const MIN_CLIPS = 3;
    private const MAX_CLIPS = 5;
    private const DEFAULT_CLIPS = 5;

    private const CANVAS_W = 1080;
    private const CANVAS_H = 1920;
    private const INTRO_SECONDS = 1.6;
    private const POP_SECONDS = 1.7;

    /** Hard ceiling for the finished short. */
    private const MAX_TOTAL_SECONDS = 60.0;

    /** Star rating by rank (1 = best). Everything stays "top tier". */
    private const RANK_STARS = [1 => 5.0, 2 => 4.5, 3 => 4.0, 4 => 3.5, 5 => 3.0];

    /** Per-rank ASS colours (ABGR): gold, hot pink, cyan, green, purple. */
    private const RANK_COLORS = [
        1 => '&H0000D7FF&',
        2 => '&H006B3BFF&',
        3 => '&H00FFC83B&',
        4 => '&H0077E43B&',
        5 => '&H00FF6BB0&',
    ];

    /** Title words cycle through these (white, gold, cyan, pink). */
    private const TITLE_COLORS = ['&H00FFFFFF&', '&H0000D7FF&', '&H00FFC83B&', '&H006B3BFF&'];

    private const DIM_COLOR = '&H009A9A9A&';
    private const GOLD = '&H0000D7FF&';

    private YoutubeDownloaderInterface $downloadService;
    private VideoTranscriptionService $transcriptionService;
    private RapidApiTranscriptionService $rapidTranscriptionService;
    private ClipSelectionService $clipSelectionService;
    private ClipEditPlanService $editPlanService;
    private VideoClipCutterService $clipCutterService;
    private WordLevelCaptionService $captionService;
    private PythonAIService $pythonService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);

        $this->downloadService = YoutubeDownloaderFactory::make();
        $this->transcriptionService = new VideoTranscriptionService();
        $this->rapidTranscriptionService = new RapidApiTranscriptionService();
        $this->clipSelectionService = new ClipSelectionService();
        $this->editPlanService = new ClipEditPlanService();
        $this->clipCutterService = new VideoClipCutterService();
        $this->captionService = new WordLevelCaptionService();
        $this->pythonService = new PythonAIService();
    }

    public function process(): bool
    {
        try {
            Log::info('RankingMomentsProcessor starting', [
                'project_id' => $this->project->id,
                'input_mode' => $this->settings['input_mode'] ?? 'unknown',
                'clip_count' => $this->clipCount(),
                'title' => $this->rankingTitle(),
            ]);

            $this->logActivity('ranking_moments_processing_started', 'Ranking Moments processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting Ranking Moments processing');

            // STEP 1: Validate
            $validation = $this->runProcessingStep(
                'validate_input',
                fn() => $this->validateInput(),
                5,
                'Validating input',
                'Input validation completed',
                'Validation failed'
            );
            if ($validation === false) {
                return false;
            }

            // STEP 2: Prepare source video
            $sourceVideoPath = $this->runProcessingStep(
                'download_video',
                fn() => $this->prepareSourceVideo(),
                20,
                'Preparing source video',
                'Source video ready',
                'Failed to prepare source video'
            );
            if (!$sourceVideoPath) {
                return false;
            }

            // STEP 3: Transcribe (full transcript stays in memory only)
            $transcription = $this->runProcessingStep(
                'transcribe_video',
                fn() => $this->transcribeVideo($sourceVideoPath),
                40,
                'Transcribing video',
                'Video transcription completed',
                'Failed to transcribe video',
                persistOutput: false
            );
            if ($transcription === false) {
                return false;
            }
            if (!is_array($transcription) || empty($transcription['segments'])) {
                $transcription = $this->transcribeVideo($sourceVideoPath);
                if (!$transcription) {
                    $this->handleFailure('Failed to transcribe video', 'transcribe_video');
                    return false;
                }
            }

            // STEP 4: Select + rank the N best moments
            $rankedClips = $this->runProcessingStep(
                'select_and_rank',
                fn() => $this->selectAndRankClips($transcription),
                55,
                'Selecting and ranking best moments',
                'Moments ranked',
                'Failed to select moments',
                persistOutput: false
            );
            if ($rankedClips === false) {
                return false;
            }
            if (!is_array($rankedClips) || empty($rankedClips)) {
                $rankedClips = $this->selectAndRankClips($transcription);
                if (empty($rankedClips)) {
                    $this->handleFailure('Failed to select moments', 'select_and_rank');
                    return false;
                }
            }

            // STEP 5: Render the ranking video
            $output = $this->runProcessingStep(
                'render_ranking',
                fn() => $this->renderRanking($sourceVideoPath, $transcription, $rankedClips),
                95,
                'Rendering ranking video',
                'Ranking video rendered',
                'Failed to render ranking video'
            );
            if (!$output) {
                return false;
            }

            // STEP 6: Cleanup
            $this->runProcessingStep(
                'cleanup',
                fn() => $this->cleanupTempFiles(),
                98,
                'Cleaning up temporary files',
                'Cleanup completed',
                'Cleanup failed (non-fatal)'
            );

            $this->project->update([
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now(),
                'failed_step' => null,
            ]);
            $this->pusherService->sendStatus($this->project->id, 'completed', 'Ranking video completed successfully');

            Log::info('RankingMomentsProcessor completed successfully', [
                'project_id' => $this->project->id,
            ]);
            return true;

        } catch (\Exception $e) {
            Log::error('RankingMomentsProcessor failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);

            $this->cleanupTempFiles();
            $this->handleFailure('AI Video processing failed: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────── settings helpers ──

    private function clipCount(): int
    {
        $raw = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        $count = is_numeric($raw) ? (int) $raw : self::DEFAULT_CLIPS;

        return max(self::MIN_CLIPS, min(self::MAX_CLIPS, $count));
    }

    private function rankingTitle(): string
    {
        $title = trim((string) ($this->settings['ranking_title'] ?? ''));

        return $title !== '' ? mb_substr($title, 0, 60) : 'RANKING THE TOP MOMENTS';
    }

    /** countdown (worst→best, default) keeps viewers to the end. */
    private function countdownOrder(): bool
    {
        return ($this->settings['ranking_order'] ?? 'countdown') !== 'top_first';
    }

    private function introEnabled(): bool
    {
        return filter_var($this->settings['show_intro'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    private function captionTemplate(): string
    {
        $template = (string) ($this->settings['caption_template'] ?? 'single_word_pop');

        return in_array($template, ['single_word_pop', 'modern_karaoke', 'classic_block', 'minimal_clean'], true)
            ? $template
            : 'single_word_pop';
    }

    /**
     * Per-moment time budget: intro + N moments must fit MAX_TOTAL_SECONDS.
     * Top 5 with intro ≈ 11.7s per moment; Top 3 ≈ 19.5s.
     */
    private function momentBudgetSeconds(): float
    {
        $intro = $this->introEnabled() ? self::INTRO_SECONDS : 0.0;

        return max(6.0, (self::MAX_TOTAL_SECONDS - $intro) / $this->clipCount());
    }

    private function starsForRank(int $rank): float
    {
        return self::RANK_STARS[$rank] ?? 3.0;
    }

    private function colorForRank(int $rank): string
    {
        return self::RANK_COLORS[$rank] ?? '&H00FFFFFF&';
    }

    /** "★★★★½" / "★★★☆☆" style star strings. */
    private function starString(float $stars): string
    {
        $full = (int) floor($stars);
        $half = ($stars - $full) >= 0.5;

        return str_repeat('★', $full) . ($half ? '½' : '') . str_repeat('☆', 5 - $full - ($half ? 1 : 0));
    }

    // ───────────────────────────────────────────── STEP 1: validate ──

    protected function validateInput(): bool
    {
        $inputMode = $this->settings['input_mode'] ?? null;

        if ($inputMode === 'youtube_url') {
            $mainUrl = $this->settings['main_url'] ?? null;
            if (!$mainUrl) {
                throw new \Exception('YouTube URL is required');
            }
            try {
                $this->downloadService->extractVideoId($mainUrl);
            } catch (\Exception $e) {
                throw new \Exception('Invalid YouTube URL: ' . $e->getMessage());
            }
        } elseif ($inputMode === 'upload') {
            $videoPath = $this->project->video_path;
            if (!$videoPath || !Storage::disk('public')->exists($videoPath)) {
                throw new \Exception('Uploaded video file not found');
            }
        } else {
            throw new \Exception('Invalid input mode: ' . $inputMode);
        }

        if (trim((string) ($this->settings['ranking_title'] ?? '')) === '') {
            throw new \Exception('A ranking title is required (e.g. "Ranking The Top 5 Best Moments")');
        }

        $rawCount = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        if (!is_numeric($rawCount) || (int) $rawCount < self::MIN_CLIPS || (int) $rawCount > self::MAX_CLIPS) {
            throw new \Exception('Number of moments must be between ' . self::MIN_CLIPS . ' and ' . self::MAX_CLIPS);
        }

        return true;
    }

    // ──────────────────────────────────────────── STEP 2: source ──

    protected function prepareSourceVideo(): ?string
    {
        $inputMode = $this->settings['input_mode'];
        $sourceVideoPath = Storage::disk('public')->path("projects/{$this->project->id}/source_video.mp4");

        if ($inputMode === 'youtube_url') {
            $mainUrl = $this->settings['main_url'];

            $initResult = $this->downloadService->initDownload($mainUrl);
            $downloadUrl = $this->downloadService->pollUntilReady($initResult['progress_url']);
            $this->downloadService->streamToStorage($downloadUrl, $sourceVideoPath);

            $state = $this->project->processing_state ?? [];
            $state['source_video_path'] = $sourceVideoPath;
            $state['video_title'] = $initResult['title'];
            $this->project->update(['processing_state' => $state]);
        } else {
            $sourceVideoPath = Storage::disk('public')->path($this->project->video_path);
        }

        if (!file_exists($sourceVideoPath) || filesize($sourceVideoPath) === 0) {
            throw new \Exception('Source video is missing or empty');
        }

        return $sourceVideoPath;
    }

    protected function youtubeVideoId(): ?string
    {
        if (($this->settings['input_mode'] ?? null) !== 'youtube_url') {
            return null;
        }

        $url = $this->settings['main_url'] ?? null;
        if (!$url) {
            return null;
        }

        try {
            return $this->downloadService->extractVideoId($url);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ────────────────────────────────────────── STEP 3: transcribe ──

    protected function transcribeVideo(string $sourceVideoPath): ?array
    {
        $result = null;
        $videoId = $this->youtubeVideoId();

        if ($videoId !== null) {
            try {
                $result = $this->rapidTranscriptionService->transcribeByVideoId($videoId);
            } catch (\Exception $e) {
                Log::warning('[RANKING] RapidAPI transcription failed, falling back to local', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$result || empty($result['segments'])) {
            $result = $this->transcriptionService->transcribe($sourceVideoPath);
        }

        if (!$result || empty($result['segments'])) {
            return null;
        }

        // Persist only lightweight metadata — the full transcript bloats
        // processing_state (MySQL 1038, see yt_gameplay history).
        $state = $this->project->processing_state ?? [];
        $state['transcript_segment_count'] = count($result['segments']);
        $state['total_duration'] = $result['totalDuration'] ?? ($result['duration'] ?? 0);
        $this->project->update(['processing_state' => $state]);

        return $result;
    }

    // ────────────────────────────────────── STEP 4: select + rank ──

    /**
     * Select the N best clips and rank them by score: rank 1 = highest.
     * Each returned clip gains `rank` and `stars` keys.
     */
    protected function selectAndRankClips(array $transcription): ?array
    {
        $segments = $transcription['segments'] ?? [];
        $totalDuration = (float) ($transcription['totalDuration'] ?? ($transcription['duration'] ?? 0));

        if (empty($segments)) {
            throw new \Exception('No transcript segments available for moment selection');
        }

        $requested = $this->clipCount();

        // 'moments' mode: this template feeds on compilations / sports /
        // gameplay where commentary is sparse — the default story-driven
        // selection returns zero clips for that content.
        $clips = $this->clipSelectionService->findBestClips($segments, $totalDuration, $requested, 'moments');

        if (count($clips) < 2) {
            Log::warning('[RANKING] AI selection returned too few moments — using speech-density fallback', [
                'project_id' => $this->project->id,
                'ai_clips' => count($clips),
            ]);
            $clips = $this->fallbackMoments($segments, $totalDuration, $requested, $clips);
        }

        if (empty($clips)) {
            throw new \Exception('No suitable moments found in the video');
        }

        if (count($clips) < 2) {
            throw new \Exception('Only one strong moment was found — a ranking needs at least 2. Try a longer video.');
        }

        // Enforce the per-moment budget so intro + N moments never exceed 60s,
        // and so one rank never spans several events.
        $budget = $this->momentBudgetSeconds();
        foreach ($clips as &$clip) {
            $clip = $this->trimClipWindow($clip, $budget, $segments);
        }
        unset($clip);

        // Rank by score, best first.
        usort($clips, fn ($a, $b) => ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0));
        foreach ($clips as $i => &$clip) {
            $clip['rank'] = $i + 1;
            $clip['stars'] = $this->starsForRank($i + 1);
        }
        unset($clip);

        $state = $this->project->processing_state ?? [];
        $state['ranked_clips'] = array_map(fn ($c) => [
            'rank' => $c['rank'],
            'stars' => $c['stars'],
            'start' => round((float) $c['final_start_seconds'], 2),
            'end' => round((float) $c['final_end_seconds'], 2),
            'score' => (int) ($c['final_score'] ?? 0),
        ], $clips);
        $this->project->update(['processing_state' => $state]);

        Log::info('[RANKING] Moments ranked', [
            'project_id' => $this->project->id,
            'requested' => $requested,
            'found' => count($clips),
            'ranks' => $state['ranked_clips'],
        ]);

        return $clips;
    }

    /**
     * Deterministic last-resort moment picker: slide a 30s window across the
     * video and rank windows by speech density (commentary clusters around
     * the action in compilations). Tops up whatever the AI found so a
     * ranking can always be produced instead of hard-failing the project.
     *
     * @param array $aiClips Clips the AI selection did return (kept as-is).
     */
    private function fallbackMoments(array $segments, float $totalDuration, int $count, array $aiClips): array
    {
        // Window = the per-moment budget: a wider window would show several
        // events under one rank and blow the 60s ceiling.
        $windowLen = $this->momentBudgetSeconds();
        $step = 3.0;

        if ($totalDuration < $windowLen) {
            return $aiClips;
        }

        // Score every window position by how many transcript words it contains.
        $candidates = [];
        for ($start = 0.0; $start + $windowLen <= $totalDuration + 0.01; $start += $step) {
            $end = min($start + $windowLen, $totalDuration);
            $words = 0;
            $texts = [];

            foreach ($segments as $seg) {
                $segStart = (float) ($seg['start'] ?? 0);
                $segEnd = (float) ($seg['end'] ?? 0);
                $text = trim((string) ($seg['text'] ?? ''));
                if ($text === '' || $segEnd <= $start || $segStart >= $end) {
                    continue;
                }
                $words += count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
                $texts[] = $text;
            }

            if ($words > 0) {
                $candidates[] = ['start' => $start, 'end' => $end, 'words' => $words, 'text' => implode(' ', $texts)];
            }
        }

        usort($candidates, fn ($a, $b) => $b['words'] <=> $a['words']);

        // Greedily take the densest windows that don't overlap each other or
        // the AI picks. Fallback scores sit below typical AI scores so real
        // AI picks keep outranking heuristic ones.
        $occupied = array_map(fn ($c) => [
            (float) $c['final_start_seconds'],
            (float) $c['final_end_seconds'],
        ], $aiClips);

        $clips = $aiClips;
        foreach ($candidates as $candidate) {
            if (count($clips) >= $count) {
                break;
            }

            foreach ($occupied as [$busyStart, $busyEnd]) {
                if ($candidate['start'] < $busyEnd && $candidate['end'] > $busyStart) {
                    continue 2;
                }
            }

            $occupied[] = [$candidate['start'], $candidate['end']];
            $clips[] = [
                'final_start_seconds' => $candidate['start'],
                'final_end_seconds' => $candidate['end'],
                'subtitle' => mb_substr($candidate['text'], 0, 300),
                'highlight_words' => [],
                'final_score' => max(5, 55 - 5 * (count($clips) - count($aiClips))),
                'reason' => 'Speech-density fallback selection',
            ];
        }

        Log::info('[RANKING] Fallback moment selection complete', [
            'project_id' => $this->project->id,
            'ai_clips' => count($aiClips),
            'total_clips' => count($clips),
        ]);

        return $clips;
    }

    /**
     * Trim one moment's window down to the per-moment budget. Keeps the
     * budget-length sub-window with the densest speech — commentary clusters
     * around the action. Ties (and windows with no speech at all) resolve to
     * the latest position, where the prompt places the peak of the moment.
     */
    private function trimClipWindow(array $clip, float $budget, array $segments): array
    {
        $start = (float) $clip['final_start_seconds'];
        $end = (float) $clip['final_end_seconds'];

        if ($end - $start <= $budget + 0.01) {
            return $clip;
        }

        $local = array_values(array_filter($segments, fn ($seg) =>
            (float) ($seg['end'] ?? 0) > $start
            && (float) ($seg['start'] ?? 0) < $end
            && trim((string) ($seg['text'] ?? '')) !== ''));

        // Walk from the tail backwards: ties (and fully silent windows) keep
        // the latest position, where the peak of the moment resolves.
        $bestStart = $end - $budget;
        $bestScore = -1.0;
        for ($s = $end - $budget; $s >= $start - 0.01; $s -= 0.5) {
            $score = 0.0;
            foreach ($local as $seg) {
                $segStart = (float) $seg['start'];
                $segEnd = (float) $seg['end'];
                $overlap = min($segEnd, $s + $budget) - max($segStart, $s);
                if ($overlap <= 0) {
                    continue;
                }
                // Words weighted by how much of the segment the window really
                // contains — grazing a burst must not tie with covering it.
                $words = count(preg_split('/\s+/', trim((string) $seg['text']), -1, PREG_SPLIT_NO_EMPTY));
                $score += $words * ($overlap / max(0.001, $segEnd - $segStart));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStart = $s;
            }
        }

        Log::info('[RANKING] Trimmed moment to per-moment budget', [
            'project_id' => $this->project->id,
            'from' => [round($start, 2), round($end, 2)],
            'to' => [round($bestStart, 2), round($bestStart + $budget, 2)],
            'budget' => round($budget, 2),
        ]);

        $clip['final_start_seconds'] = round($bestStart, 2);
        $clip['final_end_seconds'] = round($bestStart + $budget, 2);

        return $clip;
    }

    // ─────────────────────────────────────────── STEP 5: render ──

    protected function renderRanking(string $sourceVideoPath, array $transcription, array $rankedClips): ?array
    {
        $segments = $transcription['segments'] ?? [];
        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        @mkdir($tmpDir, 0755, true);

        $clipTotal = count($rankedClips);
        $progressBase = (float) $this->project->progress;
        $progressSpan = max(0, 95 - $progressBase);
        $unitsTotal = $clipTotal * 2 + 2; // cut + prepare per clip, concat, burn
        $unitsDone = 0;
        $tick = function (string $message) use (&$unitsDone, $unitsTotal, $progressBase, $progressSpan) {
            $unitsDone++;
            $progress = (int) round($progressBase + $progressSpan * ($unitsDone / max(1, $unitsTotal)));
            $this->updateProgress(min(95, $progress));
            $this->pusherService->sendProgress($this->project->id, min(95, $progress), $message);
        };

        // ── Phase A: edit-plan + cut every ranked clip from the source ──
        $cuts = [];
        foreach ($rankedClips as $clip) {
            $rank = (int) $clip['rank'];
            try {
                $plan = $this->momentEditPlan(
                    (float) $clip['final_start_seconds'],
                    (float) $clip['final_end_seconds']
                );

                $cutPath = $tmpDir . "/cut_rank{$rank}.mp4";
                $this->clipCutterService->cutSegments($sourceVideoPath, $plan['ranges'], $cutPath, $this->project->id);

                $cuts[] = ['clip' => $clip, 'plan' => $plan, 'path' => $cutPath];
            } catch (\Exception $e) {
                Log::error('[RANKING] Clip cut failed — skipping this moment', [
                    'project_id' => $this->project->id,
                    'rank' => $rank,
                    'error' => $e->getMessage(),
                ]);
            }
            $tick("Cut moment #{$rank}");
        }

        if (count($cuts) < 2) {
            throw new \Exception('Could not cut enough moments for a ranking (need at least 2)');
        }

        // Failures leave rank gaps — re-rank the survivors contiguously so the
        // rail and stars stay coherent (score order was preserved).
        foreach (array_values($cuts) as $i => &$cut) {
            $cut['clip']['rank'] = $i + 1;
            $cut['clip']['stars'] = $this->starsForRank($i + 1);
        }
        unset($cut);

        $this->deleteSourceVideoEarly($sourceVideoPath);

        // ── Phase B: normalize each cut to canvas + Whisper word timings ──
        $prepared = [];
        foreach ($cuts as $cut) {
            $rank = (int) $cut['clip']['rank'];
            try {
                $normPath = Storage::disk('public')->path("projects/{$this->project->id}/tmp") . "/norm_rank{$rank}.mp4";
                $this->normalizeClip($cut['path'], $normPath);
                $duration = (float) $this->ffprobe->format($normPath)->get('duration');
                if ($duration <= 0.5) {
                    throw new \Exception('Normalized clip has no duration');
                }

                $prepared[] = [
                    'clip' => $cut['clip'],
                    'plan' => $cut['plan'],
                    'path' => $normPath,
                    'duration' => $duration,
                    'words' => $this->clipWordTimings($normPath, $cut, $segments, $duration),
                ];
            } catch (\Exception $e) {
                Log::error('[RANKING] Clip preparation failed — skipping this moment', [
                    'project_id' => $this->project->id,
                    'rank' => $rank,
                    'error' => $e->getMessage(),
                ]);
            }
            $tick("Prepared moment #{$rank}");
        }

        if (count($prepared) < 2) {
            throw new \Exception('Could not prepare enough moments for a ranking (need at least 2)');
        }

        // ── Timeline: countdown plays worst → best ──
        usort($prepared, $this->countdownOrder()
            ? fn ($a, $b) => $b['clip']['rank'] <=> $a['clip']['rank']
            : fn ($a, $b) => $a['clip']['rank'] <=> $b['clip']['rank']);

        $introSeconds = $this->introEnabled() ? self::INTRO_SECONDS : 0.0;
        $cursor = $introSeconds;
        foreach ($prepared as &$entry) {
            $entry['offset'] = $cursor;
            $cursor += $entry['duration'];
        }
        unset($entry);

        // Per-moment budgets keep intro + clips under the ceiling; the clamp
        // (and -t on the burn below) only guards against encoder drift.
        $totalDuration = min($cursor, self::MAX_TOTAL_SECONDS);

        // ── Assemble: intro card + clips, uniform re-encode ──
        $assembledPath = $this->assembleTimeline($prepared, $introSeconds);
        $tick('Timeline assembled');

        // ── Captions + overlay, burned in one pass ──
        $filters = [];

        $captionsAbs = $this->buildCaptions($prepared);
        if ($captionsAbs !== null) {
            $filters[] = "ass='" . str_replace(["\\", "'"], ['/', "\\'"], $captionsAbs) . "'";
        }

        $overlayAbs = $this->buildOverlayAss($prepared, $introSeconds, count($prepared));
        $filters[] = "ass='" . str_replace(["\\", "'"], ['/', "\\'"], $overlayAbs) . "'";

        $outputRelative = "projects/{$this->project->id}/output.mp4";
        $outputAbs = Storage::disk('public')->path($outputRelative);

        $burn = new Process([
            'ffmpeg', '-y',
            '-i', $assembledPath,
            '-vf', implode(',', $filters),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'copy',
            '-t', number_format(self::MAX_TOTAL_SECONDS, 3, '.', ''),
            $outputAbs,
        ]);
        $burn->setTimeout(3600);
        $burn->run();

        if (!$burn->isSuccessful() || !file_exists($outputAbs) || filesize($outputAbs) === 0) {
            throw new \Exception('Overlay burn failed: ' . substr($burn->getErrorOutput(), -800));
        }
        $tick('Overlays burned');

        // Optional Pixabay music bed under the moments (default: none).
        $this->applyBackgroundMusic($outputAbs);

        $thumbnailRelative = $this->generateRankingThumbnail($outputAbs);

        $state = $this->project->processing_state ?? [];
        $state['ranking'] = array_map(fn ($p) => [
            'rank' => (int) $p['clip']['rank'],
            'stars' => (float) $p['clip']['stars'],
            'duration' => round($p['duration'], 2),
            'offset' => round($p['offset'], 2),
            'score' => (int) ($p['clip']['final_score'] ?? 0),
        ], $prepared);

        $update = [
            'processing_state' => $state,
            'output_path' => $outputRelative,
            'duration' => round($totalDuration, 2),
        ];
        if ($thumbnailRelative) {
            $update['thumbnail_path'] = $thumbnailRelative;
        }
        $this->project->update($update);

        Log::info('[RANKING] Render complete', [
            'project_id' => $this->project->id,
            'moments' => count($prepared),
            'total_duration' => round($totalDuration, 2),
        ]);

        return ['path' => $outputRelative, 'duration' => round($totalDuration, 2)];
    }

    /**
     * One rank number = one continuous moment. Silence-skipping is deliberately
     * NOT used here: stitching non-adjacent pieces of the source reads as
     * several different moments under a single rank, and with the tight
     * per-moment budget there is nothing worth trimming anyway.
     */
    private function momentEditPlan(float $clipStart, float $clipEnd): array
    {
        return [
            'ranges' => [['start' => $clipStart, 'end' => $clipEnd]],
            'edited_duration' => round(max(0.001, $clipEnd - $clipStart), 3),
            'removed_seconds' => 0.0,
            'is_edited' => false,
        ];
    }

    /**
     * Scale/crop a cut clip to the 1080×1920 canvas with uniform encode
     * parameters so concat is seamless. Falls back to silent audio when the
     * source has no audio stream.
     */
    private function normalizeClip(string $inputPath, string $outputPath): void
    {
        $w = self::CANVAS_W;
        $h = self::CANVAS_H;
        $videoChain = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},setsar=1,fps=30[v]";

        $cmd = [
            'ffmpeg', '-y',
            '-i', $inputPath,
            '-filter_complex', $videoChain . ';[0:a]aresample=44100[a]',
            '-map', '[v]', '-map', '[a]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            $outputPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(1800);
        $process->run();

        if ($process->isSuccessful() && file_exists($outputPath) && filesize($outputPath) > 0) {
            return;
        }

        // Retry assuming the input has no usable audio stream.
        $fallback = new Process([
            'ffmpeg', '-y',
            '-i', $inputPath,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-filter_complex', $videoChain,
            '-map', '[v]', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            '-shortest',
            $outputPath,
        ]);
        $fallback->setTimeout(1800);
        $fallback->run();

        if (!$fallback->isSuccessful() || !file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \Exception('Clip normalization failed: ' . substr($fallback->getErrorOutput(), -500));
        }
    }

    /**
     * Word timings for one prepared clip, on the clip's own timeline.
     *
     * Preferred: Whisper word timestamps measured on the normalized clip's
     * audio (exact sync). Fallback: source-transcript words remapped through
     * the edit plan (approximate, but render never dies for captions).
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    private function clipWordTimings(string $normPath, array $cut, array $segments, float $duration): array
    {
        try {
            $result = $this->transcriptionService->transcribe($normPath, 'en', true);
            $timings = [];

            foreach ($result['segments'] ?? [] as $segment) {
                foreach ($segment['words'] ?? [] as $w) {
                    $word = trim((string) ($w['word'] ?? ''));
                    if ($word === '') {
                        continue;
                    }
                    $start = max(0.0, min((float) ($w['start'] ?? 0), $duration));
                    $end = max($start + 0.05, min((float) ($w['end'] ?? 0), $duration));
                    $timings[] = ['word' => $word, 'start' => $start, 'end' => $end];
                }
            }

            if (!empty($timings)) {
                return $timings;
            }
        } catch (\Exception $e) {
            Log::warning('[RANKING] Per-clip Whisper timings failed — using transcript remap', [
                'project_id' => $this->project->id,
                'rank' => $cut['clip']['rank'] ?? 0,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->remapTranscriptWords($segments, $cut['clip'], $cut['plan'], $duration);
    }

    /**
     * Fallback word timings: spread each source-transcript segment's words
     * evenly, then map through the edit plan onto the edited timeline.
     */
    private function remapTranscriptWords(array $segments, array $clip, array $plan, float $duration): array
    {
        $clipStart = (float) $clip['final_start_seconds'];
        $clipEnd = (float) $clip['final_end_seconds'];
        $ranges = $plan['ranges'];
        $timings = [];

        foreach ($segments as $segment) {
            $segStart = (float) ($segment['start'] ?? 0);
            $segEnd = (float) ($segment['end'] ?? 0);
            if ($segEnd <= $clipStart || $segStart >= $clipEnd) {
                continue;
            }

            $words = array_values(array_filter(explode(' ', trim((string) ($segment['text'] ?? ''))), fn ($w) => trim($w) !== ''));
            if (empty($words)) {
                continue;
            }
            $perWord = ($segEnd - $segStart) / count($words);

            foreach ($words as $i => $word) {
                $editedStart = $this->editPlanService->toEditedTime($segStart + $i * $perWord, $ranges);
                $editedEnd = $this->editPlanService->toEditedTime($segStart + ($i + 1) * $perWord, $ranges);

                if ($editedEnd - $editedStart < 0.05) {
                    continue; // word fell inside a removed gap
                }

                $editedStart = max(0.0, min($editedStart, $duration));
                $editedEnd = max($editedStart + 0.05, min($editedEnd, $duration));
                $timings[] = ['word' => trim($word), 'start' => $editedStart, 'end' => $editedEnd];
            }
        }

        return $timings;
    }

    /**
     * Intro card + clips → one uniformly encoded file (concat demuxer).
     */
    private function assembleTimeline(array $prepared, float $introSeconds): string
    {
        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        $listContent = '';

        if ($introSeconds > 0) {
            $introPath = $tmpDir . '/intro.mp4';
            $intro = new Process([
                'ffmpeg', '-y',
                '-f', 'lavfi', '-i', 'color=c=0x0E0D12:s=' . self::CANVAS_W . 'x' . self::CANVAS_H . ':d=' . $introSeconds . ':r=30',
                '-f', 'lavfi', '-t', (string) $introSeconds, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
                '-map', '0:v', '-map', '1:a',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
                '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
                $introPath,
            ]);
            $intro->setTimeout(300);
            $intro->run();

            if (!$intro->isSuccessful() || !file_exists($introPath) || filesize($introPath) === 0) {
                throw new \Exception('Intro card rendering failed: ' . substr($intro->getErrorOutput(), -500));
            }
            $listContent .= "file '" . str_replace("'", "'\\''", $introPath) . "'\n";
        }

        foreach ($prepared as $entry) {
            $listContent .= "file '" . str_replace("'", "'\\''", $entry['path']) . "'\n";
        }

        $concatList = $tmpDir . '/concat.txt';
        file_put_contents($concatList, $listContent);

        $assembledPath = $tmpDir . '/assembled.mp4';
        $concat = new Process([
            'ffmpeg', '-y',
            '-f', 'concat', '-safe', '0', '-i', $concatList,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            $assembledPath,
        ]);
        $concat->setTimeout(3600);
        $concat->run();

        if (!$concat->isSuccessful() || !file_exists($assembledPath) || filesize($assembledPath) === 0) {
            throw new \Exception('Concat failed: ' . substr($concat->getErrorOutput(), -800));
        }

        return $assembledPath;
    }

    /**
     * Global captions .ass from per-clip word timings shifted to the final
     * timeline. Returns null when no words exist anywhere (captions skipped,
     * render continues).
     */
    private function buildCaptions(array $prepared): ?string
    {
        $global = [];
        foreach ($prepared as $entry) {
            foreach ($entry['words'] as $timing) {
                $global[] = [
                    'word' => $timing['word'],
                    'start' => round($entry['offset'] + $timing['start'], 3),
                    'end' => round(min($entry['offset'] + $timing['end'], $entry['offset'] + $entry['duration']), 3),
                ];
            }
        }

        if (empty($global)) {
            Log::error('[RANKING] No word timings for any moment — rendering without captions', [
                'project_id' => $this->project->id,
            ]);
            return null;
        }

        $captionsRelative = "projects/{$this->project->id}/tmp/captions.ass";
        $this->captionService->generateKaraokeCaptions($global, $captionsRelative, $this->captionTemplate());

        $captionsAbs = Storage::disk('public')->path($captionsRelative);
        if (!file_exists($captionsAbs) || filesize($captionsAbs) === 0) {
            throw new \Exception('Caption file was not generated');
        }

        return $captionsAbs;
    }

    // ───────────────────────────────────────── overlay design ──

    /**
     * The template's visual identity, hand-built as one ASS file:
     * intro title, persistent colourful top title, per-clip star rating,
     * the left rank rail, and the rank-reveal pop at each clip start.
     */
    private function buildOverlayAss(array $prepared, float $introSeconds, int $railCount): string
    {
        $w = self::CANVAS_W;
        $h = self::CANVAS_H;

        $ass = "[Script Info]\n"
            . "ScriptType: v4.00+\n"
            . "PlayResX: {$w}\n"
            . "PlayResY: {$h}\n"
            . "WrapStyle: 0\n"
            . "ScaledBorderAndShadow: yes\n\n"
            . "[V4+ Styles]\n"
            . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            . "Style: IntroTitle,Arial,110,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,7,3,5,70,70,60,1\n"
            . "Style: IntroSub,Arial,64,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,4,0,1,4,2,5,60,60,60,1\n"
            . "Style: Title,Arial,74,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,6,3,8,70,70,64,1\n"
            . "Style: Stars,Arial,58,&H0000D7FF,&H0000D7FF,&H00000000,&H80000000,-1,0,0,0,100,100,2,0,1,4,2,8,60,60,64,1\n"
            . "Style: Rail,Arial,64,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,4,2,4,0,0,0,1\n"
            . "Style: PopNum,Arial,190,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,8,4,5,0,0,0,1\n"
            . "Style: PopStars,Arial,78,&H0000D7FF,&H0000D7FF,&H00000000,&H80000000,-1,0,0,0,100,100,3,0,1,5,2,5,0,0,0,1\n\n"
            . "[Events]\n"
            . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $title = $this->colorizedTitle();
        $end = end($prepared);
        $videoEnd = $end['offset'] + $end['duration'];

        // 1) Intro card: big title + colourful countdown numbers strip.
        if ($introSeconds > 0) {
            $ass .= 'Dialogue: 1,' . $this->assTime(0.0) . ',' . $this->assTime($introSeconds)
                . ',IntroTitle,,0,0,0,,{\\fad(150,120)\\fscx92\\fscy92\\t(0,300,\\fscx100\\fscy100)}' . $title . "\n";

            $numbers = [];
            for ($r = $railCount; $r >= 1; $r--) {
                $numbers[] = '{\\c' . $this->colorForRank($r) . '}' . $r;
            }
            $ass .= 'Dialogue: 1,' . $this->assTime(0.25) . ',' . $this->assTime($introSeconds)
                . ',IntroSub,,0,0,0,,{\\fad(150,120)\\pos(540,1210)}' . implode('{\\c&H00FFFFFF&} · ', $numbers) . "\n";
        }

        // 2) Persistent top title across every clip.
        $ass .= 'Dialogue: 1,' . $this->assTime($introSeconds) . ',' . $this->assTime($videoEnd)
            . ',Title,,0,0,0,,' . $title . "\n";

        foreach ($prepared as $entry) {
            $rank = (int) $entry['clip']['rank'];
            $stars = (float) $entry['clip']['stars'];
            $start = (float) $entry['offset'];
            $endT = $start + (float) $entry['duration'];
            $color = $this->colorForRank($rank);
            $starText = $this->starString($stars) . '  ' . number_format($stars, 1) . '/5';

            // 3) Star rating under the title for the whole clip.
            $ass .= 'Dialogue: 1,' . $this->assTime($start) . ',' . $this->assTime($endT)
                . ',Stars,,0,0,0,,{\\fad(120,0)\\pos(540,206)}{\\c' . self::GOLD . '}#' . $rank
                . '  ' . $starText . "\n";

            // 4) Left rank rail: 1..N stacked, current enlarged in its colour,
            //    the rest dimmed. Rebuilt per clip so the highlight moves.
            $railSpacing = 118;
            $railTop = 900 - (int) round($railSpacing * ($railCount - 1) / 2);
            for ($r = 1; $r <= $railCount; $r++) {
                $y = $railTop + ($r - 1) * $railSpacing;
                if ($r === $rank) {
                    $tag = '{\\an4\\pos(58,' . $y . ')\\fs96\\c' . $this->colorForRank($r) . '\\bord5}';
                } else {
                    $tag = '{\\an4\\pos(58,' . $y . ')\\fs60\\c' . self::DIM_COLOR . '\\alpha&H50&\\bord3}';
                }
                $ass .= 'Dialogue: 1,' . $this->assTime($start) . ',' . $this->assTime($endT)
                    . ',Rail,,0,0,0,,' . $tag . $r . "\n";
            }

            // 5) Rank-reveal pop at clip start: giant #N + stars punch in.
            $popEnd = min($endT, $start + self::POP_SECONDS);
            $ass .= 'Dialogue: 2,' . $this->assTime($start) . ',' . $this->assTime($popEnd)
                . ',PopNum,,0,0,0,,{\\pos(540,830)\\c' . $color
                . '\\fad(130,220)\\fscx55\\fscy55\\t(0,160,\\fscx112\\fscy112)\\t(160,260,\\fscx100\\fscy100)}#'
                . $rank . "\n";
            $ass .= 'Dialogue: 2,' . $this->assTime($start + 0.15) . ',' . $this->assTime($popEnd)
                . ',PopStars,,0,0,0,,{\\pos(540,990)\\fad(140,220)}' . $starText . "\n";
        }

        $overlayAbs = Storage::disk('public')->path("projects/{$this->project->id}/tmp") . '/overlay.ass';
        file_put_contents($overlayAbs, $ass);

        return $overlayAbs;
    }

    /**
     * The user's title, uppercased, each word cycling through the palette.
     */
    private function colorizedTitle(): string
    {
        $clean = $this->sanitizeAssText(mb_strtoupper($this->rankingTitle()));
        $words = array_values(array_filter(explode(' ', $clean), fn ($w) => $w !== ''));

        $parts = [];
        foreach ($words as $i => $word) {
            $parts[] = '{\\c' . self::TITLE_COLORS[$i % count(self::TITLE_COLORS)] . '}' . $word;
        }

        return implode(' ', $parts);
    }

    private function sanitizeAssText(string $text): string
    {
        return trim(str_replace(['{', '}', '\\', "\n", "\r"], ' ', $text));
    }

    private function assTime(float $seconds): string
    {
        $totalCs = (int) round(max(0, $seconds) * 100);

        return sprintf(
            '%d:%02d:%02d.%02d',
            intdiv($totalCs, 360000),
            intdiv($totalCs % 360000, 6000),
            intdiv($totalCs % 6000, 100),
            $totalCs % 100
        );
    }

    // ───────────────────────────────────────────── misc helpers ──

    protected function deleteSourceVideoEarly(string $sourceVideoPath): void
    {
        if (($this->settings['input_mode'] ?? null) !== 'youtube_url') {
            return;
        }

        if (file_exists($sourceVideoPath)) {
            @unlink($sourceVideoPath);
            Log::info('[RANKING] Deleted large source video early', [
                'project_id' => $this->project->id,
            ]);
        }
    }

    /** Thumbnail at 0.8s — the colourful intro title card. Non-fatal. */
    private function generateRankingThumbnail(string $videoAbsolutePath): ?string
    {
        try {
            $relative = "projects/{$this->project->id}/thumbnail.jpg";

            $response = $this->pythonService->makeRequest('POST', '/generate-thumbnail', [
                'video_path' => $videoAbsolutePath,
                'time_seconds' => $this->introEnabled() ? 0.8 : 1.0,
                'output_path' => Storage::disk('public')->path($relative),
                'width' => self::CANVAS_W,
                'height' => self::CANVAS_H,
                'project_id' => $this->project->id,
            ]);

            return !empty($response['success']) ? $relative : null;
        } catch (\Exception $e) {
            Log::warning('[RANKING] Thumbnail generation failed (non-fatal)', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function cleanupTempFiles(): bool
    {
        try {
            $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");

            if (is_dir($tmpDir)) {
                foreach (glob($tmpDir . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($tmpDir);
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('[RANKING] Temp cleanup failed (non-fatal)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ──────────────────────────────────────── template contract ──

    public function validate(): array
    {
        $errors = [];

        $inputMode = $this->settings['input_mode'] ?? null;
        if (!$inputMode || !in_array($inputMode, ['youtube_url', 'upload'], true)) {
            $errors[] = 'Input mode must be either youtube_url or upload';
        }

        if ($inputMode === 'youtube_url' && empty($this->settings['main_url'])) {
            $errors[] = 'YouTube URL is required when using youtube_url mode';
        }
        if ($inputMode === 'upload' && !$this->inputPath) {
            $errors[] = 'Video file must be uploaded for upload mode';
        }

        if (trim((string) ($this->settings['ranking_title'] ?? '')) === '') {
            $errors[] = 'Ranking title is required';
        }

        $rawCount = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        if (!is_numeric($rawCount) || (int) $rawCount < self::MIN_CLIPS || (int) $rawCount > self::MAX_CLIPS) {
            $errors[] = 'Number of moments must be between ' . self::MIN_CLIPS . ' and ' . self::MAX_CLIPS;
        }

        return $errors;
    }

    protected function getTemplateConfig(): array
    {
        return self::getStaticTemplateConfig();
    }

    protected function getProcessingSteps(): array
    {
        return [
            'validate_input' => 5,
            'download_video' => 15,
            'transcribe_video' => 20,
            'select_and_rank' => 15,
            'render_ranking' => 40,
            'cleanup' => 5,
        ];
    }

    public static function getStaticTemplateConfig(): array
    {
        return [
            'name' => 'Top Moments Ranking',
            'description' => 'Turn one video into a countdown-style ranking short (max 60s) — the best single moments are auto-ranked with star ratings, a colorful rank rail, a big bold title, and punchy one-word captions.',
            'requires_upload' => false,
            'min_duration' => 60,
            'max_duration' => 7200,
            'max_file_size_mb' => 500,
            'supported_formats' => ['mp4', 'mov', 'webm', 'avi', 'mkv'],
            'output_formats' => ['mp4'],
            'settings_schema' => [
                'input_mode' => [
                    'type' => 'radio',
                    'label' => 'Source Video',
                    'options' => [
                        'youtube_url' => 'YouTube URL',
                        'upload' => 'Upload Video File',
                    ],
                    'default' => 'youtube_url',
                ],
                'main_url' => [
                    'type' => 'text',
                    'label' => 'YouTube URL',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'required' => true,
                    'visible_when' => ['input_mode' => 'youtube_url'],
                ],
                'video_file' => [
                    'type' => 'file',
                    'label' => 'Upload Video',
                    'accept' => 'video/*',
                    'max_size' => 536870912,
                    'required' => true,
                    'visible_when' => ['input_mode' => 'upload'],
                ],
                'ranking_title' => [
                    'type' => 'text',
                    'label' => 'Ranking Title',
                    'placeholder' => 'Ranking The Top 5 Best Moments',
                    'required' => true,
                ],
                'clip_count' => [
                    'type' => 'select',
                    'label' => 'Number of Moments',
                    'options' => [
                        '3' => 'Top 3',
                        '4' => 'Top 4',
                        '5' => 'Top 5 (recommended)',
                    ],
                    'default' => '5',
                ],
                'ranking_order' => [
                    'type' => 'select',
                    'label' => 'Play Order',
                    'options' => [
                        'countdown' => 'Countdown — save #1 for last (recommended)',
                        'top_first' => 'Best first — start with #1',
                    ],
                    'default' => 'countdown',
                ],
                'show_intro' => [
                    'type' => 'checkbox',
                    'label' => 'Show title intro card',
                    'default' => true,
                ],
                'caption_template' => [
                    'type' => 'select',
                    'label' => 'Caption Style',
                    'options' => [
                        'single_word_pop' => 'One Word Pop (recommended)',
                        'modern_karaoke' => 'Modern Karaoke',
                        'classic_block' => 'Classic Block',
                        'minimal_clean' => 'Minimal Clean',
                    ],
                    'default' => 'single_word_pop',
                ],
            ],
            'processing_steps' => [
                'validate_input' => 5,
                'download_video' => 15,
                'transcribe_video' => 20,
                'select_and_rank' => 15,
                'render_ranking' => 40,
                'cleanup' => 5,
            ],
        ];
    }
}
