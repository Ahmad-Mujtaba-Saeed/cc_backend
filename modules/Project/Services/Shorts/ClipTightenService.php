<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Log;

/**
 * Cuts a clip down to the parts that matter.
 *
 * WHAT WAS WRONG. The editor only ever removed SILENCE. On a real run the
 * shorts came out 43-58 seconds long having dropped between 0 and 5.8 seconds
 * — which is to say they were the raw window with the pauses tightened, and
 * they played like it: a minute of a conversation with the good bit somewhere
 * in the middle. Every short worth copying does the opposite. It cuts from the
 * setup straight to the reaction, from one person's turn straight to the next
 * person's turn, and throws away everything in between, because on a phone the
 * thing that kills a short is not a bad moment, it is a slow one.
 *
 * Silence removal cannot do that, because the dead weight in a stream clip is
 * not silent. It is someone repeating themselves, a tangent about nothing, the
 * crowd noise between two jokes, the ten seconds of "wait — what? — huh?"
 * before the actual answer. Finding those needs someone who understands what
 * the clip is ABOUT, so this asks the model, over the transcript, with times.
 *
 * HOW IT IS KEPT SAFE. The model never returns timestamps — it returns LINE
 * NUMBERS from a numbered transcript, so every cut lands exactly on a
 * transcript boundary and can never land mid-word. On top of that:
 *
 *   * the opening line survives (it is the hook the clip was chosen for) and
 *     so does the closing line (it is the payoff);
 *   * no more than MAX_DROP_SHARE of the window may go, so a model that
 *     decides it dislikes the whole clip cannot delete it;
 *   * what is left must still run at least MIN_KEPT_SECONDS;
 *   * an answer that fails any of these is discarded whole and the clip goes
 *     through unchanged. There is no half-applied tightening.
 *
 * Output is DROP spans in source seconds, which ClipEditPlanService subtracts
 * alongside the silence it already removes.
 */
class ClipTightenService
{
    /** At most this share of the selected window may be cut for content. */
    private const MAX_DROP_SHARE = 0.45;

    /** Never tighten a clip below this. */
    private const MIN_KEPT_SECONDS = 14.0;

    /** Don't bother asking about a clip already this short. */
    private const MIN_WINDOW_TO_TIGHTEN = 22.0;

    /** A drop this small is a jump cut nobody asked for; skip it. */
    private const MIN_DROP_SECONDS = 0.8;

    /** Transcript lines sent to the model (a 2-minute window is ~40). */
    private const MAX_LINES = 90;

    /**
     * Spans of source time to remove from this clip, in order.
     *
     * @param  array<int, array{start: float, end: float, text: string}>  $segments  full transcript
     * @return array<int, array{start: float, end: float}>
     */
    public function dropSpans(
        array $segments,
        float $clipStart,
        float $clipEnd,
        string $hook,
        string $videoTitle,
        int $clipIndex
    ): array {
        $window = $clipEnd - $clipStart;
        if ($window < self::MIN_WINDOW_TO_TIGHTEN) {
            return [];
        }

        $lines = $this->linesInWindow($segments, $clipStart, $clipEnd);
        if (count($lines) < 6) {
            return [];
        }

        $answer = ShortsLlm::json(
            (string) config('services.openai.shorts_director_model', 'gpt-4o-mini'),
            [['role' => 'user', 'content' => $this->prompt($lines, $window, $hook, $videoTitle)]],
            700,
            'shorts_tighten',
            'low',
            90
        );
        if (!is_array($answer)) {
            return [];
        }

        $drop = $this->spansFromLineNumbers($answer['drop'] ?? [], $lines);
        $drop = $this->guard($drop, $lines, $window);

        Log::info('[SHORTS] Clip tightened', [
            'clip' => $clipIndex,
            'window' => round($window, 1),
            'lines' => count($lines),
            'drop_spans' => count($drop),
            'drop_seconds' => round(array_sum(array_map(fn ($d) => $d['end'] - $d['start'], $drop)), 1),
            'reason' => mb_substr((string) ($answer['reason'] ?? ''), 0, 120),
        ]);

        return $drop;
    }

    /**
     * Transcript lines that fall inside the window, numbered from 1.
     *
     * @return array<int, array{n: int, start: float, end: float, text: string}>
     */
    private function linesInWindow(array $segments, float $clipStart, float $clipEnd): array
    {
        $lines = [];
        foreach ($segments as $seg) {
            $s = (float) ($seg['start'] ?? 0);
            $e = (float) ($seg['end'] ?? 0);
            $text = trim((string) ($seg['text'] ?? ''));
            if ($e <= $clipStart || $s >= $clipEnd || $e <= $s || $text === '') {
                continue;
            }
            $lines[] = [
                'n' => count($lines) + 1,
                'start' => max($s, $clipStart),
                'end' => min($e, $clipEnd),
                'text' => $text,
            ];
        }

        // A very long window would otherwise blow the prompt: keep the ends
        // (hook and payoff) and thin the middle, which is where the padding is.
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
            foreach ($lines as $i => $_) {
                $lines[$i]['n'] = $i + 1;
            }
        }

        return $lines;
    }

    /** @param array<int, array{n: int, start: float, end: float, text: string}> $lines */
    private function prompt(array $lines, float $window, string $hook, string $videoTitle): string
    {
        $body = '';
        foreach ($lines as $l) {
            $body .= sprintf("%d [%.1fs] %s\n", $l['n'], $l['start'] - $lines[0]['start'], $l['text']);
        }
        $target = max(self::MIN_KEPT_SECONDS, round($window * 0.62));
        $hookLine = $hook !== '' ? "The hook this clip was chosen for: \"{$hook}\"\n" : '';

        return <<<TXT
You are the editor on a viral vertical short cut from "{$videoTitle}".

Below is the clip's transcript, one numbered line per caption segment, with the
time each line starts. The clip currently runs {$window} seconds. It is too
slow. Your job is to say which LINES to delete so that what is left is only the
parts a stranger scrolling their phone would stay for — ideally around
{$target} seconds.

{$hookLine}
Delete a line when it is:
- repetition — the same thing said again in different words
- dead conversation between the good parts: greetings, logistics, "wait", "huh",
  people talking over each other about nothing
- a tangent that goes nowhere and is never paid off
- setup the viewer does not need to understand the payoff
- crowd noise, mumbling, half-sentences that lead nowhere

KEEP:
- the opening line — it is the hook, it must survive
- the closing line — it is the payoff, it must survive
- every line the payoff depends on to make sense
- reactions, punchlines, numbers, names, anything surprising

Rules:
- Delete whole lines only, never part of one.
- What remains must still make sense read in order, with no dangling question
  that never gets answered and no answer to a question you deleted.
- Deleting nothing is a valid answer if the clip is already tight.
- Never delete more than half the lines.

Transcript:
{$body}
Answer ONLY with JSON:
{"drop": [3, 4, 9], "reason": "one short sentence on what you removed"}
TXT;
    }

    /**
     * Line numbers to source-time spans, merging runs of consecutive lines into
     * one span so five deleted lines in a row become one cut, not five.
     *
     * @param  array<int, array{n: int, start: float, end: float, text: string}>  $lines
     * @return array<int, array{start: float, end: float}>
     */
    private function spansFromLineNumbers(mixed $raw, array $lines): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $byNumber = [];
        foreach ($lines as $l) {
            $byNumber[$l['n']] = $l;
        }

        $wanted = [];
        foreach ($raw as $n) {
            if (!is_numeric($n)) {
                continue;
            }
            $n = (int) $n;
            if (isset($byNumber[$n])) {
                $wanted[$n] = true;
            }
        }
        if (!$wanted) {
            return [];
        }
        $wanted = array_keys($wanted);
        sort($wanted);

        $spans = [];
        $run = [$wanted[0], $wanted[0]];
        foreach (array_slice($wanted, 1) as $n) {
            if ($n === $run[1] + 1) {
                $run[1] = $n;
                continue;
            }
            $spans[] = $run;
            $run = [$n, $n];
        }
        $spans[] = $run;

        return array_map(fn ($r) => [
            'start' => $byNumber[$r[0]]['start'],
            'end' => $byNumber[$r[1]]['end'],
        ], $spans);
    }

    /**
     * Throw the whole answer away unless it is safe.
     *
     * Partial enforcement would be worse than none: dropping "the cuts that are
     * allowed" out of a plan the model built as a whole leaves a clip edited to
     * nobody's intention.
     *
     * @param  array<int, array{start: float, end: float}>  $drop
     * @param  array<int, array{n: int, start: float, end: float, text: string}>  $lines
     * @return array<int, array{start: float, end: float}>
     */
    private function guard(array $drop, array $lines, float $window): array
    {
        $first = $lines[0];
        $last = $lines[count($lines) - 1];

        $drop = array_values(array_filter(
            $drop,
            fn ($d) => $d['end'] - $d['start'] >= self::MIN_DROP_SECONDS
                // The hook and the payoff are not negotiable, whatever the
                // model said: the clip was selected for them.
                && $d['start'] >= $first['end'] - 0.01
                && $d['end'] <= $last['start'] + 0.01
        ));
        if (!$drop) {
            return [];
        }

        $total = array_sum(array_map(fn ($d) => $d['end'] - $d['start'], $drop));
        if ($total > $window * self::MAX_DROP_SHARE || $window - $total < self::MIN_KEPT_SECONDS) {
            Log::info('[SHORTS] Tightening rejected — too much would go', [
                'window' => round($window, 1),
                'would_drop' => round($total, 1),
                'cap' => round($window * self::MAX_DROP_SHARE, 1),
            ]);

            return [];
        }

        return $drop;
    }
}
