<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\Project;
use Modules\Project\Support\LlmModels;

/**
 * ThumbnailConceptService — what the thumbnail should SAY, before anything
 * draws it.
 *
 * The old thumbnail set the project title in large type next to whatever
 * picture happened to be first, and that is not a thumbnail, it is a title
 * card. A title describes; a thumbnail has to make someone stop scrolling. The
 * two want opposite copy: "How commercial aircraft maintain lift at cruising
 * altitude" is a good title and a terrible thumbnail, where "WHY PLANES DON'T
 * FALL" is the reverse.
 *
 * So one cheap call reads the finished storyboard and returns a CONCEPT — a
 * hook of a few words, the single word in it worth blowing up, an optional
 * number to hang the design on, and which of five layouts fits. The renderer
 * (ThumbnailComp) turns that into the picture.
 *
 * Everything is clamped on the way out and everything has a deterministic
 * fallback derived from the title, so a refusal, a timeout or a garbage reply
 * still produces a usable thumbnail rather than no thumbnail. It runs on the
 * `light` role: this is extraction and compression of copy we already have.
 */
class ThumbnailConceptService
{
    /** The layouts ThumbnailComp knows how to draw. */
    public const LAYOUTS = ['subject_left', 'subject_right', 'stat_hero', 'question', 'versus'];

    private const MAX_HOOK_WORDS = 6;
    private const MAX_HOOK_CHARS = 38;

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('light');
    }

    /**
     * @param  array  $scenes  Ordered scene rows (heading + narration are used).
     * @return array{hook:string,emphasis:string,badge:string,stat:?string,layout:string,vs_left:?string,vs_right:?string}
     */
    public function concept(Project $project, array $scenes): array
    {
        $title = (string) $project->title;
        $fallback = $this->fallback($title);

        try {
            if (empty($this->apiKey) || $scenes === []) {
                return $fallback;
            }

            $digest = $this->digest($title, $scenes);
            $raw = $this->ask($title, $digest);

            return $raw === null ? $fallback : $this->clamp($raw, $fallback);
        } catch (\Throwable $e) {
            Log::warning('ThumbnailConceptService: falling back to the title (' . $e->getMessage() . ')');

            return $fallback;
        }
    }

    /**
     * The headings and the opening lines — enough for the model to know what
     * the video actually promises, without shipping the whole narration.
     */
    private function digest(string $title, array $scenes): string
    {
        $lines = [];
        foreach (array_slice($scenes, 0, 10) as $scene) {
            $heading = '';
            foreach ((array) ($scene['slots'] ?? []) as $slot) {
                $candidate = trim((string) ($slot['heading'] ?? ''));
                if ($candidate !== '') {
                    $heading = $candidate;
                    break;
                }
            }
            $narration = trim((string) ($scene['narration'] ?? ''));
            $line = trim($heading . ($heading !== '' && $narration !== '' ? ' — ' : '') . mb_substr($narration, 0, 150));
            if ($line !== '') {
                $lines[] = '- ' . $line;
            }
        }

        return implode("\n", $lines);
    }

    private function ask(string $title, string $digest): ?array
    {
        $layouts = implode('|', self::LAYOUTS);

        $system = <<<PROMPT
You design YouTube thumbnails. Given a finished explainer video, return the COPY and the LAYOUT for its thumbnail.

Return ONLY JSON:
{"hook": "...", "emphasis": "...", "badge": "...", "stat": "...", "layout": "{$layouts}", "vs_left": "...", "vs_right": "..."}

hook      2-5 words, UPPERCASE, at most 38 characters. NOT the title — the reason to click.
          Promise the payoff or open a gap: "WHY PLANES DON'T FALL", "THE 3-SECOND RULE",
          "IT WAS NEVER RANDOM". No punctuation except ' ? and -. Never say "video", "explained
          in", "everything about", "a guide to", "learn".
emphasis  ONE word copied EXACTLY from hook — the word the design blows up. Pick the word
          carrying the surprise, not an article or preposition.
badge     0-2 words, UPPERCASE, at most 12 characters, or "" for none. A sticker: "EXPLAINED",
          "IN 2 MIN", "STEP BY STEP", "MYTH", "PROVEN".
stat      The single most striking number IN THE CONTENT with its unit — "40,000 FT", "93%",
          "1 IN 8". "" when the video has no number worth showing. Never invent one.
layout    subject_left / subject_right — a picture carries it, copy on the other side.
          stat_hero  — only when stat is set and the number IS the story.
          question   — the hook is a question the viewer wants answered.
          versus     — the video compares exactly two things; fill vs_left and vs_right
                       with 1-2 words each. Otherwise leave both "".

Prefer subject_right. Choose stat_hero or versus only when they genuinely fit.
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(40)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "TITLE: {$title}\n\nSCENES:\n{$digest}"],
                ],
                'temperature' => 0.8,
                'max_tokens' => 220,
                'response_format' => ['type' => 'json_object'],
            ]));

        if (!$response->successful()) {
            throw new \RuntimeException("thumbnail concept request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'thumbnail_concept');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Nothing from the model is trusted: the hook is re-clamped to a length
     * the type can actually hold, `emphasis` must really occur inside the hook
     * (otherwise the renderer would highlight nothing), and an unknown layout
     * degrades to the one that always works.
     */
    private function clamp(array $raw, array $fallback): array
    {
        $hook = $this->normalise((string) ($raw['hook'] ?? ''));
        $words = preg_split('/\s+/u', $hook, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > self::MAX_HOOK_WORDS) {
            $words = array_slice($words, 0, self::MAX_HOOK_WORDS);
            $hook = implode(' ', $words);
        }
        if ($hook === '' || mb_strlen($hook) > self::MAX_HOOK_CHARS) {
            return $fallback;
        }

        // The emphasis word has to be one of the hook's own words — the
        // renderer highlights by matching, so a paraphrase highlights nothing.
        $emphasis = $this->normalise((string) ($raw['emphasis'] ?? ''));
        if ($emphasis === '' || !in_array($emphasis, $words, true)) {
            $emphasis = $this->pickEmphasis($words);
        }

        $badge = mb_substr($this->normalise((string) ($raw['badge'] ?? '')), 0, 12);
        $stat = mb_substr($this->normalise((string) ($raw['stat'] ?? '')), 0, 12);

        $layout = (string) ($raw['layout'] ?? '');
        if (!in_array($layout, self::LAYOUTS, true)) {
            $layout = 'subject_right';
        }

        $left = mb_substr($this->normalise((string) ($raw['vs_left'] ?? '')), 0, 14);
        $right = mb_substr($this->normalise((string) ($raw['vs_right'] ?? '')), 0, 14);

        // A layout that needs a field it did not get would render an empty
        // hole, so it falls back to one that only needs the hook.
        if ($layout === 'versus' && ($left === '' || $right === '')) {
            $layout = 'subject_right';
        }
        if ($layout === 'stat_hero' && $stat === '') {
            $layout = 'subject_right';
        }

        return [
            'hook' => $hook,
            'emphasis' => $emphasis,
            'badge' => $badge,
            'stat' => $stat !== '' ? $stat : null,
            'layout' => $layout,
            'vs_left' => $left !== '' ? $left : null,
            'vs_right' => $right !== '' ? $right : null,
        ];
    }

    /** Uppercase, single-spaced, and stripped of the punctuation the type hates. */
    private function normalise(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s\'?%,.\-]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_strtoupper(trim($value));
    }

    /** The longest word — a decent proxy for the one carrying the meaning. */
    private function pickEmphasis(array $words): string
    {
        $best = '';
        foreach ($words as $word) {
            if (mb_strlen($word) > mb_strlen($best)) {
                $best = $word;
            }
        }

        return $best;
    }

    /**
     * The title, cut down to thumbnail length. Not as good as a written hook,
     * but it is always available and it is what shipped before this service
     * existed — so the floor never drops.
     */
    private function fallback(string $title): array
    {
        $hook = $this->normalise($title);
        $words = preg_split('/\s+/u', $hook, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Drop leading filler so the cut keeps the subject, not the article.
        $filler = ['THE', 'A', 'AN', 'HOW', 'WHAT', 'WHY'];
        while (count($words) > self::MAX_HOOK_WORDS && in_array($words[0], $filler, true)) {
            array_shift($words);
        }
        $words = array_slice($words, 0, self::MAX_HOOK_WORDS);
        $hook = mb_substr(implode(' ', $words), 0, self::MAX_HOOK_CHARS);

        return [
            'hook' => $hook !== '' ? $hook : 'WATCH THIS',
            'emphasis' => $this->pickEmphasis($words),
            'badge' => '',
            'stat' => null,
            'layout' => 'subject_right',
            'vs_left' => null,
            'vs_right' => null,
        ];
    }
}
