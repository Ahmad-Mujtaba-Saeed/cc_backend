<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WordLevelCaptionService
{
    /**
     * Generate ASS subtitle file with karaoke-style word-level highlighting.
     *
     * $styleOverrides lets callers adjust placement/sizing per composition
     * (e.g. margin_v so captions land on the main panel instead of the
     * gameplay panel). Only keys that exist in the template are applied.
     */
    public function generateKaraokeCaptions(array $wordTimings, string $outputPath, string $templateName = 'modern_karaoke', array $styleOverrides = []): bool
    {
        try {
            if (empty($wordTimings)) {
                Log::warning('No word timings provided for caption generation');
                return false;
            }

            $assContent = $this->buildASSFile($wordTimings, $templateName, $styleOverrides);
            $success = Storage::disk('public')->put($outputPath, $assContent);

            if (!$success) {
                throw new \Exception('Failed to write ASS caption file');
            }

            Log::info('Word-level caption generation completed', [
                'output_path' => $outputPath,
                'words_count' => count($wordTimings),
                'caption_template' => $templateName,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Word-level caption generation failed: ' . $e->getMessage());
            return false;
        }
    }

    private function buildASSFile(array $wordTimings, string $templateName, array $styleOverrides = []): string
    {
        $template = $this->getCaptionTemplate($templateName);
        if (!empty($styleOverrides)) {
            $template = array_merge($template, array_intersect_key($styleOverrides, $template));
        }
        $header = $this->getASSHeader($template);
        $events = $this->generateASSEvents($wordTimings, $template);

        return $header . "\r\n" . implode("\r\n", $events);
    }

    private function getASSHeader(array $template): string
    {
        // PlayRes calibrated for 1080×1920 (9:16 vertical video).
        // All font sizes below are in these script units — do NOT remove PlayResX/Y
        // or ffmpeg will scale sizes arbitrarily based on output resolution.
        return "[Script Info]\r\n" .
               "Title: Karaoke Captions\r\n" .
               "ScriptType: v4.00+\r\n" .
               "PlayResX: 1080\r\n" .
               "PlayResY: 1920\r\n\r\n" .
               "[V4+ Styles]\r\n" .
               "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\r\n" .
               "Style: Base," .
               "{$template['fontname']}," .
               "{$template['font_size']}," .
               "{$template['normal_color']}," .
               "&H00000000&," .
               "&H00000000&," .        // OutlineColour: solid black
               "{$template['back_color']}," .
               "1,0,0,0," .            // Bold=1, no italic/underline/strikeout
               "100,100,0,0," .        // ScaleX, ScaleY, Spacing, Angle
               "{$template['border_style']}," . // 1 = outline+shadow, 3 = opaque box
               "{$template['outline']}," .
               "{$template['shadow']}," .
               "{$template['alignment']}," .
               "40,40,{$template['margin_v']},1\r\n\r\n" .
               "[Events]\r\n" .
               "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text";
    }

    private function getCaptionTemplate(string $templateName): array
    {
        $templates = [
            // TikTok / YouTube Shorts viral style — bold white with yellow highlight,
            // outline only, sat in the lower-third (not jammed against the bottom).
            'modern_karaoke' => [
                'fontname'           => 'Arial',
                'font_size'          => 78,
                'current_font_size'  => 90,     // 1.15× ratio — noticeable but not jarring
                'normal_color'       => '&H00FFFFFF&',  // white
                'highlight_color'    => '&H0000FFFF&',  // yellow (RGB 255,255,0 in ABGR)
                'back_color'         => '&H00000000&',
                'border_style'       => 1,       // outline + shadow
                'outline'            => 5,
                'shadow'             => 0,
                'alignment'          => 2,       // bottom-center anchor
                'margin_v'           => 520,     // ~73% down — comfortable lower-third
            ],

            // Boxed caption — text sits inside a solid dark box (BorderStyle 3).
            // Distinct, highly legible "block" look.
            'classic_block' => [
                'fontname'           => 'Arial',
                'font_size'          => 70,
                'current_font_size'  => 78,     // 1.11×
                'normal_color'       => '&H00FFFFFF&',  // white
                'highlight_color'    => '&H0000FFFF&',  // yellow
                'back_color'         => '&HA0000000&',  // ~63% opaque black box
                'border_style'       => 3,       // opaque box behind text
                'outline'            => 12,      // box padding around the text
                'shadow'             => 0,
                'alignment'          => 2,
                'margin_v'           => 500,
            ],

            // Minimal style — used for horror: white text, orange-red highlight,
            // thin outline, centered lower-third.
            'minimal_clean' => [
                'fontname'           => 'Arial',
                'font_size'          => 70,
                'current_font_size'  => 80,     // 1.143×
                'normal_color'       => '&H00FFFFFF&',  // white
                'highlight_color'    => '&H000060FF&',  // orange-red (RGB 255,96,0)
                'back_color'         => '&H00000000&',
                'border_style'       => 1,
                'outline'            => 4,
                'shadow'             => 0,
                'alignment'          => 2,
                'margin_v'           => 520,
            ],

            // ONE word on screen at a time — huge, uppercase, pop-in scale
            // animation, word colors cycling. The high-energy style used by
            // the Ranking Moments template.
            'single_word_pop' => [
                'fontname'           => 'Arial',
                'font_size'          => 104,
                'current_font_size'  => 104,    // unused in single-word mode
                'normal_color'       => '&H00FFFFFF&',  // white
                'highlight_color'    => '&H0000FFFF&',  // yellow
                'back_color'         => '&H00000000&',
                'border_style'       => 1,
                'outline'            => 6,
                'shadow'             => 2,
                'alignment'          => 2,
                'margin_v'           => 560,
                'mode'               => 'single_word',
                'uppercase'          => true,
                'pop'                => true,
                // Word colours cycle so runs of text stay lively without
                // turning into a rainbow: mostly white, every 3rd/4th accent.
                'word_colors'        => ['&H00FFFFFF&', '&H00FFFFFF&', '&H0000FFFF&', '&H00FFFFFF&', '&H00FFC83B&'],
            ],
        ];

        return $templates[$templateName] ?? $templates['modern_karaoke'];
    }

    /**
     * Groups word timings into lines of $wordsPerLine, then for each word generates
     * one ASS event showing the entire line — current word highlighted, rest normal.
     * This produces smooth phrase-level karaoke with enough context around each word.
     */
    private function generateASSEvents(array $wordTimings, array $template): array
    {
        if (($template['mode'] ?? '') === 'single_word') {
            return $this->generateSingleWordEvents($wordTimings, $template);
        }

        $events = [];
        $wordsPerLine = 5;
        $lines = array_chunk($wordTimings, $wordsPerLine);

        $normalTag    = "\\fs{$template['font_size']}\\c{$template['normal_color']}";
        $highlightTag = "\\fs{$template['current_font_size']}\\c{$template['highlight_color']}";
        $alignment    = $template['alignment'];

        foreach ($lines as $lineWords) {
            foreach ($lineWords as $currentIdx => $wordData) {
                $start = $wordData['start'] ?? 0;
                $end   = $wordData['end']   ?? ($start + 0.3);

                $parts = [];
                foreach ($lineWords as $i => $wd) {
                    // Strip ASS tag delimiters from the raw word
                    $w = str_replace(['{', '}'], '', $wd['word'] ?? '');
                    if ($w === '') {
                        continue;
                    }
                    if ($i === $currentIdx) {
                        $parts[] = "{{$highlightTag}}{$w}";
                    } else {
                        $parts[] = "{{$normalTag}}{$w}";
                    }
                }

                if (empty($parts)) {
                    continue;
                }

                $lineText  = implode(' ', $parts);
                $startTime = $this->formatASSTime($start);
                $endTime   = $this->formatASSTime($end);

                $events[] = "Dialogue: 0,{$startTime},{$endTime},Base,,0,0,0,,{\\an{$alignment}}{$lineText}";
            }
        }

        return $events;
    }

    /**
     * One Dialogue event per word — nothing else on screen. Each word scales
     * in with a small overshoot "pop" and takes the next colour in the cycle.
     * Words shorter than 120ms are stretched so the pop can land.
     */
    private function generateSingleWordEvents(array $wordTimings, array $template): array
    {
        $events = [];
        $colors = $template['word_colors'] ?? [$template['normal_color']];
        $colorCount = max(1, count($colors));
        $alignment = $template['alignment'];
        $pop = !empty($template['pop'])
            ? '\\fscx75\\fscy75\\t(0,90,\\fscx108\\fscy108)\\t(90,160,\\fscx100\\fscy100)'
            : '';

        $index = 0;
        foreach ($wordTimings as $wordData) {
            $word = trim(str_replace(['{', '}'], '', (string) ($wordData['word'] ?? '')));
            if ($word === '') {
                continue;
            }
            if (!empty($template['uppercase'])) {
                $word = mb_strtoupper($word);
            }

            $start = (float) ($wordData['start'] ?? 0);
            $end = max($start + 0.12, (float) ($wordData['end'] ?? $start + 0.3));
            $color = $colors[$index % $colorCount];

            $events[] = 'Dialogue: 0,' . $this->formatASSTime($start) . ',' . $this->formatASSTime($end)
                . ",Base,,0,0,0,,{\\an{$alignment}{$pop}\\c{$color}}{$word}";
            $index++;
        }

        return $events;
    }

    /**
     * Format seconds as ASS timestamp (H:MM:SS.cs)
     */
    private function formatASSTime(float $seconds): string
    {
        // Integer centisecond math: avoids float modulo deprecation warnings
        // and the rounding carry that could emit an invalid ".100" component.
        $totalCs = (int) round(max(0, $seconds) * 100);

        $hours        = intdiv($totalCs, 360000);
        $minutes      = intdiv($totalCs % 360000, 6000);
        $secs         = intdiv($totalCs % 6000, 100);
        $centiseconds = $totalCs % 100;

        return sprintf('%01d:%02d:%02d.%02d', $hours, $minutes, $secs, $centiseconds);
    }

    /**
     * Generate WebVTT with per-word karaoke formatting.
     */
    public function generateWebVTTKaraoke(array $wordTimings, string $outputPath): bool
    {
        try {
            if (empty($wordTimings)) {
                Log::warning('No word timings provided for WebVTT generation');
                return false;
            }

            $vttContent = $this->buildWebVTTFile($wordTimings);
            $success = Storage::disk('public')->put($outputPath, $vttContent);

            if (!$success) {
                throw new \Exception('Failed to write WebVTT file');
            }

            Log::info('WebVTT karaoke caption generation completed', [
                'output_path' => $outputPath,
                'words_count' => count($wordTimings)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('WebVTT generation failed: ' . $e->getMessage());
            return false;
        }
    }

    private function buildWebVTTFile(array $wordTimings): string
    {
        $lines      = ["WEBVTT\n"];
        $wordCount  = count($wordTimings);

        foreach ($wordTimings as $index => $wordData) {
            $word      = $wordData['word'] ?? '';
            $start     = $wordData['start'] ?? 0;
            $end       = $wordData['end']   ?? $start + 0.5;
            $prevWord  = $index > 0            ? ($wordTimings[$index - 1]['word'] ?? '') : '';
            $nextWord  = $index < $wordCount - 1 ? ($wordTimings[$index + 1]['word'] ?? '') : '';
            $startTime = $this->formatWebVTTTime($start);
            $endTime   = $this->formatWebVTTTime($end);

            $lines[] = "$startTime --> $endTime";
            $lines[] = "<v Karaoke><c.prev>$prevWord</c> <c.highlight>$word</c> <c.next>$nextWord</c></v>";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    private function formatWebVTTTime(float $seconds): string
    {
        $hours        = (int) floor($seconds / 3600);
        $minutes      = (int) floor(($seconds % 3600) / 60);
        $secs         = (int) floor($seconds % 60);
        $milliseconds = (int) round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $milliseconds);
    }
}
