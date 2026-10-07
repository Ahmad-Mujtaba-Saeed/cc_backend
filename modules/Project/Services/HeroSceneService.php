<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Support\HeroScenes;
use Modules\Project\Support\LlmModels;
use Throwable;

/**
 * HeroSceneService — a code model writes ONE scene of the video as a Remotion
 * component, and this service refuses to ship it until it has proven itself.
 *
 *   1. write   — the model gets the hero skill (Resources/hero/skill.md: the
 *                sandbox contract, the kit, the timing/composition rules) and
 *                three worked examples, then this scene's narration with word
 *                timings, its on-screen copy and the video's frame size;
 *   2. compile — the render service's guard (POST /hero/compile) parses it,
 *                refuses anything that reaches outside the sandbox, compiles;
 *   3. test    — the render service renders 5 frames of the scene ALONE in the
 *                video's real look (POST /hero/test); a runtime error, a
 *                fallback to the card, an empty frame or a slow frame fails;
 *   4. review  — a cheap vision pass looks at the frames for overlapping or
 *                clipped text, illegible contrast and broken layouts.
 *
 * Any failure goes back to the model as a concrete fix list, up to
 * `$maxAttempts` writes. A hero that never passes is recorded as failed and
 * the scene simply keeps its card.
 */
class HeroSceneService
{
    private string $apiKey;
    private string $model;
    private string $renderUrl;

    /** @var array<int, array{label: string, model: string, in: int, out: int, cached: int}> every model call this run made */
    public array $calls = [];

    /**
     * @param  string|null  $tag  a bench run's label: its stills and module go under hero/{tag}/
     *                            and never replace the project's live hero file
     */
    public function __construct(
        ?string $model = null,
        private int $maxAttempts = 3,
        private bool $review = true,
        private ?string $tag = null,
        ?string $renderUrl = null
    ) {
        $this->apiKey = (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY'));
        $this->model = $model ?: LlmModels::for('hero');
        $this->renderUrl = rtrim($renderUrl ?: (string) config('services.remotion.url', 'http://localhost:3020'), '/');
    }

    private function dir(Project $project): string
    {
        return "explainer/{$project->id}/hero" . ($this->tag ? '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->tag) : '');
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Write, prove and store a hero for one scene.
     *
     * @param  array  $scene  the scene as assembled for render (ExplainerSceneAssembler)
     * @param  array  $payloadScene  the same scene as it appears in the render payload (assets resolved)
     * @param  array  $shotList  the project's render shot list (theme, fonts, motion…) — the test renders in it
     * @return array{ok: bool, entry: array}
     */
    public function generate(Project $project, array $scene, array $payloadScene, array $shotList, int $fps, int $width, int $height): array
    {
        $sceneId = (string) $scene['scene_id'];
        $started = microtime(true);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->brief($scene, $payloadScene, $fps, $width, $height, (bool) ($shotList['captions']['enabled'] ?? false))],
        ];

        $attempts = [];
        $last = ['code' => null, 'js' => null, 'stills' => [], 'review' => null];
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $reply = $this->ask($messages, "hero_scene_write#{$attempt}");
            $code = $reply === null ? null : self::extractCode($reply);
            if ($code === null) {
                $attempts[] = ['attempt' => $attempt, 'stage' => 'write', 'errors' => ['no code block in the reply']];
                $messages[] = ['role' => 'assistant', 'content' => (string) $reply];
                $messages[] = ['role' => 'user', 'content' => 'Return the complete module in ONE ```tsx code block and nothing else.'];
                continue;
            }
            $messages[] = ['role' => 'assistant', 'content' => "```tsx\n{$code}\n```"];

            // 2. compile
            $compiled = $this->compile($code);
            if (!$compiled['ok']) {
                $attempts[] = ['attempt' => $attempt, 'stage' => 'compile', 'errors' => $compiled['errors']];
                $messages[] = ['role' => 'user', 'content' => "The sandbox REFUSED the module:\n- " . implode("\n- ", $compiled['errors'])
                    . "\n\nFix every point and return the whole corrected module in one ```tsx block."];
                continue;
            }

            // 3. test render
            $test = $this->test($project, $sceneId, $attempt, $compiled['js'], $payloadScene, $shotList, $fps, $width, $height);
            $problems = $test['problems'];
            $last = ['code' => $code, 'js' => $compiled['js'], 'stills' => $test['stills'], 'review' => null];
            if ($problems !== []) {
                $attempts[] = ['attempt' => $attempt, 'stage' => 'test', 'errors' => $problems];
                $messages[] = ['role' => 'user', 'content' => "The module compiled, but rendering it failed:\n- " . implode("\n- ", $problems)
                    . "\n\nFix it and return the whole corrected module in one ```tsx block."];
                continue;
            }

            // 4. review: the measured layout notes (small / overlapping text,
            // free and exact) plus one cheap vision look at the design.
            $review = $this->review ? $this->reviewStills($test['stills'], $scene) : null;
            $last['review'] = $review;
            $notes = array_merge($test['layout'], ($review !== null && !$review['pass']) ? $review['issues'] : []);
            if ($notes !== [] && $attempt < $this->maxAttempts) {
                $attempts[] = ['attempt' => $attempt, 'stage' => 'review', 'errors' => $notes, 'score' => $review['score'] ?? null];
                $messages[] = ['role' => 'user', 'content' => "Your scene rendered. Measuring its frames (at 6%, 30%, 55%, 80% and 97%) and reviewing them found:\n- "
                    . implode("\n- ", $notes)
                    . "\n\nFix every point (keep what works) and return the whole module in one ```tsx block."];
                continue;
            }

            // The last attempt is accepted with review notes — unless the
            // reviewer scored it plain-or-worse: then the card is the better
            // scene and the hero is not shipped.
            if ($review !== null && !$review['pass'] && $review['score'] <= 5) {
                $attempts[] = ['attempt' => $attempt, 'stage' => 'review', 'errors' => $review['issues'], 'score' => $review['score']];
                break;
            }
            $attempts[] = ['attempt' => $attempt, 'stage' => 'pass', 'score' => $review['score'] ?? null];

            $entry = $this->store($project, $scene, $code, $compiled['js'], $test['stills'], $review, $attempts, $started);
            if ($this->tag === null) {
                $entry += $this->renderClip($project, $sceneId, $compiled['js'], $payloadScene, $shotList, $fps, $width, $height) ?? [];
            }

            return ['ok' => true, 'entry' => $entry];
        }

        return ['ok' => false, 'entry' => [
            'status' => 'failed',
            'model' => $this->model,
            'attempts' => $attempts,
            'content_hash' => HeroScenes::contentHash($scene),
            'stills' => $last['stills'],
            'cost_usd' => round($this->cost(), 4),
            'seconds' => round(microtime(true) - $started, 1),
            'error' => 'did not pass after ' . $this->maxAttempts . ' attempts',
            'updated_at' => now()->toIso8601String(),
            // Kept for the bench and for debugging: the last code that compiled.
            'last_code' => $last['code'],
        ]];
    }

    // ------------------------------------------------------------------
    // prompts
    // ------------------------------------------------------------------

    public function systemPrompt(): string
    {
        $dir = dirname(__DIR__) . '/Resources/hero';
        $out = (string) file_get_contents("{$dir}/skill.md");
        foreach (glob("{$dir}/examples/*.tsx") ?: [] as $i => $file) {
            $out .= "\n\n## Example " . ($i + 1) . "\n\n```tsx\n" . trim((string) file_get_contents($file)) . "\n```";
        }

        return $out;
    }

    /** The user turn: everything about THIS beat. */
    public function brief(array $scene, array $payloadScene, int $fps, int $width, int $height, bool $captions): string
    {
        $narration = (string) ($scene['narration']['text'] ?? $scene['narration'] ?? '');
        $seconds = (float) ($scene['duration_seconds'] ?? 8);
        $frames = (int) round($seconds * $fps);

        $words = (array) ($payloadScene['narration_words'] ?? []);
        $estimated = $words === [];
        if ($estimated) {
            $words = self::estimateWords($narration, $seconds);
        }
        $timing = implode(' ', array_map(fn ($w) => ((string) ($w['word'] ?? '')) . '@' . (int) round(((float) ($w['start'] ?? 0)) * $fps), array_slice($words, 0, 140)))
            . ($estimated ? '  (ESTIMATED — the voice is recorded later; cue() finds the real words by text)' : '');

        $copy = HeroScenes::onScreenCopy($scene);
        $lines = [
            'Make the hero scene for this beat.',
            '',
            "NARRATION (spoken over the scene): \"{$narration}\"",
            "WORD TIMINGS (word@frame, scene-local): {$timing}",
            "SCENE LENGTH: about {$frames} frames at {$fps} fps (~" . round($seconds, 1) . ' s). It may come out a little longer or shorter once the voice is recorded — time with cue() and t, never with absolute end frames.',
            "FRAME: {$width}×{$height} (" . ($height > $width ? 'portrait 9:16' : 'landscape 16:9') . ')' . ($captions && $height > $width ? ', captions burned into the bottom ~20% (stay above safe.bottom)' : ''),
            'THE CARD THIS REPLACES: ' . (string) ($scene['layout_template'] ?? 'text card') . '. Its on-screen copy (use what helps, rewrite freely, keep it short):',
            $copy !== [] ? '- ' . implode("\n- ", $copy) : '- (none)',
        ];
        if (!empty($payloadScene['illustration_url'])) {
            $lines[] = 'ASSETS: "illustration" — a drawing of the subject you may show with <Asset name="illustration" />.';
        } else {
            $lines[] = 'ASSETS: none — draw everything with SVG.';
        }
        $lines[] = '';
        $lines[] = 'Design one strong visual idea that SHOWS what the narration says, timed to its words. Return only the ```tsx module.';

        return implode("\n", $lines);
    }

    /**
     * Word timings for a scene whose voice has not been recorded yet (TTS runs
     * at render time): the narration spread evenly, roughly how a voice
     * paces it. The real timings replace these at render; the hero's cue()
     * looks words up by TEXT, so a scene written against the estimate still
     * lands on the real voice.
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    public static function estimateWords(string $narration, float $seconds): array
    {
        $words = preg_split('/\s+/u', trim($narration)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        $n = count($words);
        if ($n === 0) {
            return [];
        }
        $span = max(1.0, $seconds - 1.2);
        $out = [];
        foreach ($words as $i => $w) {
            $out[] = [
                'word' => preg_replace("/[^\\p{L}\\p{N}'-]/u", '', $w) ?? $w,
                'start' => round(0.3 + ($i / $n) * $span, 3),
                'end' => round(0.3 + (($i + 0.85) / $n) * $span, 3),
            ];
        }

        return $out;
    }

    /** The module inside the first ```tsx / ```ts / ```jsx block (or the bare reply if it is obviously code). */
    public static function extractCode(string $reply): ?string
    {
        if (preg_match('/```(?:tsx|typescript|ts|jsx|javascript|js)?\s*\n(.*?)```/s', $reply, $m)) {
            $code = trim($m[1]);
        } else {
            $code = trim($reply);
        }

        return str_contains($code, 'export default') ? $code : null;
    }

    // ------------------------------------------------------------------
    // the four gates
    // ------------------------------------------------------------------

    /** Test seam: when set, called instead of the model (dry runs of the pipeline cost nothing). */
    public $fakeWriter = null;

    private function ask(array $messages, string $label): ?string
    {
        if (is_callable($this->fakeWriter)) {
            return (string) ($this->fakeWriter)($messages);
        }
        $payload = LlmModels::tune([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => 9000,
        ], 'low');

        try {
            $response = Http::withToken($this->apiKey)->timeout(300)->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (Throwable $e) {
            Log::warning('HeroSceneService: model call failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (!$response->successful()) {
            Log::warning('HeroSceneService: model call rejected', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return null;
        }
        $usage = (array) $response->json('usage');
        CostTracker::recordChat($this->model, $usage, 'hero_scene');
        $this->calls[] = [
            'label' => $label,
            'model' => $this->model,
            'in' => (int) ($usage['prompt_tokens'] ?? 0),
            'out' => (int) ($usage['completion_tokens'] ?? 0),
            'cached' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
        ];

        return (string) $response->json('choices.0.message.content');
    }

    /** @return array{ok: bool, js?: string, errors: string[]} */
    public function compile(string $code): array
    {
        try {
            $r = Http::timeout(30)->post("{$this->renderUrl}/hero/compile", ['code' => $code]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['(render service unreachable: ' . $e->getMessage() . ')']];
        }
        $json = (array) $r->json();

        return ['ok' => (bool) ($json['ok'] ?? false), 'js' => $json['js'] ?? null, 'errors' => array_values((array) ($json['errors'] ?? ['compile failed']))];
    }

    /**
     * Render the scene alone, in the video's real look, at five points.
     *
     * @return array{problems: string[], stills: string[], layout: string[]}
     */
    /**
     * The hero's scene ALONE, in the project's real look: what the acceptance
     * test and the preview clip both render.
     */
    public static function miniShotList(string $js, array $payloadScene, array $shotList, bool $audit): array
    {
        $scene = $payloadScene;
        if (empty($scene['narration_words'])) {
            $scene['narration_words'] = self::estimateWords((string) ($scene['narration']['text'] ?? ''), (float) ($scene['duration_seconds'] ?? 8));
        }
        $scene['hero'] = array_filter([
            'js' => $js,
            'captions' => (bool) ($shotList['captions']['enabled'] ?? false),
            'assets' => array_filter(['illustration' => $payloadScene['illustration_url'] ?? null]),
            'audit' => $audit ?: null,
        ], fn ($v) => $v !== null);
        $scene['transition'] = 'none';
        unset($scene['punchline']);

        return array_merge($shotList, [
            'composition_mode' => 'slides',
            'canvas' => null,
            'chapters' => null,
            'music' => null,
            'scenes' => [$scene],
        ]);
    }

    /**
     * Record the hero as a small silent MP4 (POST /hero/clip) — what the
     * dashboard's Play tab shows in its place, since hero code never runs in
     * the dashboard. Captions are left off the clip (the Player draws its own
     * on top) while the hero still keeps clear of them. Never throws: a hero
     * without a clip just shows its card in the Play tab, as before.
     *
     * @return array{clip: string, clip_frames: int}|null
     */
    public function renderClip(Project $project, string $sceneId, string $js, array $payloadScene, array $shotList, int $fps, int $width, int $height): ?array
    {
        $mini = self::miniShotList($js, $payloadScene, $shotList, false);
        $mini['captions'] = array_merge((array) ($mini['captions'] ?? []), ['enabled' => false]);
        $rel = $this->dir($project) . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sceneId) . '-' . substr(md5($js), 0, 8) . '.mp4';
        Storage::disk('public')->makeDirectory(dirname($rel));

        try {
            $r = Http::timeout(300)->post("{$this->renderUrl}/hero/clip", [
                'shot_list' => $mini,
                'output_path' => Storage::disk('public')->path($rel),
                'fps' => $fps,
                'width' => $width,
                'height' => $height,
                'scale' => 0.5,
            ]);
        } catch (Throwable $e) {
            Log::warning('HeroSceneService: clip render could not run', ['project_id' => $project->id, 'scene_id' => $sceneId, 'error' => $e->getMessage()]);

            return null;
        }
        $json = (array) $r->json();
        if (!($json['success'] ?? false) || !Storage::disk('public')->exists($rel)) {
            Log::warning('HeroSceneService: clip render failed', ['project_id' => $project->id, 'scene_id' => $sceneId, 'error' => $json['error'] ?? null]);

            return null;
        }

        return ['clip' => $rel, 'clip_frames' => (int) ($json['frames'] ?? 0)];
    }

    private function test(Project $project, string $sceneId, int $attempt, string $js, array $payloadScene, array $shotList, int $fps, int $width, int $height): array
    {
        // Measure the hero's text in every test frame (remotion-render hero/audit.tsx).
        $mini = self::miniShotList($js, $payloadScene, $shotList, true);
        $scene = $mini['scenes'][0];
        $frames = (int) max(30, round(((float) ($scene['duration_seconds'] ?? 8)) * $fps));
        $at = array_map(fn ($f) => (int) round($f * ($frames - 1)), [0.06, 0.3, 0.55, 0.8, 0.97]);

        $rel = $this->dir($project) . '/test/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sceneId) . "-{$attempt}";
        Storage::disk('public')->makeDirectory($rel);
        $containerDir = Storage::disk('public')->path($rel);

        try {
            $r = Http::timeout(240)->post("{$this->renderUrl}/hero/test", [
                'shot_list' => $mini,
                'frames' => $at,
                'output_dir' => $containerDir,
                'fps' => $fps,
                'width' => $width,
                'height' => $height,
                'scale' => 0.5,
            ]);
        } catch (Throwable $e) {
            return ['problems' => ['(the test render could not run: ' . $e->getMessage() . ')'], 'stills' => [], 'layout' => []];
        }
        $json = (array) $r->json();
        if (!($json['success'] ?? false)) {
            return ['problems' => ['the scene could not be rendered: ' . (string) ($json['error'] ?? 'unknown error')], 'stills' => [], 'layout' => []];
        }

        // Text running off the frame is a hard failure; small or overlapping
        // text is a note the model gets to fix while attempts remain.
        $problems = [];
        $layout = [];
        foreach ((array) ($json['hero_errors'] ?? []) as $e) {
            $e = (string) $e;
            if (preg_match('/\[hero\] audit: (off-frame|small|overlap): (.*)$/s', $e, $m)) {
                if ($m[1] === 'off-frame') {
                    $problems[] = mb_substr($m[2], 0, 200);
                } else {
                    $layout[] = mb_substr($m[2], 0, 200);
                }
                continue;
            }
            $problems[] = 'runtime: ' . mb_substr($e, 0, 300);
        }
        $stills = [];
        $slow = 0;
        foreach ((array) ($json['stills'] ?? []) as $s) {
            $stills[] = $rel . '/' . basename((string) $s['output_path']);
            if ((int) ($s['ms'] ?? 0) > 9000) {
                $slow++;
            }
        }
        if ($slow > 0) {
            $problems[] = "{$slow} frame(s) took over 9 s to draw — reduce the element count, filters and blur.";
        }
        if (count($stills) < count($at)) {
            $problems[] = 'some frames could not be drawn.';
        }
        $flat = array_filter($stills, fn ($p) => self::isFlat(Storage::disk('public')->path($p)));
        if (count($stills) > 0 && count($flat) >= 2) {
            $problems[] = count($flat) . ' of the sampled frames are empty (one flat colour) — something must be visible from the first frame and stay visible.';
        }

        return [
            'problems' => array_slice(array_values(array_unique($problems)), 0, 10),
            'stills' => $stills,
            'layout' => array_slice(array_values(array_unique($layout)), 0, 8),
        ];
    }

    /** Is a PNG essentially one colour? (an empty or failed frame) */
    public static function isFlat(string $absPath): bool
    {
        if (!is_file($absPath) || !function_exists('imagecreatefrompng')) {
            return false;
        }
        $img = @imagecreatefrompng($absPath);
        if (!$img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $vals = [];
        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 24; $x++) {
                $c = imagecolorat($img, (int) (($x + 0.5) * $w / 24), (int) (($y + 0.5) * $h / 24));
                $vals[] = (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
            }
        }
        imagedestroy($img);
        $mean = array_sum($vals) / count($vals);
        $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / count($vals);

        return sqrt($var) < 2.5;
    }

    /**
     * One cheap vision look at the frames. Never blocks on its own failure.
     *
     * @return array{pass: bool, score: int, issues: string[]}|null
     */
    public function reviewStills(array $stills, array $scene): ?array
    {
        if ($stills === [] || $this->apiKey === '') {
            return null;
        }
        $content = [[
            'type' => 'text',
            // Text clipping, size and overlap are MEASURED in the test render
            // (hero/audit.tsx) - exact and free. The vision pass judges what
            // only a viewer can: is the idea clear, are the facts right, does
            // the frame look designed or empty. Tuned on the hero bench
            // (2026-10-03): a lenient wording scored a sparse scene showing
            // 10% for "a fifth" 7/10; high image detail cost more than the
            // scene's own code.
            'text' => 'You are a strict art director reviewing 3 frames (at 30%, 80% and 97%) from ONE animated scene of a professional explainer video. '
                . 'Narration: "' . mb_substr((string) ($scene['narration']['text'] ?? ''), 0, 400) . '". '
                . 'Report every one of these you see: '
                . '(a) a number, date, name or proportion on screen that does not match the narration; '
                . '(b) a mostly empty frame - a small graphic lost in a big empty field; '
                . '(c) elements piled on top of each other, or a frame that looks broken; '
                . '(d) the visual does not show what the narration says (it is decoration, or a generic text card); '
                . '(e) the 97% frame does not read as a finished picture; '
                . '(f) text too low-contrast to read. '
                . 'Score 1-10 as motion design: 9-10 = could air on a top explainer channel; 7-8 = good; 5-6 = plain or sparse; 1-4 = broken or amateur. '
                . 'Return JSON {"score": n, "pass": false if ANY of (a)-(f) is present, "issues": ["short, specific, actionable fix naming the element", ...]}.',
        ]];
        // Frames 2, 4 and 5 (30%, 80%, 97%): three low-detail images cost
        // ~8.5k tokens on gpt-4o-mini, where five at high detail cost ~70k.
        $pick = array_values(array_intersect_key(array_values($stills), [1 => 1, 3 => 1, 4 => 1])) ?: array_values($stills);
        foreach ($pick as $i => $p) {
            $abs = Storage::disk('public')->path($p);
            if (!is_file($abs)) {
                continue;
            }
            $content[] = ['type' => 'text', 'text' => 'Frame ' . ($i + 1) . ':'];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($abs)), 'detail' => 'low']];
        }

        $vlm = LlmModels::for('vlm');
        try {
            $r = Http::withToken($this->apiKey)->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $vlm,
                'messages' => [['role' => 'user', 'content' => $content]],
                'temperature' => 0.1,
                'max_tokens' => 400,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (Throwable $e) {
            return null;
        }
        if (!$r->successful()) {
            return null;
        }
        $usage = (array) $r->json('usage');
        CostTracker::recordChat($vlm, $usage, 'hero_scene_review');
        $this->calls[] = ['label' => 'hero_scene_review', 'model' => $vlm, 'in' => (int) ($usage['prompt_tokens'] ?? 0), 'out' => (int) ($usage['completion_tokens'] ?? 0), 'cached' => 0];

        $j = json_decode((string) $r->json('choices.0.message.content'), true);
        if (!is_array($j)) {
            return null;
        }

        return [
            'pass' => (bool) ($j['pass'] ?? true),
            'score' => max(1, min(10, (int) ($j['score'] ?? 5))),
            'issues' => array_slice(array_values(array_filter(array_map('strval', (array) ($j['issues'] ?? [])))), 0, 6),
        ];
    }

    private function store(Project $project, array $scene, string $code, string $js, array $stills, ?array $review, array $attempts, float $started): array
    {
        $file = $this->tag
            ? $this->dir($project) . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $scene['scene_id']) . '.json'
            : HeroScenes::storagePath($project->id, (string) $scene['scene_id']);
        Storage::disk('public')->put($file, json_encode(['code' => $code, 'js' => $js], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'status' => 'ready',
            'file' => $file,
            'model' => $this->model,
            'content_hash' => HeroScenes::contentHash($scene),
            'stills' => $stills,
            'review' => $review,
            'attempts' => $attempts,
            'cost_usd' => round($this->cost(), 4),
            'seconds' => round(microtime(true) - $started, 1),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Dollars spent by this instance's calls — counted exactly as the ledger
     * (render_cost_events) counts them: every prompt token at the full input
     * rate. The first hero bench discounted cached tokens and reported $0.175
     * for a run the ledger put at ~$0.24; the bench budget must use the
     * conservative number.
     */
    public function cost(): float
    {
        $total = 0.0;
        foreach ($this->calls as $c) {
            [$in, , $out] = self::rates($c['model']);
            $total += ($c['in'] * $in + $c['out'] * $out) / 1_000_000;
        }

        return $total;
    }

    /** @return array{0: float, 1: float, 2: float} $/1M input, cached input, output */
    public static function rates(string $model): array
    {
        $r = CostTracker::rates();

        return match (true) {
            str_starts_with($model, 'gpt-5.6-luna') => [$r['gpt_5_6_luna_input_per_1m'], $r['gpt_5_6_luna_input_per_1m'] * 0.1, $r['gpt_5_6_luna_output_per_1m']],
            str_starts_with($model, 'gpt-5-nano') => [$r['gpt_5_nano_input_per_1m'], $r['gpt_5_nano_input_per_1m'] * 0.1, $r['gpt_5_nano_output_per_1m']],
            str_starts_with($model, 'gpt-4.1-nano') => [$r['gpt_4_1_nano_input_per_1m'], $r['gpt_4_1_nano_input_per_1m'] * 0.25, $r['gpt_4_1_nano_output_per_1m']],
            str_starts_with($model, 'gpt-4o-mini') => [$r['gpt_4o_mini_input_per_1m'], $r['gpt_4o_mini_input_per_1m'] * 0.5, $r['gpt_4o_mini_output_per_1m']],
            default => [$r['gpt_4o_input_per_1m'], $r['gpt_4o_input_per_1m'] * 0.5, $r['gpt_4o_output_per_1m']],
        };
    }
}
