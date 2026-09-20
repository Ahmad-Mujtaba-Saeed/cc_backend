<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Support\LlmModels;

/**
 * SlotImageBriefService — turns a media slot's one-line label into a SHOT.
 *
 * `asset_request.description` is the image prompt (see {@see MediaBrief}), and
 * the composer writes it while it is busy writing nine other things: what
 * comes out is "An image of a network diagram", "recycling process", or — when
 * the validator has to invent a picture for a text-only beat — "A real
 * photograph of network finds bank issued, plain and documentary". None of
 * those describe anything. The image model fills the gap with whatever it
 * likes, and the user sees a generic picture and a one-line prompt in the
 * storyboard's Generate panel.
 *
 * This pass reads the beat — the video's subject, the scene's own narration,
 * the slot's heading — and rewrites the description as a specific shot: the
 * objects in frame, where they are, what is happening, and the one detail that
 * ties it to THIS video.
 *
 * It writes the SAME field the user edits and the render generates from, so
 * there is still exactly one prompt per slot ({@see ExplainerImagePrompt}) and
 * the cache hash stays stable. Style, palette and the no-text rule are added
 * later by the prompt builder and must never appear here.
 */
class SlotImageBriefService
{
    /** Briefs per call. A long video's slots are chunked across several. */
    private const BATCH = 12;

    /** Anything shorter than this is a label, not a shot. */
    public const THIN = 48;

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Openers the composer and the validator both reach for. They say nothing
     * about the picture, and left in place they push the image model toward a
     * stock-photo cliché.
     */
    private const FILLER = [
        '/^an?\s+(image|picture|photo|photograph|illustration|visual|shot|graphic)\s+(of|showing|depicting)\s+/i',
        '/^a\s+real\s+photograph\s+of\s+/i',
        '/^(a\s+)?visual\s+(of|for)\s+/i',
        '/^(an?\s+)?(visual\s+)?(representation|depiction|illustration|graphic|diagram|animation|render(ing)?)\s+'
        . '(of|showing|representing|illustrating)\s+/i',
        '/^(an?\s+)?animated\s+/i',
        '/,?\s*plain and documentary[^.]*/i',
        '/,?\s*no text or graphics in frame\.?/i',
    ];

    /** Strip the filler an opener adds, so what is left is the subject. */
    public static function bare(string $description): string
    {
        $text = trim($description);
        foreach (self::FILLER as $pattern) {
            $text = (string) preg_replace($pattern, '', $text);
        }

        return trim($text, " \t\n\r\0\x0B.,");
    }

    /**
     * Does this slot still need a real shot written for it? Short, or nothing
     * but the opener plus two words.
     */
    public static function isThin(array $slot): bool
    {
        $description = (string) ($slot['asset_request']['description'] ?? '');
        if (trim($description) === '') {
            return true;
        }
        $bare = self::bare($description);

        return mb_strlen($bare) < self::THIN || str_word_count($bare) < 7;
    }

    /**
     * Rewrite every thin image-slot description in a raw storyboard.
     *
     * Scene keys are preserved (a revision hands over drafts keyed by scene
     * id). Never throws, never blocks: a slot whose brief does not come back
     * keeps the words the composer gave it.
     *
     * @param  bool $all  true rewrites every image slot, not just the thin ones
     */
    public function enrichAll(array $parsed, string $topic = '', bool $all = false): array
    {
        $scenes = (array) ($parsed['scenes'] ?? []);
        if ($scenes === [] || !$this->available()) {
            return $parsed;
        }

        $jobs = [];
        foreach ($scenes as $si => $scene) {
            if (!is_array($scene)) {
                continue;
            }
            foreach ((array) ($scene['slots'] ?? []) as $key => $slot) {
                if (!is_array($slot) || ($slot['content_type'] ?? '') !== 'image') {
                    continue;
                }
                // An uploaded or stock-backed slot already has its picture.
                if (!empty($slot['asset_ref'])) {
                    continue;
                }
                if (!$all && !self::isThin($slot)) {
                    continue;
                }
                $jobs[] = [
                    'id' => count($jobs) + 1,
                    'si' => $si,
                    'key' => $key,
                    'heading' => mb_substr(trim((string) ($slot['heading'] ?? $slot['label'] ?? '')), 0, 60),
                    'current' => self::bare((string) ($slot['asset_request']['description'] ?? '')),
                    'narration' => mb_substr(trim((string) (
                        is_array($scene['narration'] ?? null)
                            ? ($scene['narration']['text'] ?? '')
                            : ($scene['narration'] ?? '')
                    )), 0, 240),
                ];
            }
        }

        if ($jobs === []) {
            return $parsed;
        }

        foreach (array_chunk($jobs, self::BATCH) as $chunk) {
            $briefs = $this->write($chunk, $topic);
            foreach ($chunk as $job) {
                $brief = $briefs[$job['id']] ?? null;
                if ($brief === null) {
                    continue;
                }
                $scenes[$job['si']]['slots'][$job['key']]['asset_request']['description'] = $brief;
            }
        }

        $parsed['scenes'] = $scenes;

        return $parsed;
    }

    /**
     * One shot for ONE slot, written on demand (the storyboard's Generate
     * panel). Returns null when there is nothing better to say.
     */
    public function brief(string $current, string $narration, string $heading, string $topic): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $briefs = $this->write([[
            'id' => 1,
            'heading' => mb_substr($heading, 0, 60),
            'current' => self::bare($current),
            'narration' => mb_substr($narration, 0, 240),
        ]], $topic);

        return $briefs[1] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>> $jobs
     * @return array<int, string>  brief by job id
     */
    private function write(array $jobs, string $topic): array
    {
        $lines = [];
        foreach ($jobs as $job) {
            $lines[] = json_encode([
                'id' => $job['id'],
                'heading' => $job['heading'],
                'narration' => $job['narration'],
                'current' => $job['current'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => "VIDEO: " . mb_substr($topic, 0, 160) . "\nSLOTS:\n" . implode("\n", $lines)],
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 1200,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));
        } catch (\Throwable $e) {
            Log::info('SlotImageBriefService: request failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (!$response->successful()) {
            Log::info('SlotImageBriefService: request failed', ['status' => $response->status()]);

            return [];
        }
        CostTracker::recordChat($this->model, $response->json('usage'), 'slot_image_brief');

        $decoded = json_decode((string) $response->json('choices.0.message.content'), true);
        $shots = is_array($decoded['shots'] ?? null) ? $decoded['shots'] : (is_array($decoded) ? $decoded : []);

        $out = [];
        foreach ($shots as $key => $shot) {
            $id = (int) (is_array($shot) ? ($shot['id'] ?? $key) : $key);
            $text = trim((string) (is_array($shot) ? ($shot['shot'] ?? $shot['description'] ?? '') : $shot));
            $clean = self::clean($text);
            if ($id > 0 && $clean !== '') {
                $out[$id] = $clean;
            }
        }

        return $out;
    }

    /**
     * Keep it a subject: one line, no opener, no style words (the prompt
     * builder owns those, and a brief that names its own colours fights the
     * video's palette), capped so it stays a shot and not a paragraph.
     */
    private static function clean(string $shot): string
    {
        $text = self::bare(preg_replace('/\s+/u', ' ', strip_tags($shot)) ?? '');

        // A camera word is art direction, and the shot list owns framing.
        $text = (string) preg_replace(
            '/^(an?\s+)?(extreme\s+)?(close[- ]?up|wide|aerial|overhead|macro|birds?[- ]?eye|low[- ]angle|top[- ]down|side)\s+'
            . '(shot\s+|view\s+)?(of|on)\s+/i',
            '',
            $text
        );

        // Style, quality and lighting words fight the video's own art
        // direction, which the prompt builder adds after this.
        $text = (string) preg_replace(
            '/\b(flat vector|vector illustration|minimalist|photorealistic|4k|8k|ultra detailed|cinematic lighting|'
            . 'high quality|trending on artstation|octane render|hyperrealistic)\b[,.]?/i',
            '',
            $text
        );
        $text = (string) preg_replace(
            '/,?\s*\b(in|under|with|lit by)\s+(an?\s+|the\s+)?(bright|soft|warm|cool|cold|dim|natural|studio|'
            . 'dramatic|golden[- ]hour|morning|evening)\s+(light|lighting|sunlight|sun)\b[^,]*/i',
            '',
            $text
        );

        // A clause that puts WRITING in the frame: the image model cannot
        // spell, so a visible tag, sign or label always comes back as
        // scribble. Drop that clause and keep the rest of the shot.
        $parts = array_filter(
            array_map('trim', explode(',', $text)),
            fn ($part) => preg_match(
                '/\b(tags?|labell?(ed|ing)?|signs?|signage|logos?|text|words?|letters?|numbers?|captions?|writing|'
                . 'headline|title|screens? (showing|displaying|reading))\b/i',
                $part
            ) !== 1
        );
        $text = implode(', ', $parts);

        $text = trim((string) preg_replace('/\s{2,}/', ' ', $text), " \t\n\r\0\x0B,;");
        if (mb_strlen($text) > 240) {
            $text = rtrim(mb_substr($text, 0, 240), " ,;");
        }

        return str_word_count($text) >= 5 ? $text : '';
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You write the SHOT for a picture in an explainer video: what the frame actually contains.

You are given the video's subject and, for each slot, the narration of that exact beat, the card's heading and the one-line placeholder the planner left behind ("An image of a network diagram", "recycling process"). Replace that placeholder with a shot a person could set up without asking a single question.

Return ONLY JSON: {"shots": [{"id": <the slot's id>, "shot": "<the shot>"}, ...]} — one entry per slot given, same ids.

A SHOT
- 12 to 30 words, one sentence, present tense.
- Name the OBJECTS in the frame, where they are in relation to each other, and what is happening to them right now.
- Be specific to THIS video and THIS beat: pull the real thing the narration is talking about (the card terminal on the shop counter, the bale of crushed bottles on the conveyor), not the category it belongs to.
- One scene, one moment. Not a sequence, not a collage, not "and then".
- Concrete nouns only. No abstractions ("innovation", "the process", "data flowing"), no metaphors the model cannot draw.

NEVER
- No words, letters, numbers, labels, signs, logos, brands or user interface text — the image model cannot spell and every attempt comes back as gibberish.
- No style, medium, palette, lighting or camera words: no "flat vector", "minimalist", "photorealistic", "4k", "close-up shot of", "cinematic lighting", no colours. The video's own art direction is added afterwards and yours would fight it.
- No named real people, no celebrities, no copyrighted characters.
- It is ONE still frame. Nothing moves, nothing animates, nothing "flows" — if the beat is about movement, draw the moment it can be seen in (the hand mid-tap, the bale on the belt), not the movement itself.
- Never "a visual representation of", "a graphic showing", "a diagram representing", "the concept of". Those are ways of not deciding what is in the frame. Decide.
- Do not start with "An image of", "A photograph of", "A visual of". Start with the subject itself.

EXAMPLES
narration: "The card terminal reads your card and builds a small request. It does not send your card number in the clear."
shot: "A bank card held against a card terminal on a shop counter, the terminal screen facing the viewer, a shopper's hand steady above it"

narration: "What is left is a pure enough pile of one plastic, and it gets squashed into a bale the size of a washing machine."
shot: "A cube-shaped bale of crushed plastic bottles bound with wire, sitting on a warehouse floor beside a forklift"

narration: "It flows back into the left atrium, down into the left ventricle, and out through the aorta to every part of your body."
shot: "A cross-section of a human heart with the left chambers open and the aorta curving away from the top"
PROMPT;
    }
}
