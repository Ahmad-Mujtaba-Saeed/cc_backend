<?php

namespace Modules\Project\Support;

/**
 * ExplainerImagePrompt — the ONE place a slot becomes an image prompt.
 *
 * Two callers build it now: `ExplainerVideoProcessor::fillMissingMediaSlots()`
 * at render time, and `ExplainerController::generateSlotImage()` when the user
 * asks for a picture (or a different one) from the storyboard. They MUST agree
 * character for character, because the generated file is cached under
 * `slot-fill:md5(prompt)` and the processor decides whether to re-generate by
 * comparing that hash: a prompt that differs by a comma means every render
 * silently re-bills an image the user already accepted, and a prompt that
 * ignores the user's own art direction means the render throws their choice
 * away and puts the old picture back.
 */
final class ExplainerImagePrompt
{
    /** The nudge a VLM-flagged fill gets so its prompt (and hash) changes. */
    public const RETRY_NUDGE = ' — different composition, different angle';

    /**
     * What the picture is OF: the slot's own request, plus whatever direction
     * the user typed on the storyboard.
     *
     * The instruction is appended rather than replacing the description, so
     * "make it night-time" stays a note about the subject instead of becoming
     * the whole subject.
     */
    public static function subject(array $slot, bool $retryNudge = false): string
    {
        $subject = trim((string) ($slot['asset_request']['description'] ?? ''));
        if ($subject === '') {
            $subject = trim((string) ($slot['label'] ?? ''));
        }
        if ($subject === '') {
            $subject = 'the idea this scene narrates';
        }

        $instruction = trim((string) ($slot['asset_request']['instruction'] ?? ''));
        if ($instruction !== '') {
            $subject .= '. ' . $instruction;
        }
        if ($retryNudge) {
            $subject .= self::RETRY_NUDGE;
        }

        return $subject;
    }

    /**
     * The one flat-vector art direction every AI image in the video obeys —
     * locked to the theme's own three colours (named in WORDS: the image model
     * largely ignores raw hex codes but follows "dark plum background with pink
     * accents" reliably) with the hard no-text rule (§7.1).
     *
     * @param array $theme an ExplainerRegistry colour scheme
     */
    public static function flatVector(string $subject, array $theme): string
    {
        $field = $theme['bg_from'] ?? '#0A0F1E';
        $ink = $theme['text'] ?? '#EDF0F8';
        $accent = $theme['accent'] ?? '#FFB020';

        return 'Bold flat 2D vector illustration of ' . $subject . '. '
            . 'Bold geometric shapes with solid colour fills, thick confident outlines, crisp clean edges, '
            // "generous negative space, centered composition" was read by the
            // image model as "one small icon adrift in an empty field" — the
            // reason so many frames came back as a near-solid background with a
            // postage-stamp motif. The subject has to OWN the frame instead.
            . 'The subject is large and fills the frame edge to edge, generously cropped, '
            . 'occupying most of the canvas — never a small icon floating in empty space. '
            . 'Modern editorial poster style. '
            . 'Strictly three colours only: a ' . self::colorName($field) . ' background, '
            . self::colorName($ink) . ' linework, and ' . self::colorName($accent) . ' accents. '
            . 'Not a photograph: no photorealism, no bokeh, no blur, no depth of field. '
            . 'No gradients, no shading, no glow, no drop shadows. '
            // Image models cannot spell — the hard no-text rule (copilot.md
            // §7.1) covers every way glyphs sneak in.
            . 'No text, no words, no letters, no numbers, no labels, no captions, no watermark anywhere in the image';
    }

    /** Prompt + cache hash for one slot, in one call. */
    public static function forSlot(array $slot, array $theme, bool $retryNudge = false): array
    {
        $prompt = self::flatVector(self::subject($slot, $retryNudge), $theme);

        return ['prompt' => $prompt, 'hash' => md5($prompt), 'name' => 'slot-fill:' . md5($prompt)];
    }

    /**
     * A hex colour as words the image model actually follows.
     */
    public static function colorName(string $hex): string
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) !== 6) {
            return 'neutral dark';
        }
        $r = hexdec(substr($h, 0, 2)) / 255;
        $g = hexdec(substr($h, 2, 2)) / 255;
        $b = hexdec(substr($h, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $d === 0.0 ? 0.0 : $d / (1 - abs(2 * $l - 1));

        if ($s < 0.12) {
            return $l < 0.12 ? 'near-black' : ($l > 0.85 ? 'near-white' : ($l > 0.6 ? 'light warm grey' : 'dark grey'));
        }

        $hue = 0.0;
        if ($d > 0) {
            $hue = match ($max) {
                $r => fmod((($g - $b) / $d) + 6, 6),
                $g => (($b - $r) / $d) + 2,
                default => (($r - $g) / $d) + 4,
            } * 60;
        }

        $name = match (true) {
            $hue < 15 || $hue >= 345 => 'red',
            $hue < 40 => 'orange',
            $hue < 65 => 'golden yellow',
            $hue < 90 => 'lime green',
            $hue < 150 => 'green',
            $hue < 185 => 'teal',
            $hue < 210 => 'cyan',
            $hue < 250 => 'blue',
            $hue < 275 => 'indigo',
            $hue < 300 => 'violet',
            $hue < 330 => 'magenta',
            default => 'pink',
        };

        if ($l < 0.16) {
            return "very dark {$name}, almost black";
        }
        if ($l < 0.35) {
            return "deep {$name}";
        }
        if ($l > 0.85) {
            return "pale {$name}, almost white";
        }
        if ($l > 0.65) {
            return "light {$name}";
        }

        return $name;
    }
}
