<?php

namespace Modules\Project\Support;

/**
 * ExplainerRegistry
 *
 * Single source of truth for the slot-based explainer composition system.
 * The same registry feeds three consumers so they can never drift apart:
 *   1. The LLM system prompt (what templates / slots / content types exist)
 *   2. The {@see ShotListValidator} (what is a legal scene)
 *   3. The frontend storyboard UI + the Remotion render service
 *
 * Adding a new template later = one entry in explainer_registry.json plus one
 * Remotion layout component. Nothing else in PHP needs to change.
 */
class ExplainerRegistry
{
    private static ?array $cache = null;

    /**
     * Load and cache the raw registry array.
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $path = dirname(__DIR__) . '/Resources/explainer_registry.json';
            $json = file_get_contents($path);
            $data = json_decode($json, true);

            if (!is_array($data)) {
                throw new \RuntimeException('explainer_registry.json is missing or invalid JSON');
            }

            self::$cache = $data;
        }

        return self::$cache;
    }

    public static function fps(): int
    {
        return (int) (self::all()['fps'] ?? 30);
    }

    public static function defaultSceneSeconds(): float
    {
        return (float) (self::all()['default_scene_seconds'] ?? 6);
    }

    public static function templates(): array
    {
        return self::all()['templates'] ?? [];
    }

    public static function templateNames(): array
    {
        return array_keys(self::templates());
    }

    public static function template(string $name): ?array
    {
        return self::templates()[$name] ?? null;
    }

    public static function hasTemplate(string $name): bool
    {
        return self::template($name) !== null;
    }

    /**
     * Declared slot keys for a template, e.g. ['slot_left', 'slot_right'].
     */
    public static function slotKeys(string $template): array
    {
        return array_keys(self::template($template)['slots'] ?? []);
    }

    /**
     * Allowed content types for a given slot in a given template.
     */
    public static function allowedContentTypes(string $template, string $slotKey): array
    {
        return self::template($template)['slots'][$slotKey]['allowed'] ?? [];
    }

    /**
     * Full slot definition (allowed types + any dock/width config).
     */
    public static function slotMeta(string $template, string $slotKey): array
    {
        return self::template($template)['slots'][$slotKey] ?? [];
    }

    public static function contentTypes(): array
    {
        return self::all()['content_types'] ?? [];
    }

    /**
     * Required fields for a content type, e.g. text_block => ['heading', 'bullets'].
     */
    public static function requiredFields(string $contentType): array
    {
        return self::all()['content_types'][$contentType]['required'] ?? [];
    }

    public static function cameraMoves(): array
    {
        return self::all()['camera_moves'] ?? ['static'];
    }

    public static function defaultCameraMove(): string
    {
        return self::all()['default_camera_move'] ?? 'static';
    }

    public static function transitions(): array
    {
        return self::all()['transitions'] ?? ['none'];
    }

    public static function defaultTransition(): string
    {
        return self::all()['default_transition'] ?? 'none';
    }

    /**
     * One-line editorial meaning per §3.1 transition, for the planner prompt.
     *
     * @return array<string, string>
     */
    public static function transitionMeanings(): array
    {
        return self::all()['transition_meanings'] ?? [];
    }

    /**
     * Relation → signature transition map (copilot.md §3.2): the cut each
     * story relation deserves when the planner doesn't make a deliberate
     * other choice.
     *
     * @return array<string, string>
     */
    public static function relationSignatures(): array
    {
        return self::all()['relation_signatures'] ?? [];
    }

    /** The signature transition for a relation, or null when unmapped. */
    public static function signatureTransition(?string $relation): ?string
    {
        if ($relation === null || $relation === '') {
            return null;
        }
        $t = self::relationSignatures()[$relation] ?? null;

        return is_string($t) && in_array($t, self::transitions(), true) ? $t : null;
    }

    /**
     * Font packs (copilot.md §4.7) keyed by name.
     *
     * @return array<string, array{label: string, display: string, body: string, mono: string, use_when: string}>
     */
    public static function fontPacks(): array
    {
        return self::all()['font_packs']['packs'] ?? [];
    }

    /** @return string[] */
    public static function fontPackNames(): array
    {
        return array_keys(self::fontPacks());
    }

    /** The stored default ('auto' resolves at render time). */
    public static function defaultFontPack(): string
    {
        return self::all()['font_packs']['default'] ?? 'auto';
    }

    /**
     * Motion style presets (copilot.md §2.5), keyed by name.
     *
     * @return array<string, array>
     */
    public static function motionStyles(): array
    {
        return self::all()['motion_styles']['styles'] ?? [];
    }

    /** @return string[] */
    public static function motionStyleNames(): array
    {
        return array_keys(self::motionStyles());
    }

    /** The stored default ('auto' resolves via the mood map at render). */
    public static function defaultMotionStyle(): string
    {
        return self::all()['motion_styles']['default'] ?? 'auto';
    }

    /** mood → motion style map for 'auto'. */
    public static function motionStyleForMood(string $mood): string
    {
        $map = self::all()['motion_styles']['mood_defaults'] ?? [];

        return $map[$mood] ?? 'crisp';
    }

    /** motion style → font pack map for `font_pack: auto` (§4.7). */
    public static function fontPackForStyle(string $style): string
    {
        $map = self::all()['motion_styles']['font_defaults'] ?? [];

        return $map[$style] ?? 'editorial';
    }

    /**
     * Surface skins (§11.2), keyed by name.
     *
     * @return array<string, array{label: string, use_when: string}>
     */
    public static function skins(): array
    {
        return self::all()['skins']['options'] ?? [];
    }

    /** @return string[] */
    public static function skinNames(): array
    {
        return array_keys(self::skins());
    }

    public static function defaultSkin(): string
    {
        return self::all()['skins']['default'] ?? 'flat';
    }

    /**
     * Math-board surface styles (math_board mode only), keyed by name.
     *
     * @return array<string, array{label: string, use_when: string}>
     */
    public static function boardStyles(): array
    {
        return self::all()['board_styles']['options'] ?? [];
    }

    /** @return string[] */
    public static function boardStyleNames(): array
    {
        return array_keys(self::boardStyles());
    }

    /** Per-video cap for a template, or null when uncapped. */
    public static function maxPerVideo(string $template): ?int
    {
        $max = self::all()['templates'][$template]['max_per_video'] ?? null;

        return is_numeric($max) ? (int) $max : null;
    }

    /**
     * Per-video cap for a MATHS video (the topic classifier said the subject
     * is mathematical). A worked solution or a visual proof legitimately wants
     * the same math card 4-5 beats running — the figure gains one element per
     * beat, which is the whole argument. The ordinary caps exist to stop a card
     * becoming a tic in a normal explainer and still apply there; only a
     * confirmed maths topic reads these.
     *
     * Returns the ordinary cap for any template with no maths override, so
     * callers can use this as a drop-in for maxPerVideo().
     */
    public static function mathModeMaxPerVideo(string $template): ?int
    {
        $max = self::all()['math_mode']['max_per_video'][$template] ?? null;

        return is_numeric($max) ? (int) $max : self::maxPerVideo($template);
    }

    /**
     * "Peak" cards — the high-energy beats a professional cut spaces through
     * the runtime (copilot.md §5 planner integration).
     *
     * @return string[]
     */
    public static function peakTemplates(): array
    {
        return self::all()['peak_templates'] ?? [];
    }

    public static function peakIntervalSeconds(): int
    {
        return (int) (self::all()['peak_interval_seconds'] ?? 45);
    }

    /**
     * The bundled Lucide icon whitelist (synced by remotion-render
     * scripts/gen-icons.ts — the renderer bundles exactly these).
     *
     * @return string[]
     */
    public static function iconNames(): array
    {
        return self::all()['icon_grid']['icons'] ?? [];
    }

    /** Per-video cap on auto-fetched stock b-roll slots (§8). */
    public static function maxStockVideos(): int
    {
        return (int) (self::all()['stock_video']['max_per_video'] ?? 3);
    }

    /**
     * Chapter-cover insertion rules (copilot.md §5.5).
     *
     * @return array{min_video_seconds: float, min_chapters: int, duration_seconds: float}
     */
    public static function coversConfig(): array
    {
        $c = self::all()['chapters']['covers'] ?? [];

        return [
            'min_video_seconds' => (float) ($c['min_video_seconds'] ?? 60),
            'min_chapters' => (int) ($c['min_chapters'] ?? 2),
            'duration_seconds' => (float) ($c['duration_seconds'] ?? 3.0),
        ];
    }

    public static function moods(): array
    {
        return self::all()['moods'] ?? ['neutral'];
    }

    public static function defaultMood(): string
    {
        return self::all()['default_mood'] ?? 'neutral';
    }

    public static function compositionModes(): array
    {
        return self::all()['composition_modes'] ?? ['canvas_journey', 'slides'];
    }

    public static function defaultCompositionMode(): string
    {
        return self::all()['default_composition_mode'] ?? 'canvas_journey';
    }

    /**
     * AI-image budget: at most max_total generated images per video — one
     * shared blurred backdrop plus up to max_illustrations creative
     * flat-vector illustrations.
     */
    public static function maxAiImages(): int
    {
        return (int) (self::all()['ai_images']['max_total'] ?? 8);
    }

    public static function maxAiIllustrations(): int
    {
        $max = (int) (self::all()['ai_images']['max_illustrations'] ?? 7);
        return max(0, min($max, self::maxAiImages() - 1));
    }

    /** Auto-visuals budget: AI images generated INTO unfilled media slots. */
    public static function maxSlotFills(): int
    {
        return (int) (self::all()['ai_images']['max_slot_fills'] ?? 8);
    }

    /**
     * Hybrid-chapter vocabulary (modes, size limits, act-break transition).
     */
    public static function chapters(): array
    {
        return self::all()['chapters'] ?? [];
    }

    public static function chapterModes(): array
    {
        return self::chapters()['modes'] ?? ['canvas', 'slides'];
    }

    public static function maxChapters(): int
    {
        return (int) (self::chapters()['max_chapters'] ?? 6);
    }

    public static function minCanvasChapterScenes(): int
    {
        return (int) (self::chapters()['min_canvas_chapter_scenes'] ?? 2);
    }

    public static function defaultChapterTransition(): string
    {
        return self::chapters()['default_chapter_transition'] ?? 'zoom_through';
    }

    /**
     * Canvas-journey vocabulary (journey patterns, card size bounds, ...).
     */
    public static function canvas(): array
    {
        return self::all()['canvas'] ?? [];
    }

    /**
     * Suggested station-card size for a given video aspect ratio.
     *
     * @return array{w: int, h: int}
     */
    public static function canvasBaseCard(string $aspectRatio): array
    {
        $base = self::canvas()['base_card'] ?? [];
        return $base[$aspectRatio] ?? ($base['16:9'] ?? ['w' => 1560, 'h' => 1000]);
    }

    /**
     * @return array<string, string> treatment name => description (for the LLM).
     */
    public static function treatments(): array
    {
        return self::canvas()['treatments'] ?? ['canvas_hop' => 'Fly to this scene.'];
    }

    public static function treatmentNames(): array
    {
        return array_keys(self::treatments());
    }

    public static function defaultTreatment(): string
    {
        return self::canvas()['default_treatment'] ?? 'canvas_hop';
    }

    /**
     * @return array<string, string> relation name => description (for the LLM).
     */
    public static function relations(): array
    {
        return self::canvas()['relations'] ?? ['continues' => 'The next step of the same thread.'];
    }

    public static function relationNames(): array
    {
        return array_keys(self::relations());
    }

    public static function defaultRelation(): string
    {
        return self::canvas()['default_relation'] ?? 'continues';
    }

    public static function maxArrows(): int
    {
        return (int) (self::canvas()['max_arrows'] ?? 3);
    }

    public static function maxConsecutiveTreatment(): int
    {
        return (int) (self::canvas()['max_consecutive_treatment'] ?? 2);
    }

    public static function propAnimations(): array
    {
        return self::canvas()['prop_animations'] ?? ['float'];
    }

    public static function defaultPropAnimation(): string
    {
        return self::canvas()['default_prop_animation'] ?? 'float';
    }

    public static function maxPropsPerScene(): int
    {
        return (int) (self::canvas()['max_props_per_scene'] ?? 3);
    }

    public static function nestScale(): float
    {
        return (float) (self::canvas()['nest_scale'] ?? 0.15);
    }

    /**
     * @return array<int, array> List of colour scheme objects.
     */
    public static function colorSchemes(): array
    {
        return self::all()['color_schemes'] ?? [];
    }

    public static function colorSchemeNames(): array
    {
        return array_map(fn ($s) => $s['name'], self::colorSchemes());
    }

    /**
     * Look up a colour scheme by name, falling back to the first scheme.
     */
    public static function colorScheme(?string $name): array
    {
        $schemes = self::colorSchemes();
        if (empty($schemes)) {
            return [];
        }
        foreach ($schemes as $scheme) {
            if (($scheme['name'] ?? null) === $name) {
                return $scheme;
            }
        }
        return $schemes[0];
    }

    /**
     * Pick a random colour scheme name (used to randomise each video's look).
     */
    public static function randomColorSchemeName(): string
    {
        $names = self::colorSchemeNames();
        return $names[array_rand($names)] ?? 'midnight';
    }

    /**
     * Human-readable reference block describing every legal choice, injected
     * verbatim into the LLM system prompt. Generated from the registry so the
     * prompt automatically stays in sync with the validator.
     */
    public static function promptReference(): string
    {
        $lines = [];

        $lines[] = 'LAYOUT TEMPLATES (pick exactly one per scene):';
        foreach (self::templates() as $name => $tpl) {
            $slotDescriptions = [];
            foreach ($tpl['slots'] as $slotKey => $slot) {
                $allowed = implode(' | ', $slot['allowed']);
                $detail = "{$slotKey} (accepts: {$allowed}";
                if (!empty($slot['dock_options'])) {
                    $detail .= '; dock: ' . implode('/', $slot['dock_options'])
                        . ', default ' . ($slot['default_dock'] ?? $slot['dock_options'][0]);
                    if (!empty($slot['default_width_pct'])) {
                        $detail .= "; width_pct ~{$slot['default_width_pct']}";
                    }
                }
                $detail .= ')';
                $slotDescriptions[] = $detail;
            }
            $lines[] = "- \"{$name}\": {$tpl['description']}";
            $lines[] = "    slots: " . implode(', ', $slotDescriptions);
        }

        $lines[] = '';
        $lines[] = 'CONTENT TYPES (what goes in a slot):';
        foreach (self::contentTypes() as $type => $meta) {
            $required = implode(', ', $meta['required']);
            $lines[] = "- \"{$type}\": {$meta['description']} Required fields: {$required}.";
        }

        $lines[] = '';
        $lines[] = 'CAMERA MOVES (for image slots only): ' . implode(', ', self::cameraMoves())
            . ". Default: " . self::defaultCameraMove() . '. Never leave an image static unless intentional.'
            . ' Guidance: "arc_pan" for a filmic sweep across a wide scene; "whip_settle" to throw energetically onto a reveal;'
            . ' "pedestal_up"/"pedestal_down" for TALL subjects that read top-to-bottom (skylines, waterfalls, towers) — especially in 9:16;'
            . ' "pan_up_zoom_in" when a tall subject should also feel like a climb; "zoom_in_snap" for the beat the narration lands on.';
        $lines[] = 'TRANSITIONS (between scenes): ' . implode(', ', self::transitions())
            . ". Default: " . self::defaultTransition() . '.';

        $meanings = self::transitionMeanings();
        if (!empty($meanings)) {
            $lines[] = 'TRANSITION MEANINGS (motion is grammar — pick by what the cut DOES):';
            foreach ($meanings as $name => $meaning) {
                $lines[] = "- \"{$name}\": {$meaning}";
            }
        }

        $signatures = self::relationSignatures();
        if (!empty($signatures)) {
            $lines[] = '';
            $lines[] = 'SCENE RELATIONS (how a scene relates to the PREVIOUS one; each has a signature transition used when you leave "transition" out):';
            foreach (self::relations() as $name => $meaning) {
                $sig = $signatures[$name] ?? self::defaultTransition();
                $lines[] = "- \"{$name}\" (signature transition: {$sig}): {$meaning}";
            }
        }

        $icons = self::iconNames();
        if (!empty($icons)) {
            $lines[] = '';
            $lines[] = 'ICON LIBRARY (the ONLY legal values for icon_grid item "icon"): ' . implode(', ', $icons) . '.';
        }

        return implode("\n", $lines);
    }
}
