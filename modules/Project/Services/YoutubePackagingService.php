<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ExplainerTimingMap;

/**
 * YoutubePackagingService — the paste-ready upload kit, written next to the
 * MP4 as `<video>.youtube.txt`: a description paragraph, the chapter list
 * (real composition-clock timestamps from ExplainerTimingMap — the same
 * clock the SRT rides), and a hashtag line.
 *
 * Chapters are DERIVED, never invented: chapter_cover scenes and scene
 * headings mark the beats, and YouTube's own rules are enforced — the first
 * chapter pinned to 0:00, every chapter ≥10s, and the whole block omitted
 * below 3 chapters (YouTube would ignore it anyway). The description and
 * hashtags come from one cheap LLM read of the narration; any failure falls
 * back to a deterministic assembly (the hook's opening + title tokens), so
 * the file always ships. Best-effort throughout; never blocks the render.
 */
class YoutubePackagingService
{
    private const MIN_CHAPTER_S = 10.0;
    private const MIN_CHAPTERS = 3;
    private const MAX_CHAPTERS = 10;

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    /**
     * @return string|null Relative storage path of the .youtube.txt, or null.
     */
    public function export(Project $project, string $outputRelativeMp4): ?string
    {
        try {
            $settings = $project->settings ?? [];
            $scenes = $project->explainerScenes()->orderBy('order')->get()
                ->map(fn ($s) => [
                    'scene_id' => (string) $s->scene_id,
                    'duration_seconds' => (float) $s->duration_seconds,
                    'transition' => (string) $s->transition,
                    'layout_template' => (string) $s->layout_template,
                    'narration' => (string) $s->narration,
                    'slots' => $s->slots ?? [],
                ])->all();
            if ($scenes === []) {
                return null;
            }

            $windows = ExplainerTimingMap::sceneWindows(
                array_map(fn ($s) => [
                    'scene_id' => $s['scene_id'],
                    'duration_seconds' => $s['duration_seconds'],
                    'transition' => $s['transition'],
                ], $scenes),
                (string) ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode()),
                $settings['chapter_plan'] ?? null
            );

            $title = trim((string) $project->title);
            $chapters = self::chapterLines($scenes, $windows, $settings['chapter_plan'] ?? null);

            $copy = null;
            try {
                $copy = $this->requestCopy($title, $scenes);
            } catch (\Throwable $e) {
                Log::info('YoutubePackagingService: copy call unavailable, using fallback', ['error' => $e->getMessage()]);
            }
            $copy ??= self::fallbackCopy($title, $scenes);

            $tags = $copy['hashtags'];
            if (($project->aspect_ratio ?? '') === '9:16' && !in_array('shorts', $tags, true)) {
                $tags[] = 'shorts';
            }
            $hashtagLine = implode(' ', array_map(fn ($t) => '#' . $t, $tags));

            $out = "TITLE\n=====\n{$title}\n\n";
            $out .= "DESCRIPTION — paste everything below into YouTube\n";
            $out .= "==================================================\n";
            $out .= $copy['description'] . "\n";
            if ($chapters !== []) {
                $out .= "\nChapters:\n" . implode("\n", $chapters) . "\n";
            }
            $out .= "\n" . $hashtagLine . "\n";

            $relative = (preg_replace('/\.mp4$/i', '', $outputRelativeMp4) ?: $outputRelativeMp4) . '.youtube.txt';
            Storage::disk('public')->put($relative, $out);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('YoutubePackagingService: export failed (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Chapter lines ("0:00 The Setup") from the storyboard's own beats.
     * Public + pure for the test suite.
     *
     * @param array $scenes  rows: scene_id, layout_template, slots
     * @param array $windows scene_id => {start, end} seconds
     * @return string[] [] when a legal YouTube chapter list cannot be built
     */
    public static function chapterLines(array $scenes, array $windows, ?array $chapterPlan): array
    {
        // Which scenes OPEN a chapter: covers always; with a plan, each
        // chapter's first scene; without one, any scene with a heading.
        $openers = [];
        foreach ((array) ($chapterPlan['chapters'] ?? []) as $ch) {
            $first = (array) ($ch['scene_ids'] ?? []);
            if ($first !== []) {
                $openers[(string) $first[0]] = true;
            }
        }

        $candidates = [];
        foreach ($scenes as $scene) {
            $id = (string) ($scene['scene_id'] ?? '');
            $tpl = (string) ($scene['layout_template'] ?? '');
            if ($tpl === 'outro_card') {
                continue;
            }
            $isCover = $tpl === 'chapter_cover';
            if ($openers !== [] && !$isCover && !isset($openers[$id])) {
                continue;
            }
            $label = self::sceneHeading($scene);
            if ($openers === [] && !$isCover && $label === '') {
                continue; // headingless scenes only continue the current beat
            }
            $start = (float) (($windows[$id] ?? [])['start'] ?? -1);
            if ($start < 0) {
                continue;
            }
            $candidates[] = ['start' => $start, 'label' => $label !== '' ? $label : 'Chapter ' . (count($candidates) + 1)];
        }

        if ($candidates === []) {
            return [];
        }

        // YouTube's rules: first chapter at 0:00, every chapter ≥10s long.
        usort($candidates, fn ($a, $b) => $a['start'] <=> $b['start']);
        if ($candidates[0]['start'] > 0.5) {
            array_unshift($candidates, ['start' => 0.0, 'label' => 'Intro']);
        }
        $candidates[0]['start'] = 0.0;

        $kept = [];
        foreach ($candidates as $c) {
            if ($kept !== [] && ($c['start'] - $kept[count($kept) - 1]['start']) < self::MIN_CHAPTER_S) {
                continue;
            }
            $kept[] = $c;
            if (count($kept) >= self::MAX_CHAPTERS) {
                break;
            }
        }

        if (count($kept) < self::MIN_CHAPTERS) {
            return [];
        }

        return array_map(
            fn ($c) => self::stamp($c['start']) . ' ' . mb_substr($c['label'], 0, 80),
            $kept
        );
    }

    /** The first heading any slot of the scene carries. */
    private static function sceneHeading(array $scene): string
    {
        foreach ((array) ($scene['slots'] ?? []) as $slot) {
            $heading = trim((string) (is_array($slot) ? ($slot['heading'] ?? '') : ''));
            if ($heading !== '') {
                return $heading;
            }
        }

        return '';
    }

    /** YouTube chapter stamp: m:ss below the hour, h:mm:ss above. */
    private static function stamp(float $seconds): string
    {
        $seconds = (int) floor(max(0.0, $seconds));
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * One cheap call for the description + hashtags. Null on refusal/junk.
     *
     * @return array{description: string, hashtags: string[]}|null
     */
    private function requestCopy(string $title, array $scenes): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $digest = '';
        foreach ($scenes as $scene) {
            $text = trim((string) ($scene['narration'] ?? ''));
            if ($text !== '') {
                $digest .= $text . "\n";
            }
        }
        $digest = trim(mb_substr($digest, 0, 2400));
        if ($digest === '') {
            return null;
        }

        $system = <<<'PROMPT'
You write the YouTube description for a finished explainer video. Return ONLY JSON:
{"description": "2-4 sentences that sell the video's actual content — specific, no hype filler, no 'in this video', no hashtags, no links", "hashtags": ["4-8 lowercase tags without the # sign, most specific first"]}
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(45)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "TITLE: {$title}\n\nNARRATION:\n{$digest}"],
                ],
                'temperature' => 0.4,
                'max_tokens' => 300,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("packaging copy request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'youtube_packaging');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        $description = trim((string) ($parsed['description'] ?? ''));
        if ($description === '') {
            return null;
        }

        $tags = [];
        foreach ((array) ($parsed['hashtags'] ?? []) as $tag) {
            $tag = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $tag));
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= 8) {
                break;
            }
        }

        return [
            'description' => mb_substr($description, 0, 1200),
            'hashtags' => $tags !== [] ? $tags : self::titleTags($title),
        ];
    }

    /**
     * No-LLM fallback: the hook's opening sentences + tags from the title.
     * Public + pure for the test suite.
     *
     * @return array{description: string, hashtags: string[]}
     */
    public static function fallbackCopy(string $title, array $scenes): array
    {
        $hook = '';
        foreach ($scenes as $scene) {
            $hook = trim((string) ($scene['narration'] ?? ''));
            if ($hook !== '') {
                break;
            }
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $hook) ?: [];
        $description = trim(implode(' ', array_slice(array_filter(array_map('trim', $sentences)), 0, 2)));

        return [
            'description' => $description !== '' ? $description : $title,
            'hashtags' => self::titleTags($title),
        ];
    }

    /** @return string[] lowercase alnum tags from the title's longer words */
    private static function titleTags(string $title): array
    {
        $tags = [];
        foreach (preg_split('/[^A-Za-z0-9]+/', $title) ?: [] as $word) {
            $word = strtolower($word);
            if (mb_strlen($word) > 3 && !in_array($word, $tags, true)) {
                $tags[] = $word;
            }
            if (count($tags) >= 5) {
                break;
            }
        }
        $tags[] = 'explainer';

        return array_values(array_unique($tags));
    }
}
