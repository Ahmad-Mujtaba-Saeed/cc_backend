<?php

namespace Modules\Mcp\Support;

use Modules\Mcp\Services\McpVideoService;
use Modules\Project\Support\ExplainerRegistry;

/**
 * The studio's documentation, as the model reads it: get_guide(topic) and the
 * same texts as MCP resources (guide://<topic>) for clients that attach them.
 *
 * Hand-written guides live in Resources/guides/*.md. The card catalogue is
 * generated from the registry (the same source the validator enforces), so it
 * can never document a slot shape the validator would reject.
 */
final class Guides
{
    public const TOPICS = ['workflow', 'scene_code', 'examples', 'design', 'cards', 'presenter'];

    private const TITLES = [
        'workflow' => 'Workflow — how to make a video with the studio (read first)',
        'scene_code' => 'Writing custom animated scenes (the sandbox + hero-kit API)',
        'examples' => 'Example scene modules (the quality bar)',
        'design' => 'Art direction for a whole video',
        'cards' => 'The library card catalogue (exact slot shapes)',
        'presenter' => 'Presenter mode — motion graphics over the user\'s recording',
    ];

    public static function instructions(): string
    {
        return "Vreato Video Studio: you build explainer videos (up to 15 min, rendered at 60 fps) for the user, scene by scene, with these tools.\n"
            . "1. Read get_guide(\"workflow\") and get_guide(\"scene_code\") before anything else.\n"
            . "2. Ask the user the important choices first: narrator voice (list_voices — share sample links), aspect ratio, music; for their own talking-head recording use presenter mode.\n"
            . "3. Plan every scene (one clear visual idea each), then write each one as custom animated code with upsert_scene and LOOK at it with preview_scene; fix what the frames show.\n"
            . "4. render_video, poll get_render_status, give the user the link.\n"
            . 'The studio is free for the user; paid AI image features are not available here — draw visuals in code or use free stock media (search_media).';
    }

    public static function resources(): array
    {
        $out = [];
        foreach (self::TOPICS as $topic) {
            $out[] = [
                'uri' => "guide://{$topic}",
                'name' => $topic,
                'title' => self::TITLES[$topic],
                'mimeType' => 'text/markdown',
            ];
        }

        return $out;
    }

    public static function readUri(string $uri): ?string
    {
        if (!preg_match('#^guide://([a-z_]+)$#', $uri, $m) || !in_array($m[1], self::TOPICS, true)) {
            return null;
        }

        return self::read($m[1]);
    }

    public static function read(string $topic): string
    {
        return match ($topic) {
            'cards' => self::cards(),
            'examples' => self::examples(),
            default => self::file($topic),
        };
    }

    private static function dir(): string
    {
        return dirname(__DIR__) . '/Resources/guides';
    }

    private static function file(string $topic): string
    {
        $path = self::dir() . "/{$topic}.md";

        return is_file($path) ? (string) file_get_contents($path) : "No guide named {$topic}.";
    }

    private static function examples(): string
    {
        $out = "# Example scene modules\n\nComplete, valid modules that passed the sandbox and review. They set the bar for craft — "
            . "do NOT copy their content; design for YOUR narration. Each is one scene.\n";
        $files = array_merge(
            glob(self::dir() . '/examples/*.tsx') ?: [],
            glob(dirname(__DIR__, 2) . '/Project/Resources/hero/examples/*.tsx') ?: []
        );
        foreach ($files as $i => $file) {
            $name = basename($file, '.tsx');
            $out .= "\n## Example " . ($i + 1) . ": {$name}\n\n```tsx\n" . trim((string) file_get_contents($file)) . "\n```\n";
        }

        return $out;
    }

    /** The card library, generated from the registry the validator enforces. */
    private static function cards(): string
    {
        $blockedTemplates = McpVideoService::BLOCKED_TEMPLATES;
        $blockedContent = McpVideoService::BLOCKED_CONTENT;

        $lines = [];
        $lines[] = '# Library cards';
        $lines[] = '';
        $lines[] = 'A card is a ready-made, well-tested layout you fill with content: `upsert_scene` with '
            . '`card: {"template": "<name>", "slots": {"<slot_key>": {"content_type": "<type>", ...fields}}}`. '
            . 'Cards animate in sync with the narration on their own. Use them for data the card does well (charts, timelines, comparisons, rankings, step flows, maths); '
            . 'use custom `code` for everything else — custom scenes are what make a video look designed rather than templated.';
        $lines[] = '';
        $lines[] = 'Rules in the studio:';
        $lines[] = '- Not available here: ' . implode(', ', $blockedTemplates) . ' (templates), ' . implode(', ', $blockedContent) . ' (content types). Draw those ideas in code.';
        $lines[] = '- Picture/video slots (content_type "image"/"video"): give `"media": "<shelf name>"` (search_media → add_media, or a user upload), or `"stock_query": "2-4 words"` for automatic free b-roll (video slots). '
            . 'A slot with neither is refused. Images may carry "camera_move".';
        $lines[] = '- Every structured field is clamped by a validator; if a card cannot be built from your slots it is replaced by a plain card and upsert_scene tells you — read card_warnings.';
        $lines[] = '- text_block = {"heading": "...", "bullets": ["≤ 5 short lines"]}.';
        $lines[] = '';
        $lines[] = '## Templates and content types';
        $lines[] = '';
        $reference = ExplainerRegistry::promptReference();
        // Drop the blocked ones from the generated catalogue.
        $kept = [];
        $skip = false;
        foreach (explode("\n", $reference) as $line) {
            if (preg_match('/^- "([a-z_]+)":/', $line, $m)) {
                $skip = in_array($m[1], $blockedTemplates, true) || in_array($m[1], $blockedContent, true);
            } elseif (!str_starts_with($line, '    ')) {
                $skip = false;
            }
            if (!$skip) {
                $kept[] = $line;
            }
        }
        $lines[] = implode("\n", $kept);

        return implode("\n", $lines);
    }
}
