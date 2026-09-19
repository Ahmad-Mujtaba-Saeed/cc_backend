<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Log;

/**
 * What the WHOLE source video is, read once per project.
 *
 * Every short used to be edited from its own 30-60 seconds plus the video's
 * title, so the director had to guess who was talking and why it mattered —
 * and when it guessed, it wrote cards like "The streamer is playing a game."
 * One cheap call over the title and a sample of the full transcript gives
 * every short the same grounding: what kind of video this is, who is in it,
 * what it is about, and what is at stake.
 *
 * ~$0.002 on gpt-4o-mini. Failure is harmless: an empty brief means the old
 * behaviour (title only).
 */
class VideoBriefService
{
    public const FORMATS = [
        'podcast', 'interview', 'livestream_gaming', 'livestream_irl', 'vlog', 'talking_head',
        'educational', 'reaction', 'sports', 'tutorial', 'keynote', 'comedy', 'news', 'other',
    ];

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @return array<string, mixed>  empty on failure
     */
    public function build(string $title, array $segments, float $duration): array
    {
        if (!$segments) {
            return [];
        }
        $model = (string) config('services.openai.shorts_director_model', 'gpt-4o-mini');

        $prompt = <<<TXT
You are preparing an editor to cut viral vertical shorts out of a long video. Read the title and the transcript sample and describe the SOURCE VIDEO.

Title: {$title}
Length: {$this->minutes($duration)} minutes
Transcript sample (timestamps in minutes):
{$this->sample($segments)}

Return ONLY JSON:
{
 "format": "one of: podcast, interview, livestream_gaming, livestream_irl, vlog, talking_head, educational, reaction, sports, tutorial, keynote, comedy, news, other",
 "summary": "one sentence: what this video is",
 "people": [{"name": "name ONLY if said in the transcript or title, else empty", "role": "host / guest / streamer / chat / caller ..."}],
 "topic": "the subject, or the game being played",
 "stakes": "what the viewer should care about (a challenge, a debate, a record, a boss fight) — empty if none",
 "tone": "hype | funny | serious | emotional | educational | chill | chaotic",
 "audience": "who watches this (gamers, tech people, sports fans ...)"
}
Never invent names. If you are not sure, leave a field empty.
TXT;

        $brief = ShortsLlm::json($model, [['role' => 'user', 'content' => $prompt]], 500, 'shorts_video_brief', 'low', 60);
        if (!is_array($brief)) {
            Log::warning('[SHORTS] Video brief unavailable');
            return [];
        }

        $format = (string) ($brief['format'] ?? 'other');
        $people = [];
        foreach ((array) ($brief['people'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 40);
            $role = mb_substr(trim((string) ($p['role'] ?? '')), 0, 30);
            if ($name !== '' || $role !== '') {
                $people[] = ['name' => $name, 'role' => $role];
            }
        }

        return [
            'format' => in_array($format, self::FORMATS, true) ? $format : 'other',
            'summary' => mb_substr(trim((string) ($brief['summary'] ?? '')), 0, 240),
            'people' => array_slice($people, 0, 5),
            'topic' => mb_substr(trim((string) ($brief['topic'] ?? '')), 0, 80),
            'stakes' => mb_substr(trim((string) ($brief['stakes'] ?? '')), 0, 160),
            'tone' => mb_substr(trim((string) ($brief['tone'] ?? '')), 0, 20),
            'audience' => mb_substr(trim((string) ($brief['audience'] ?? '')), 0, 60),
        ];
    }

    /** The brief as a few lines for another prompt. */
    public static function describe(array $brief, string $title): string
    {
        $lines = ["Title: {$title}"];
        if (!$brief) {
            return $lines[0];
        }
        $lines[] = 'Format: ' . str_replace('_', ' ', $brief['format']) . ($brief['summary'] ? " — {$brief['summary']}" : '');
        $people = array_map(
            fn ($p) => trim(($p['name'] ?: 'unnamed') . ($p['role'] ? " ({$p['role']})" : '')),
            $brief['people'] ?? []
        );
        if ($people) {
            $lines[] = 'People: ' . implode(', ', $people);
        }
        foreach (['topic' => 'Topic', 'stakes' => 'Stakes', 'tone' => 'Tone', 'audience' => 'Audience'] as $k => $label) {
            if (!empty($brief[$k])) {
                $lines[] = "{$label}: {$brief[$k]}";
            }
        }

        return implode("\n", $lines);
    }

    private function minutes(float $seconds): string
    {
        return (string) max(1, (int) round($seconds / 60));
    }

    /**
     * ~40 evenly spaced lines from across the whole video: the opening (where
     * people introduce themselves) gets a denser share.
     */
    private function sample(array $segments): string
    {
        $n = count($segments);
        $pick = range(0, min(11, $n - 1));
        for ($i = 0; $i < 30; $i++) {
            $pick[] = (int) floor(($i + 0.5) * $n / 30);
        }
        $pick = array_values(array_unique(array_filter($pick, fn ($i) => $i < $n)));
        sort($pick);
        $out = [];
        foreach ($pick as $i) {
            $s = $segments[$i];
            $out[] = sprintf('[%.1f] %s', $s['start'] / 60, mb_substr(trim((string) $s['text']), 0, 140));
        }

        return implode("\n", $out);
    }
}
