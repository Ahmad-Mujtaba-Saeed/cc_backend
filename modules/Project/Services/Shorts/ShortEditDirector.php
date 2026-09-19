<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Log;

/**
 * Writes one short's edit: the hook card, the punch-ins, the meme beats
 * (stickers, emoji, freeze frames, slow-mo, shakes, glitches), the sound cues,
 * which caption words get the accent colour, and the short's title/hashtags.
 *
 * The model (services.openai.shorts_director_model) proposes beats against an
 * indexed transcript plus what the vision pass saw; everything it returns is
 * validated against the short's style (a clean podcast edit never gets a vine
 * boom). Deterministic beats from the audio peaks and reaction frames are laid
 * in wherever the model left a gap, so an edit exists even with no model.
 *
 * Output is on the OUTPUT clock: freezes and slow-mo are built here into the
 * `segments` timeline, and every word and event is mapped through it.
 */
class ShortEditDirector
{
    private const BEAT_TYPES = ['zoom', 'zoom_big', 'shake', 'sticker', 'emoji', 'freeze', 'slowmo', 'bw', 'flash', 'glitch'];

    /** The hook card owns the opening; no context card before this. */
    private const CARD_EARLIEST = 2.6;

    /** Clear air between two context cards. */
    private const CARD_GAP = 1.2;

    private const FREEZE_SECONDS = 0.9;
    private const SLOWMO_SOURCE_SECONDS = 1.1;
    private const SLOWMO_RATE = 0.5;

    /**
     * @param  array<int, array{word: string, start: float, end: float}>  $words  clip-time
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $style  ShortStylePlanner output
     * @return array<string, mixed>
     */
    public function direct(array $words, array $analysis, array $style, float $duration, string $context, int $clipIndex): array
    {
        $words = array_values(array_filter($words, fn ($w) => trim((string) ($w['word'] ?? '')) !== ''));
        $directives = $style['directives'] ?? ['intensity' => 1, 'sfx' => [], 'beats' => ['zoom']];

        $plan = $this->askModel($words, $analysis, $style, $duration, $context);
        $usedModel = $plan !== null;
        $plan ??= [];

        $beats = $this->validBeats($plan['beats'] ?? [], $words, $directives);
        $beats = $this->fillGaps($beats, $words, $analysis, $directives, $duration);
        $keySet = $this->keyWords($plan['key_words'] ?? [], $words, $directives);

        $hook = $this->hook($plan['hook'] ?? null, $words, $style);

        // ---- timeline: freezes and slow-mo become segments ----
        [$segments, $map] = $this->timeline($beats, $duration);
        $outDuration = array_sum(array_map(fn ($s) => $s['dur'], $segments));

        // ---- words on the output clock ----
        $captionWords = [];
        foreach ($words as $i => $w) {
            $start = $map((float) $w['start']);
            $end = max($start + 0.08, $map((float) $w['end']));
            $row = ['text' => trim((string) $w['word']), 'start' => round($start, 3), 'end' => round($end, 3)];
            if (isset($keySet[$i])) {
                $row['key'] = true;
                if (is_string($keySet[$i]) && ($directives['emoji'] ?? false)) {
                    $row['emoji'] = $keySet[$i];
                }
            }
            $captionWords[] = $row;
        }

        $events = $this->events($beats, $words, $map, $style, $analysis, $outDuration);
        $cards = $this->contextCards($plan['context_cards'] ?? [], $words, $map, $duration, $outDuration);
        $events = array_merge($events, $cards);

        Log::info('[SHORTS] Edit directed', [
            'clip' => $clipIndex,
            'style' => $style['family'] ?? '?',
            'model' => $usedModel,
            'beats' => array_count_values(array_map(fn ($b) => $b['type'], $beats)),
            'segments' => count($segments),
            'out_duration' => round($outDuration, 2),
            'context_cards' => count($cards),
        ]);

        return [
            'segments' => $segments,
            'words' => $captionWords,
            'events' => $events,
            'hook' => $hook,
            'duration' => round($outDuration, 3),
            'title' => $this->cleanLine($plan['title'] ?? '', 90) ?: ($hook['text'] ?? ''),
            'hashtags' => $this->hashtags($plan['hashtags'] ?? []),
            'directed_by_model' => $usedModel,
            'beat_count' => count($beats),
            'card_count' => count($cards),
            'narration' => $this->cleanLine($plan['narration'] ?? '', 140),
        ];
    }

    /**
     * How many context cards a short of this length may carry.
     *
     * Deliberately stingy. A card is the editor talking over someone else's
     * video: one or two answer the "what am I even watching" question, and
     * every one after that is a caption competing with the captions.
     */
    public static function cardBudget(float $duration): int
    {
        return $duration >= 40 ? 3 : ($duration >= 22 ? 2 : 1);
    }

    /** How many beats a short of this length and loudness should carry. */
    public static function beatBudget(float $duration, int $intensity): int
    {
        return max(1, (int) round($duration / 10 * (0.8 + 0.7 * $intensity)));
    }

    // ------------------------------------------------------------------ model

    private function askModel(array $words, array $analysis, array $style, float $duration, string $context): ?array
    {
        if (!$words) {
            return null;
        }
        $model = (string) config('services.openai.shorts_director_model', 'gpt-4o-mini');
        $d = $style['directives'];
        $maxBeats = self::beatBudget($duration, (int) $d['intensity']);
        $maxCards = self::cardBudget($duration);

        // Indexed transcript, with a timestamp every few words.
        $parts = [];
        foreach ($words as $i => $w) {
            $stamp = $i % 6 === 0 ? sprintf('{%.1fs}', $w['start']) : '';
            $parts[] = "{$stamp}[{$i}]" . trim((string) $w['word']);
        }
        $indexed = implode(' ', $parts);

        $signals = [];
        foreach ($analysis['reaction_moments'] ?? [] as $r) {
            $signals[] = sprintf('visual reaction at %.1fs: %s (near word [%d])', $r['t'], $r['label'], $this->wordAt($words, (float) $r['t']));
        }
        foreach (array_slice($analysis['audio_peaks'] ?? [], 0, 6) as $p) {
            $signals[] = sprintf('loud moment at %.1fs (+%.0fdB, near word [%d])', $p['t'], $p['over_median'], $this->wordAt($words, (float) $p['t']));
        }

        $beatHelp = [
            'zoom' => 'quick punch-in on the speaker',
            'zoom_big' => 'hard punch-in with a hit sound (the biggest line)',
            'shake' => 'camera shake with a boom (shock, impact)',
            'sticker' => 'big meme text pop, 1-3 words, e.g. "WAIT WHAT", "BRO?!", "NO WAY" (write your own, fitting the moment)',
            'emoji' => 'one emoji pops on screen (💀 😭 😳 🔥 🤯 😂 🧐 💯 ...)',
            'freeze' => 'freeze frame + black&white + record scratch (a moment that deserves a pause)',
            'slowmo' => 'slow motion for dramatic effect',
            'bw' => 'black and white for a second',
            'flash' => 'white flash cut',
            'glitch' => 'digital glitch hit',
        ];
        $allowed = array_values(array_intersect($d['beats'], self::BEAT_TYPES));
        $menu = implode("\n", array_map(fn ($b) => "- {$b}: {$beatHelp[$b]}", $allowed));
        $sfx = $d['sfx'] ? implode(', ', $d['sfx']) : 'none';
        $style_name = $style['name'];
        $summary = trim((string) ($analysis['visual_summary'] ?? ''));
        $type = (string) ($analysis['scene_type'] ?? 'other');
        // The format playbook: what the payoff of THIS kind of video is.
        $playbook = ShortPlaybook::text(ShortPlaybook::key(
            (string) ($analysis['source_format'] ?? ''),
            $type,
            !empty($analysis['webcam'])
        ));
        $streamBrief = "\n\n" . $playbook;

        $prompt = <<<TXT
You are the editor of a viral vertical short (TikTok / YouTube Shorts / Reels). Plan the edit of ONE clip.

Edit style: {$style_name} (intensity {$d['intensity']} of 3). Scene: {$type}. {$summary}
Source video:
{$context}
Clip length: {$duration}s.{$streamBrief}

Transcript — each word has an index in [brackets]; {Ns} marks time:
{$indexed}

Signals from the footage:
{$this->bullets($signals)}

Beats you may use (use ONLY these, at most {$maxBeats} in total, never two within 1.5s, and put them where the moment earns it — a punchline, a reveal, a reaction, a shocking number):
{$menu}
Sound effects you may attach (optional, one per beat): {$sfx}

CONTEXT CARDS. This clip is cut out of a long video, so the viewer arrives with
no idea who these people are or what is going on. Where that MATTERS — and only
there — add a short card in your own voice as the editor, explaining what the
viewer is missing. Good cards:
 - "he's been eating the world's hottest wings for 20 minutes"
 - "the guy in the yellow shirt just beat the record"
 - "this is a self-driving taxi — there's nobody in the front seat"
Rules for cards:
 - at most {$maxCards}, never two on screen at once, each 4-12 words
 - ONLY facts you can see in the footage description or hear in the transcript.
   If you are not sure who someone is or what happened, DO NOT write a card.
   Never guess a name, a number, a place or a relationship.
 - a card that only describes what anyone can SEE ("the streamer is playing a
   game", "intense gameplay in action") is worse than no card. Zero cards is a
   perfectly good answer.
 - never restate what is being said right now — the captions already do that
 - put them where the confusion is, usually early, not on the punchline

Return ONLY JSON:
{
 "hook": {"text": "a scroll-stopping title card for the first 2 seconds, max 7 words, no hashtags", "emoji": "one emoji or empty"},
 "title": "an upload title, max 70 characters",
 "hashtags": ["3-5 relevant hashtags"],
 "key_words": [{"index": 12, "emoji": "optional emoji"}],
 "beats": [{"index": 12, "type": "zoom", "text": "for sticker only", "emoji": "for emoji only", "sfx": "name or empty"}],
 "context_cards": [{"index": 30, "text": "what the viewer is missing", "icon": "one emoji or empty"}],
 "narration": "ONE line a narrator says before the clip plays, 5-14 words, spoken English in the editor's own voice, setting up what is about to happen WITHOUT giving the payoff away (e.g. 'So he thought this boss would be easy... watch this.'). No hashtags, no emojis."
}
key_words: 3-8 words that carry the meaning (numbers, names, strong verbs) — they get the accent colour.
Never invent facts about the people; stickers react to what is SAID or SHOWN.
TXT;

        return ShortsLlm::json($model, [['role' => 'user', 'content' => $prompt]], 1200, 'shorts_edit_director', 'low', 90);
    }

    private function bullets(array $lines): string
    {
        return $lines ? '- ' . implode("\n- ", $lines) : '- (none)';
    }

    private function wordAt(array $words, float $t): int
    {
        $best = 0;
        $bestD = INF;
        foreach ($words as $i => $w) {
            $d = abs((float) $w['start'] - $t);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $i;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------- validation

    /** @return array<int, array<string, mixed>> sorted by time */
    private function validBeats(array $raw, array $words, array $d): array
    {
        $allowed = array_intersect($d['beats'] ?? [], self::BEAT_TYPES);
        $beats = [];
        foreach ($raw as $b) {
            if (!is_array($b)) {
                continue;
            }
            $type = (string) ($b['type'] ?? '');
            $idx = (int) ($b['index'] ?? -1);
            if (!in_array($type, $allowed, true) || !isset($words[$idx])) {
                continue;
            }
            $beat = ['type' => $type, 't' => (float) $words[$idx]['start'], 'word' => $idx, 'source' => 'model'];
            if ($type === 'sticker') {
                $text = $this->cleanLine($b['text'] ?? '', 18);
                if ($text === '') {
                    continue;
                }
                $beat['text'] = $text;
            }
            if ($type === 'emoji') {
                $emoji = $this->cleanEmoji($b['emoji'] ?? '');
                if ($emoji === '') {
                    continue;
                }
                $beat['emoji'] = $emoji;
            }
            $sfx = (string) ($b['sfx'] ?? '');
            if ($sfx !== '' && in_array($sfx, $d['sfx'] ?? [], true)) {
                $beat['sfx'] = $sfx;
            }
            $beats[] = $beat;
        }

        return $this->spaced($beats, 1.5, $d);
    }

    /** Drop beats too close together and cap the expensive ones. */
    private function spaced(array $beats, float $gap, array $d): array
    {
        usort($beats, fn ($a, $b) => $a['t'] <=> $b['t']);
        $out = [];
        $timeBeats = 0;
        foreach ($beats as $b) {
            if ($out && $b['t'] - end($out)['t'] < $gap) {
                continue;
            }
            if (in_array($b['type'], ['freeze', 'slowmo'], true)) {
                // Time-bending beats are strong spice: two per short at most,
                // and only in styles loud enough to carry them.
                if ($timeBeats >= 2 || ($d['intensity'] ?? 0) < 1) {
                    continue;
                }
                $timeBeats++;
            }
            $out[] = $b;
        }

        return $out;
    }

    /** Deterministic beats where the model left room (or when there is no model). */
    private function fillGaps(array $beats, array $words, array $analysis, array $d, float $duration): array
    {
        $intensity = (int) ($d['intensity'] ?? 0);
        $allowed = $d['beats'] ?? [];
        $target = self::beatBudget($duration, $intensity);
        $impact = array_values(array_intersect($d['sfx'] ?? [], ['boom', 'bass_drop', 'snare', 'whoosh_fast', 'whoosh_soft']));
        $pops = array_values(array_intersect($d['sfx'] ?? [], ['pop', 'pop_a', 'ding', 'boing']));

        $candidates = [];
        foreach ($analysis['reaction_moments'] ?? [] as $r) {
            $candidates[] = ['t' => (float) $r['t'], 'why' => 'reaction', 'label' => $r['label'], 'weight' => 3];
        }
        if ($d['punch_on_peaks'] ?? false) {
            foreach ($analysis['audio_peaks'] ?? [] as $p) {
                if ((float) $p['over_median'] >= 8) {
                    $candidates[] = ['t' => (float) $p['t'], 'why' => 'peak', 'weight' => 2];
                }
            }
        }
        // Sentence openers get a gentle punch in every style that zooms.
        foreach ($words as $i => $w) {
            if ($i > 0 && preg_match('/[.!?]$/', (string) $words[$i - 1]['word'])) {
                $candidates[] = ['t' => (float) $w['start'], 'why' => 'sentence', 'weight' => 1];
            }
        }
        usort($candidates, fn ($a, $b) => $b['weight'] <=> $a['weight'] ?: $a['t'] <=> $b['t']);

        $reactionEmoji = ['😳', '💀', '😭', '🤯', '😂', '😬'];
        $hasTimeBeat = (bool) array_filter($beats, fn ($b) => in_array($b['type'], ['freeze', 'slowmo'], true));
        foreach ($candidates as $k => $c) {
            if (count($beats) >= $target) {
                break;
            }
            if ($c['t'] < 0.4 || $c['t'] > $duration - 1.6) {
                continue;
            }
            foreach ($beats as $b) {
                if (abs($b['t'] - $c['t']) < 1.8) {
                    continue 2;
                }
            }
            $beat = ['t' => $c['t'], 'word' => $this->wordAt($words, $c['t']), 'source' => $c['why']];
            if ($c['why'] === 'reaction' && !$hasTimeBeat && $intensity >= 3 && in_array('freeze', $allowed, true)) {
                // The loudest styles earn one freeze on the best reaction.
                $beat['type'] = 'freeze';
                $hasTimeBeat = true;
            } elseif ($c['why'] === 'reaction' && in_array('emoji', $allowed, true) && $intensity >= 2) {
                $beat['type'] = 'emoji';
                $beat['emoji'] = $reactionEmoji[$k % count($reactionEmoji)];
                if ($pops) {
                    $beat['sfx'] = $pops[$k % count($pops)];
                }
            } elseif ($c['why'] !== 'sentence' && in_array('zoom_big', $allowed, true)) {
                $beat['type'] = 'zoom_big';
                if ($impact) {
                    $beat['sfx'] = $impact[$k % count($impact)];
                }
            } elseif (in_array('zoom', $allowed, true)) {
                $beat['type'] = 'zoom';
            } else {
                continue;
            }
            $beats[] = $beat;
        }

        usort($beats, fn ($a, $b) => $a['t'] <=> $b['t']);

        return $beats;
    }

    /** @return array<int, string|bool> word index => true|emoji */
    private function keyWords(array $raw, array $words, array $d): array
    {
        $keys = [];
        foreach ($raw as $k) {
            $idx = is_array($k) ? (int) ($k['index'] ?? -1) : (int) $k;
            if (!isset($words[$idx]) || count($keys) >= 10) {
                continue;
            }
            $emoji = is_array($k) ? $this->cleanEmoji($k['emoji'] ?? '') : '';
            $keys[$idx] = $emoji !== '' ? $emoji : true;
        }
        if (!$keys) {
            // Numbers and long words carry the meaning when nobody told us.
            foreach ($words as $i => $w) {
                $text = preg_replace('/[^\p{L}\p{N}]/u', '', (string) $w['word']);
                if (preg_match('/\d/', $text) || mb_strlen($text) >= 8) {
                    $keys[$i] = true;
                }
                if (count($keys) >= 6) {
                    break;
                }
            }
        }

        return $keys;
    }

    private function hook(mixed $raw, array $words, array $style): ?array
    {
        if (($style['hook']['style'] ?? 'none') === 'none') {
            return null;
        }
        $text = is_array($raw) ? $this->cleanLine($raw['text'] ?? '', 60) : '';
        $emoji = is_array($raw) ? $this->cleanEmoji($raw['emoji'] ?? '') : '';
        if ($text === '') {
            // The opening sentence when it is short enough to read at a
            // glance; otherwise its first words as a teaser.
            $first = [];
            $complete = false;
            foreach ($words as $w) {
                $first[] = trim((string) $w['word']);
                if (preg_match('/[.!?]$/', (string) $w['word'])) {
                    $complete = true;
                    break;
                }
                if (count($first) >= 9) {
                    break;
                }
            }
            $line = $complete && count($first) <= 8
                ? rtrim(implode(' ', $first), '.')
                : rtrim(implode(' ', array_slice($first, 0, 5)), '.,') . '…';
            $text = $this->cleanLine($line, 60);
        }
        if ($text === '') {
            return null;
        }

        return ['text' => $text, 'emoji' => $emoji ?: null, 'until' => 2.2];
    }

    // ---------------------------------------------------------------- timeline

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: \Closure(float): float}
     */
    private function timeline(array $beats, float $duration): array
    {
        $bends = array_values(array_filter($beats, fn ($b) => in_array($b['type'], ['freeze', 'slowmo'], true)));
        usort($bends, fn ($a, $b) => $a['t'] <=> $b['t']);

        $segments = [];
        $cursor = 0.0;
        // Each entry: [srcStart, srcEnd, outStart, rate|null(freeze)]
        $pieces = [];
        $out = 0.0;
        foreach ($bends as $b) {
            $at = min($duration - 0.2, max($cursor, (float) $b['t']));
            if ($at > $cursor + 0.05) {
                $segments[] = ['src' => round($cursor, 3), 'dur' => round($at - $cursor, 3), 'rate' => 1];
                $pieces[] = [$cursor, $at, $out, 1.0];
                $out += $at - $cursor;
            }
            if ($b['type'] === 'freeze') {
                $segments[] = ['src' => round($at, 3), 'dur' => self::FREEZE_SECONDS, 'rate' => 1, 'freeze' => true];
                $pieces[] = [$at, $at, $out, null];
                $out += self::FREEZE_SECONDS;
                $cursor = $at;
            } else {
                $end = min($duration, $at + self::SLOWMO_SOURCE_SECONDS);
                $dur = ($end - $at) / self::SLOWMO_RATE;
                $segments[] = ['src' => round($at, 3), 'dur' => round($dur, 3), 'rate' => self::SLOWMO_RATE];
                $pieces[] = [$at, $end, $out, self::SLOWMO_RATE];
                $out += $dur;
                $cursor = $end;
            }
        }
        if ($duration - $cursor > 0.05 || !$segments) {
            $segments[] = ['src' => round($cursor, 3), 'dur' => round(max(0.1, $duration - $cursor), 3), 'rate' => 1];
            $pieces[] = [$cursor, $duration, $out, 1.0];
        }

        $map = function (float $t) use ($pieces): float {
            $best = 0.0;
            foreach ($pieces as [$s, $e, $o, $rate]) {
                if ($rate === null) {
                    // A freeze sits AT its source time; content after it shifts.
                    if ($t > $s) {
                        continue;
                    }
                    return $best;
                }
                if ($t >= $s && $t <= $e) {
                    return $o + ($t - $s) / $rate;
                }
                if ($t > $e) {
                    $best = $o + ($e - $s) / $rate;
                }
            }

            return $best;
        };

        return [$segments, $map];
    }

    // ------------------------------------------------------------------ events

    /**
     * The model's context cards, validated into timed `context` events.
     *
     * These are the one place the editor puts words on screen that nobody said,
     * so the checks are about TRUTHFULNESS and CROWDING, not taste:
     *
     *  - a card anchors to a word index, so it appears while the thing it
     *    explains is happening rather than at a time the model invented;
     *  - cards never overlap each other and never sit under the hook card,
     *    which owns the opening seconds;
     *  - the budget is enforced here as well as asked for in the prompt;
     *  - a card that is really a caption — the same words that are being
     *    spoken around it — is dropped, because the captions already say it.
     *
     * @param  array<int, array<string, mixed>>  $words
     * @return array<int, array<string, mixed>>
     */
    /**
     * A card that narrates the obvious. Project 195 shipped "The streamer is
     * playing a game." and "Intense Wolverine gameplay in action" — both tell a
     * viewer what they are already looking at, which reads as filler and makes
     * the short look auto-generated.
     */
    public static function isFiller(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match(
            '/\b(is|are|was) (playing|gaming|streaming|reacting|talking)\b'
            . '|\bgameplay( is)? (in action|continues|moment)\b|\bintense (gameplay|moment|action|battle|fight)\b'
            . '|\bplaying (a|the|some|this) (video ?)?game\b|\b(epic|exciting|crazy|intense) (gaming|stream) (moment|session)\b'
            . '|^(the )?(streamer|player|gamer|he|she|they) (is|are) (here|live|online|on stream)\b/u',
            $t
        );
    }

    private function contextCards(mixed $raw, array $words, \Closure $map, float $duration, float $outDuration): array
    {
        if (!is_array($raw) || !$words) {
            return [];
        }

        $budget = self::cardBudget($duration);
        $cards = [];
        foreach ($raw as $c) {
            if (count($cards) >= $budget || !is_array($c)) {
                continue;
            }
            $text = $this->cleanLine($c['text'] ?? '', 90);
            $wordCount = str_word_count($text);
            if ($text === '' || $wordCount < 3 || $wordCount > 14) {
                continue;
            }

            $index = (int) ($c['index'] ?? 0);
            $index = max(0, min(count($words) - 1, $index));
            $at = $map((float) $words[$index]['start']);

            // The hook owns the opening; a card under it is two cards at once.
            $at = max(self::CARD_EARLIEST, $at);
            $length = max(2.2, min(4.0, $wordCount * 0.42));
            if ($at + $length > $outDuration - 0.4) {
                $at = $outDuration - 0.4 - $length;
            }
            if ($at < self::CARD_EARLIEST) {
                continue;
            }

            // No stacking: a card waits for the previous one to clear.
            foreach ($cards as $prev) {
                if ($at < $prev['end'] + self::CARD_GAP) {
                    $at = $prev['end'] + self::CARD_GAP;
                }
            }
            if ($at + $length > $outDuration - 0.4) {
                continue;
            }

            if (self::isFiller($text)) {
                continue;
            }

            if ($this->echoesSpeech($text, $words, $index)) {
                continue;
            }

            $cards[] = [
                'type' => 'context',
                'start' => round($at, 3),
                'end' => round($at + $length, 3),
                'text' => $text,
                'icon' => $this->cleanEmoji($c['icon'] ?? ''),
                'place' => count($cards) % 2 === 0 ? 'top' : 'bottom',
            ];
        }

        return array_map(function ($c) {
            if ($c['icon'] === '') {
                unset($c['icon']);
            }

            return $c;
        }, $cards);
    }

    /**
     * Is this card just repeating what is being said around it?
     *
     * Compares the card's content words against the spoken words in its
     * neighbourhood. A card that is mostly the same words is a caption with
     * extra steps — and worse, it reads as if the editor is putting the
     * speaker's line in their own mouth.
     *
     * @param  array<int, array<string, mixed>>  $words
     */
    private function echoesSpeech(string $text, array $words, int $index): bool
    {
        $near = [];
        for ($i = max(0, $index - 12); $i < min(count($words), $index + 12); $i++) {
            $near[] = mb_strtolower(trim((string) $words[$i]['word']), 'UTF-8');
        }
        $near = array_flip(array_map(fn ($w) => preg_replace('/[^a-z0-9]/', '', $w), $near));

        $content = array_values(array_filter(
            preg_split('/\W+/', mb_strtolower($text, 'UTF-8')) ?: [],
            fn ($w) => strlen($w) > 3
        ));
        if (count($content) < 3) {
            return false;
        }

        $hits = 0;
        foreach ($content as $w) {
            if (isset($near[$w])) {
                $hits++;
            }
        }

        return $hits / count($content) >= 0.6;
    }

    private function events(array $beats, array $words, \Closure $map, array $style, array $analysis, float $outDuration): array
    {
        $d = $style['directives'];
        $events = [];
        $stickerSlots = [0.3, 0.42, 0.26];
        $emojiSlots = [[0.78, 0.28], [0.22, 0.32], [0.8, 0.46], [0.2, 0.5]];
        $n = 0;

        foreach ($beats as $b) {
            $t = $map((float) $b['t']);
            $sfx = $b['sfx'] ?? null;
            switch ($b['type']) {
                case 'zoom':
                    $events[] = ['type' => 'zoom', 'start' => $t - 0.04, 'end' => $t + 1.0, 'scale' => 1.18];
                    break;
                case 'zoom_big':
                    $events[] = ['type' => 'zoom', 'start' => $t - 0.04, 'end' => $t + 1.1, 'scale' => 1.42];
                    if (($d['intensity'] ?? 0) >= 2) {
                        $events[] = ['type' => 'shake', 'start' => $t, 'end' => $t + 0.35, 'intensity' => 0.6];
                    }
                    $sfx ??= $this->firstOf($d, ['boom', 'bass_drop', 'snare', 'whoosh_fast']);
                    break;
                case 'shake':
                    $events[] = ['type' => 'shake', 'start' => $t, 'end' => $t + 0.55, 'intensity' => 1.0];
                    $events[] = ['type' => 'zoom', 'start' => $t, 'end' => $t + 0.6, 'scale' => 1.12];
                    $sfx ??= $this->firstOf($d, ['boom', 'bass_drop']);
                    break;
                case 'sticker':
                    $events[] = [
                        'type' => 'sticker', 'start' => $t, 'end' => $t + 1.3, 'text' => $b['text'],
                        'x' => 0.5, 'y' => $stickerSlots[$n % count($stickerSlots)],
                        'rotate' => ($n % 2 ? 4 : -5), 'variant' => ($style['family'] === 'comic_pop' ? 'bubble' : ($style['family'] === 'news_flash' ? 'label' : 'impact')),
                    ];
                    $sfx ??= $this->firstOf($d, ['pop', 'ding', 'swipe']);
                    break;
                case 'emoji':
                    [$ex, $ey] = $emojiSlots[$n % count($emojiSlots)];
                    $events[] = ['type' => 'emoji', 'start' => $t, 'end' => $t + 1.4, 'emoji' => $b['emoji'], 'x' => $ex, 'y' => $ey, 'size' => 0.17];
                    $sfx ??= $this->firstOf($d, ['pop', 'boing']);
                    break;
                case 'freeze':
                    // $t is where the freeze begins on the output clock.
                    $events[] = ['type' => 'bw', 'start' => $t, 'end' => $t + self::FREEZE_SECONDS];
                    $events[] = ['type' => 'zoom', 'start' => $t, 'end' => $t + self::FREEZE_SECONDS, 'scale' => 1.3];
                    $events[] = ['type' => 'shake', 'start' => $t, 'end' => $t + 0.25, 'intensity' => 0.5];
                    $sfx ??= $this->firstOf($d, ['record_scratch', 'shutter']);
                    break;
                case 'slowmo':
                    $events[] = ['type' => 'zoom', 'start' => $t, 'end' => $t + self::SLOWMO_SOURCE_SECONDS / self::SLOWMO_RATE, 'scale' => 1.22];
                    $sfx ??= $this->firstOf($d, ['heartbeat', 'riser', 'whoosh_soft']);
                    break;
                case 'bw':
                    $events[] = ['type' => 'bw', 'start' => $t, 'end' => $t + 1.1];
                    break;
                case 'flash':
                    $events[] = ['type' => 'flash', 'start' => $t];
                    $sfx ??= $this->firstOf($d, ['shutter', 'whoosh_fast']);
                    break;
                case 'glitch':
                    $events[] = ['type' => 'glitch', 'start' => $t, 'end' => $t + 0.4];
                    $events[] = ['type' => 'flash', 'start' => $t, 'color' => '#00f0ff', 'duration' => 0.1];
                    $sfx ??= $this->firstOf($d, ['glitch']);
                    break;
            }
            if ($sfx) {
                $events[] = ['type' => 'sfx', 'start' => max(0.0, $t - 0.03), 'name' => $sfx];
            }
            $n++;
        }

        // Scene cuts get the style's transition — but only where a cut is an
        // event. Footage that already cuts every couple of seconds (montages,
        // gameplay, pre-edited sources) would turn into a strobe, so dense
        // cutting gets no transitions, and the rest are spaced and capped.
        $transition = $d['cut_transition'] ?? 'none';
        $cuts = $analysis['scene_cuts'] ?? [];
        $cutRate = count($cuts) / max(1.0, $outDuration);
        if ($transition !== 'none' && $cutRate <= 1 / 4.0) {
            $placed = 0;
            $lastCut = -99.0;
            foreach ($cuts as $cut) {
                if ($placed >= 4 || (float) $cut - $lastCut < 5.0) {
                    continue;
                }
                $lastCut = (float) $cut;
                $placed++;
                $t = $map((float) $cut);
                if ($t < 0.5 || $t > $outDuration - 0.5) {
                    continue;
                }
                foreach ($events as $e) {
                    if (abs(($e['start'] ?? -9) - $t) < 0.8) {
                        continue 2;
                    }
                }
                if ($transition === 'flash') {
                    $events[] = ['type' => 'flash', 'start' => $t, 'duration' => 0.12];
                } elseif ($transition === 'glitch') {
                    $events[] = ['type' => 'glitch', 'start' => $t, 'end' => $t + 0.25];
                } else {
                    $events[] = ['type' => 'zoom', 'start' => $t, 'end' => $t + 0.5, 'scale' => 1.1];
                }
                if ($d['sfx'] ?? []) {
                    $events[] = ['type' => 'sfx', 'start' => $t, 'name' => $this->firstOf($d, ['whoosh_fast', 'swipe', 'whoosh_soft', 'glitch']) ?? $d['sfx'][0], 'volume' => 0.6];
                }
            }
        }

        // The hook lands with a sound when the style has one.
        if (($d['intensity'] ?? 0) >= 1 && ($d['sfx'] ?? [])) {
            $events[] = ['type' => 'sfx', 'start' => 0.05, 'name' => $this->firstOf($d, ['whoosh_fast', 'swipe', 'pop', 'whoosh_soft']) ?? $d['sfx'][0], 'volume' => 0.7];
        }

        // Round and clamp to the short.
        foreach ($events as &$e) {
            foreach (['start', 'end'] as $k) {
                if (isset($e[$k])) {
                    $e[$k] = round(max(0.0, min($outDuration, (float) $e[$k])), 3);
                }
            }
        }
        unset($e);
        usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $events;
    }

    private function firstOf(array $d, array $prefs): ?string
    {
        foreach ($prefs as $p) {
            if (in_array($p, $d['sfx'] ?? [], true)) {
                return $p;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ text

    private function cleanLine(mixed $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) (is_scalar($text) ? $text : '')) ?? '');
        $text = trim($text, " \"'`");

        return mb_substr($text, 0, $max);
    }

    private function cleanEmoji(mixed $emoji): string
    {
        $emoji = trim((string) (is_scalar($emoji) ? $emoji : ''));
        if ($emoji === '' || mb_strlen($emoji) > 4 || preg_match('/[\p{L}\p{N}]/u', $emoji)) {
            return '';
        }

        return $emoji;
    }

    private function hashtags(mixed $raw): array
    {
        $tags = [];
        foreach ((array) $raw as $t) {
            $t = preg_replace('/[^\p{L}\p{N}_]/u', '', (string) (is_scalar($t) ? $t : ''));
            if ($t !== '' && mb_strlen($t) <= 30) {
                $tags['#' . $t] = true;
            }
            if (count($tags) >= 5) {
                break;
            }
        }

        return array_keys($tags);
    }
}
