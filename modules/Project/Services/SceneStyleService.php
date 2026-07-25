<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SceneStyleService — the "scene stylist".
 *
 * One cheap LLM pass that gives every scene of an explainer a PRESENTATION
 * PERSONALITY so a long video never repeats one look, plus a content-true
 * illustration brief so the AI image actually depicts what the card says
 * (the old one-size prompt produced generic "tech concept" art):
 *
 *   - variant:      how the text card presents itself (editorial / statement /
 *                   numbered / checklist / cards — mirrored in Remotion's
 *                   TextBlock, which re-validates against its own constraints)
 *   - kicker:       a tiny uppercase eyebrow line ("THE PROBLEM", "STEP 2")
 *   - highlight:    1-2 heading words that deserve the accent gradient
 *   - image_prompt: a concrete, scene-specific illustration brief
 *
 * Everything is validated (variant whitelist, highlights must exist in the
 * heading, anti-repetition pass) and a deterministic seeded fallback covers
 * LLM failure, so the feature degrades gracefully, never fatally.
 */
class SceneStyleService
{
    public const VARIANTS = ['editorial', 'statement', 'numbered', 'checklist', 'cards', 'typewriter'];

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    /**
     * Style a set of scenes.
     *
     * @param  array<int, array{scene_id: string, heading: string, bullets: array, body: string, narration: string, has_media: bool}>  $scenes
     * @return array<string, array{variant: string, kicker: string, highlight: array, image_prompt: string}>  keyed by scene_id
     */
    public function style(array $scenes): array
    {
        if (empty($scenes)) {
            return [];
        }

        $picked = [];
        try {
            $picked = $this->requestStyles($scenes);
        } catch (\Throwable $e) {
            Log::warning('SceneStyleService: LLM pass failed, using seeded fallback', ['error' => $e->getMessage()]);
        }

        $byScene = [];
        foreach ($scenes as $i => $scene) {
            $sid = (string) $scene['scene_id'];
            $raw = $picked[$sid] ?? [];
            $byScene[$sid] = $this->validateEntry($raw, $scene, $i);
        }

        return $this->breakRepetition($byScene, $scenes);
    }

    /**
     * Auto-theme (copilot.md §11.3): suggest {color_scheme, font_pack,
     * motion_style, skin} for the whole video from its topic + dominant mood.
     * One cheap call; every field is whitelisted against the registry, and
     * the caller treats a failure/empty result as "use the defaults".
     *
     * @return array{color_scheme?: string, font_pack?: string, motion_style?: string, skin?: string}
     */
    public function suggestTheme(string $title, string $summary, string $mood): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $schemes = implode(', ', \Modules\Project\Support\ExplainerRegistry::colorSchemeNames());
        $packs = implode(', ', \Modules\Project\Support\ExplainerRegistry::fontPackNames());
        $styles = [];
        foreach (\Modules\Project\Support\ExplainerRegistry::motionStyles() as $name => $meta) {
            $styles[] = "\"{$name}\" ({$meta['use_when']})";
        }
        $skins = [];
        foreach (\Modules\Project\Support\ExplainerRegistry::skins() as $name => $meta) {
            $skins[] = "\"{$name}\" ({$meta['use_when']})";
        }

        $system = 'You art-direct short explainer videos. Given a topic, pick the look that FITS it. '
            . 'Return ONLY JSON: {"color_scheme": one of [' . $schemes . '], '
            . '"font_pack": one of [' . $packs . '], '
            . '"motion_style": one of [' . implode(', ', $styles) . '], '
            . '"skin": one of [' . implode(', ', $skins) . ']}.';

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "TOPIC: {$title}\nSUMMARY: " . mb_substr($summary, 0, 300) . "\nDOMINANT MOOD: {$mood}"],
                ],
                'temperature' => 0.4,
                'max_tokens' => 120,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Auto-theme request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'auto_theme');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed)) {
            return [];
        }

        $out = [];
        if (in_array($parsed['color_scheme'] ?? '', \Modules\Project\Support\ExplainerRegistry::colorSchemeNames(), true)) {
            $out['color_scheme'] = (string) $parsed['color_scheme'];
        }
        if (in_array($parsed['font_pack'] ?? '', \Modules\Project\Support\ExplainerRegistry::fontPackNames(), true)) {
            $out['font_pack'] = (string) $parsed['font_pack'];
        }
        if (in_array($parsed['motion_style'] ?? '', \Modules\Project\Support\ExplainerRegistry::motionStyleNames(), true)) {
            $out['motion_style'] = (string) $parsed['motion_style'];
        }
        if (in_array($parsed['skin'] ?? '', \Modules\Project\Support\ExplainerRegistry::skinNames(), true)) {
            $out['skin'] = (string) $parsed['skin'];
        }

        return $out;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, array>  raw LLM entries keyed by scene_id
     */
    private function requestStyles(array $scenes): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $lines = [];
        foreach ($scenes as $scene) {
            $lines[] = json_encode(array_filter([
                'scene_id' => (string) $scene['scene_id'],
                'heading' => mb_substr((string) $scene['heading'], 0, 120),
                'bullets' => array_map(fn ($b) => mb_substr((string) $b, 0, 110), array_slice((array) $scene['bullets'], 0, 6)),
                'body' => mb_substr((string) $scene['body'], 0, 200),
                'narration' => mb_substr((string) $scene['narration'], 0, 280),
                'has_media' => (bool) $scene['has_media'],
                'mood' => (string) ($scene['mood'] ?? '') ?: null,
                'has_box' => (bool) ($scene['has_box'] ?? false) ?: null,
                // Camera director marked this scene as a smash-cut beat.
                'smash_beat' => (bool) ($scene['smash_beat'] ?? false) ?: null,
            ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE);
        }
        $brief = implode("\n", $lines);

        $system = <<<PROMPT
You are the art director of a premium animated explainer video. For EVERY scene you receive, decide how its text card should present itself and write the brief for its illustration.

For each scene return:
- "variant": one of
    "editorial"  — default reading card: heading + numbered points (use for dense or long points),
    "statement"  — hero moment: one huge centered heading, points as small chips (ONLY when the heading is strong and there are at most 3 short points),
    "numbered"   — oversized 01/02/03 digits beside each point (ordered steps, rankings, sequences),
    "checklist"  — points check themselves off (requirements, do's, action items),
    "cards"      — points as small side-by-side cards (2-4 short parallel ideas, comparisons, options),
    "typewriter" — the explanation body types itself out character by character behind a block caret (ONLY for scenes with "has_box": true AND mood "suspense" or "tense"; at most once per video).
  VARY the variants across the video — never give more than two consecutive scenes the same variant. Give at least one scene "statement" if any heading deserves it. A scene marked "smash_beat" arrives as a smash cut — give it "statement" unless its content plainly forbids it.
- "kicker": a 1-3 word uppercase eyebrow label naming the scene's ROLE in the story ("THE PROBLEM", "STEP 1", "THE TWIST", "WHY IT WORKS", "BOTTOM LINE"). Max 16 characters. Empty string if nothing fits.
- "highlight": 1-2 words copied EXACTLY (same spelling) from the scene's heading that deserve the accent color — the words carrying the emotional or informational punch. Empty array if the scene has no heading.
- "image_prompt": a CONCRETE illustration brief for this specific scene, max 25 words. Name the SUBJECT and what it is DOING, drawn from the scene's content — real objects arranged in space ("a rocket lifting off from an open laptop screen", "two piggy banks on a seesaw, one overflowing"). Write the subject ONLY. Do NOT describe style, colour, lighting, mood, material or camera: the words "glowing", "gradient", "cinematic", "3d", "render", "realistic", "photo", "lighting", "shadow", "dark", "neon" are FORBIDDEN — a separate art-direction layer supplies all of that, and repeating it there corrupts the result. NEVER vague concept-art phrases ("modern technology concept", "abstract innovation"). No people's faces, no brand logos, no text in the image.

Return ONLY JSON: {"scenes": [{"scene_id": "...", "variant": "...", "kicker": "...", "highlight": ["..."], "image_prompt": "..."}]}
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "SCENES (one JSON object per line):\n" . $brief],
                ],
                'temperature' => 0.5,
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Scene style request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'scene_styles');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        $entries = is_array($parsed['scenes'] ?? null) ? $parsed['scenes'] : [];

        $bySid = [];
        foreach ($entries as $entry) {
            $sid = (string) ($entry['scene_id'] ?? '');
            if ($sid !== '') {
                $bySid[$sid] = $entry;
            }
        }

        return $bySid;
    }

    /**
     * Clamp one raw entry into a safe style record; every missing/invalid
     * field falls back to a deterministic seeded choice.
     */
    private function validateEntry(array $raw, array $scene, int $index): array
    {
        $variant = in_array($raw['variant'] ?? '', self::VARIANTS, true)
            ? (string) $raw['variant']
            : $this->seededVariant($scene, $index);

        // Typewriter (§4.5) is a suspense device, not a text-card look: it
        // needs an explanation_box body and a tense/suspense mood to type.
        if ($variant === 'typewriter' && !$this->typewriterFits($scene)) {
            $variant = $this->seededVariant($scene, $index);
        }

        $kicker = trim((string) ($raw['kicker'] ?? ''));
        if (mb_strlen($kicker) > 20) {
            $kicker = mb_substr($kicker, 0, 20);
        }

        // Highlights must be real heading words or the renderer can't paint them.
        $headingWords = [];
        foreach (preg_split('/\s+/', (string) $scene['heading']) ?: [] as $w) {
            $norm = $this->norm($w);
            if ($norm !== '') {
                $headingWords[$norm] = $w;
            }
        }
        $highlight = [];
        foreach ((array) ($raw['highlight'] ?? []) as $w) {
            $norm = $this->norm((string) $w);
            if ($norm !== '' && isset($headingWords[$norm])) {
                $highlight[] = $headingWords[$norm];
            }
            if (count($highlight) >= 2) {
                break;
            }
        }

        $imagePrompt = $this->sanitizeSubject((string) ($raw['image_prompt'] ?? ''));
        if (mb_strlen($imagePrompt) > 300) {
            $imagePrompt = mb_substr($imagePrompt, 0, 300);
        }
        if ($imagePrompt === '') {
            $imagePrompt = $this->fallbackImagePrompt($scene);
        }

        return [
            'variant' => $variant,
            'kicker' => $kicker,
            'highlight' => array_values($highlight),
            'image_prompt' => $imagePrompt,
        ];
    }

    /**
     * No more than two consecutive scenes may share a variant — the third in
     * a run is rotated to the next content-compatible personality.
     */
    private function breakRepetition(array $byScene, array $scenes): array
    {
        $run = 0;
        $prev = null;
        foreach ($scenes as $i => $scene) {
            $sid = (string) $scene['scene_id'];
            $variant = $byScene[$sid]['variant'];
            if ($variant === $prev) {
                $run++;
                if ($run >= 2) {
                    foreach ($this->compatibleVariants($scene) as $candidate) {
                        if ($candidate !== $variant) {
                            $byScene[$sid]['variant'] = $candidate;
                            $variant = $candidate;
                            break;
                        }
                    }
                    $run = 0;
                }
            } else {
                $run = 0;
            }
            $prev = $variant;
        }

        return $byScene;
    }

    /** May this scene carry the typewriter variant (§4.5)? */
    private function typewriterFits(array $scene): bool
    {
        return (bool) ($scene['has_box'] ?? false)
            && in_array((string) ($scene['mood'] ?? ''), ['suspense', 'tense'], true);
    }

    /** Variants this scene's content can carry (mirrors the renderer's rules). */
    private function compatibleVariants(array $scene): array
    {
        $bullets = (array) $scene['bullets'];
        $count = count($bullets);
        $longest = 0;
        foreach ($bullets as $b) {
            $longest = max($longest, mb_strlen((string) $b));
        }

        $ok = ['editorial'];
        if ($count >= 2) {
            $ok[] = 'numbered';
        }
        if ($count >= 2 && $longest <= 90) {
            $ok[] = 'checklist';
        }
        if ($count >= 2 && $count <= 4 && $longest <= 72) {
            $ok[] = 'cards';
        }
        if (trim((string) $scene['heading']) !== '' && $count <= 3 && $longest <= 52) {
            $ok[] = 'statement';
        }

        return $ok;
    }

    private function seededVariant(array $scene, int $index): string
    {
        $ok = $this->compatibleVariants($scene);
        $seed = crc32((string) $scene['scene_id']) + $index * 7;

        return $ok[$seed % count($ok)];
    }

    /**
     * The image_prompt must name a SUBJECT and nothing else — the caller wraps
     * it in the video's own art direction (flat vector, 3 locked colours). A
     * model that also asks for "glowing cinematic 3d lighting" fights that
     * wrapper and produces the muddy generic art this pass exists to avoid, so
     * style vocabulary is stripped rather than trusted.
     */
    private function sanitizeSubject(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }

        // Drop any trailing style clause the model tacked on after the subject.
        $banned = 'glowing|gradients?|cinematic|3d|render(?:ed|ing)?|photo(?:realistic|graphic)?|realistic|'
            . 'lighting|shadows?|neon|moody|dark|vibrant|highly detailed|intricate|sharp focus|'
            . 'depth of field|bokeh|8k|4k|ultra[- ]?hd|hyper[- ]?realistic';

        $clauses = preg_split('/\s*,\s*/', $prompt) ?: [];
        $kept = array_values(array_filter(
            $clauses,
            fn ($clause) => $clause !== '' && !preg_match('/\b(?:' . $banned . ')\b/i', $clause)
        ));

        // If every clause was style noise there is no subject left to draw.
        $clean = trim(implode(', ', $kept), " \t\n\r\0\x0B.,");

        return $this->stripUnrenderable($clean);
    }

    /**
     * The art direction bans text, numbers, letters and watermarks outright
     * (§7.1) — image models cannot spell. A brief that asks for exactly those
     * things ("a large number '500 Million' with search icons", "a stopwatch
     * with a Google logo", "a search bar with the word 'Enter' highlighted")
     * therefore fights the wrapper and the model returns mush or an empty
     * field. The system prompt already forbids it; this is the guarantee.
     *
     * Quoted fragments and brand names are removed rather than the whole
     * brief, so "a large number '500 Million' with search icons" degrades to
     * the still-drawable "search icons".
     */
    private function stripUnrenderable(string $prompt): string
    {
        if ($prompt === '') {
            return '';
        }

        $glyph = 'logos?|brand(?:ing|ed)?|trademarks?|watermarks?|words?|letters?|numbers?|'
            . 'text|spelling|captions?|labels?|subtitles?|headlines?|signage|typography';

        // Quoted glyph requests: the word 'Enter', a number "500 Million".
        $prompt = preg_replace('/["\x{2018}\x{2019}\x{201C}\x{201D}\'][^"\x{2018}\x{2019}\x{201C}\x{201D}\']{1,60}["\x{2018}\x{2019}\x{201C}\x{201D}\']/u', '', $prompt) ?? $prompt;

        // Strip the offending PHRASE, not the whole clause: "a stopwatch with
        // a Google logo" must keep the stopwatch, and "a search bar with the
        // word highlighted" must keep the search bar. Dropping the clause
        // wholesale threw the drawable subject away with the glyph.
        $prompt = preg_replace(
            '/\s*\b(?:with|showing|displaying|featuring|bearing|that (?:says|reads))\s+(?:a|an|the)?[^,]*?\b(?:' . $glyph . ')\b[^,]*/i',
            '',
            $prompt
        ) ?? $prompt;

        // A clause LEADING with a glyph noun ("a large number …", "the words
        // …") loses that fragment — but only the fragment: "a large number
        // with search icons surrounding it" still has search icons to draw.
        $clauses = preg_split('/\s*,\s*/', $prompt) ?: [];
        $kept = [];
        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            // Anything still naming a logo/watermark is unusable outright.
            if (preg_match('/\b(?:logos?|trademarks?|watermarks?)\b/i', $clause)) {
                continue;
            }

            $clause = preg_replace(
                '/^(?:a|an|the)?\s*(?:large|big|huge|small|giant)?\s*(?:' . $glyph . ')\b\s*/i',
                '',
                $clause
            ) ?? $clause;
            // Drop the connector the removed fragment left dangling.
            $clause = trim(preg_replace('/^(?:with|and|of|showing|displaying)\s+/i', '', trim($clause)) ?? $clause);

            if (mb_strlen($clause) >= 8) {
                $kept[] = $clause;
            }
        }

        $clean = trim(implode(', ', $kept), " \t\n\r\0\x0B.,");
        // Collapse the whitespace a removed quote left behind.
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean) ?? $clean);

        // A brief that was ONLY a glyph request has nothing left to draw; the
        // caller falls back to the scene's own heading.
        return mb_strlen($clean) >= 8 ? $clean : '';
    }

    /** The old heuristic brief, kept as the no-LLM safety net. */
    private function fallbackImagePrompt(array $scene): string
    {
        $topic = trim((string) $scene['heading']);
        if ($topic === '' && !empty($scene['bullets'])) {
            $topic = trim((string) $scene['bullets'][0]);
        }
        if ($topic === '') {
            $topic = trim((string) $scene['narration']);
        }
        if ($topic === '') {
            $topic = trim((string) $scene['body']);
        }

        // A bare noun phrase: the caller renders it as "Bold flat vector
        // illustration of <this>." so it must read as a subject, not a brief.
        // Deliberately NOT "a simple object representing …" — "simple object"
        // pushed the model toward one tiny glyph in an empty frame, which is
        // exactly the look this pass is supposed to prevent.
        return 'a bold symbolic scene about ' . mb_substr($topic !== '' ? $topic : 'sharing an idea', 0, 140);
    }

    private function norm(string $word): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($word)) ?? '';
    }
}
