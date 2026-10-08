<?php

namespace Modules\Project\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\Project;

/**
 * Explainer pricing — the one place that knows what an explainer action
 * costs, so the storyboard's quote, the render gate and the render itself
 * can never disagree.
 *
 *  - storyboard: the duration tier's price, charged when it is generated
 *  - render:     the first successful render is free, later ones cost
 *                `rerender_cost` (the aspect-variant bundle multiplies it)
 *  - AI pictures: `ai_image_cost` each, but ONLY the ones the user asked for:
 *                the slots "AI visuals" fills when the user switched it on,
 *                and "Generate with AI" on a slot. What the pipeline draws by
 *                default (maths videos' auto visuals, cinematic flow-card art,
 *                stock b-roll) is covered by the storyboard price.
 *
 * Prices live in config('credits.explainer').
 */
class ExplainerBilling
{
    /** @return array<string, array{label: string, max_seconds: int, cost: int}> */
    public static function tiers(): array
    {
        $tiers = [];
        foreach ((array) config('credits.explainer.duration_tiers', []) as $key => $tier) {
            $tiers[(string) $key] = [
                'label' => (string) ($tier['label'] ?? $key),
                'max_seconds' => (int) ($tier['max_seconds'] ?? 300),
                'cost' => (int) ($tier['cost'] ?? 100),
            ];
        }

        return $tiers ?: ['short' => ['label' => 'Up to 5 min', 'max_seconds' => 300, 'cost' => 100]];
    }

    public static function defaultTier(): string
    {
        $default = (string) config('credits.explainer.default_tier', 'short');

        return isset(self::tiers()[$default]) ? $default : (string) array_key_first(self::tiers());
    }

    /** The longest video any tier allows (validation ceiling). */
    public static function maxSeconds(): int
    {
        return max(array_column(self::tiers(), 'max_seconds'));
    }

    /** The cheapest tier that fits a length — used for projects made before tiers existed. */
    public static function tierForSeconds(int $seconds): string
    {
        $tiers = self::tiers();
        uasort($tiers, fn ($a, $b) => $a['max_seconds'] <=> $b['max_seconds']);
        foreach ($tiers as $key => $tier) {
            if ($seconds <= $tier['max_seconds']) {
                return $key;
            }
        }

        return (string) array_key_last($tiers);
    }

    /** The project's tier: the one it was bought with, else inferred from its length. */
    public static function tierFor(Project $project): string
    {
        $settings = $project->settings ?? [];
        $tier = (string) ($settings['duration_tier'] ?? '');

        return isset(self::tiers()[$tier])
            ? $tier
            : self::tierForSeconds((int) ($settings['target_seconds'] ?? 60));
    }

    public static function storyboardCost(string $tier): int
    {
        return (int) (self::tiers()[$tier]['cost'] ?? self::tiers()[self::defaultTier()]['cost']);
    }

    public static function imageCost(): int
    {
        return max(0, (int) config('credits.explainer.ai_image_cost', 25));
    }

    /**
     * Price of every render after the free one. The admin panel's credit
     * cost for the explainer template sets it when present (so it stays
     * editable without a deploy); otherwise the config value.
     */
    public static function rerenderCost(): int
    {
        $override = \Modules\Project\Services\TemplateSettingsService::creditCost('ai_explainer_video');

        return max(0, (int) ($override ?? config('credits.explainer.rerender_cost', 100)));
    }

    public static function storyboardChargeRef(Project $project): string
    {
        return 'storyboard:project:' . $project->id;
    }

    /**
     * Has this project already had its free render(s)? Counted on success
     * only, so a first render that FAILS does not use the free one up. Old
     * projects that rendered before the counter existed have an output file.
     */
    public static function freeRenderAvailable(Project $project): bool
    {
        $settings = $project->settings ?? [];
        $completed = (int) ($settings['billing']['renders_completed'] ?? 0);
        if ($completed === 0 && !empty($project->output_path)) {
            $completed = 1;
        }

        return $completed < max(0, (int) config('credits.explainer.free_renders', 1));
    }

    /**
     * Are the render-time AI fills billed? Only when the USER switched AI
     * visuals on. A maths video has them on by default (auto_visuals_auto),
     * and that default is part of the storyboard, not an extra.
     */
    public static function autoVisualsPaid(Project $project): bool
    {
        $settings = $project->settings ?? [];

        return ($settings['auto_visuals'] ?? null) === true
            && empty($settings['auto_visuals_auto']);
    }

    /**
     * Every slot the render's AI-visuals pass would draw right now, in
     * storyboard order and capped by the registry budget: image/video slots
     * with no stock query and no upload, plus earlier fills whose prompt has
     * changed since (they are redrawn, so they are a new picture). This is
     * exactly the list ExplainerVideoProcessor::fillMissingMediaSlots() works
     * through — both call it — so the quote is the bill.
     *
     * @return array<int, array{scene_id: string, slot_key: string, prompt: string, hash: string}>
     */
    public static function pendingSlotFills(Project $project): array
    {
        $settings = $project->settings ?? [];
        $assets = ExplainerAsset::where('project_id', $project->id)->get()
            ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);
        $theme = ExplainerRegistry::themeFor($settings);
        $retry = (array) ($settings['vlm_retry_suffix'] ?? []);
        $budget = self::slotFillBudget($project);
        // A hero that never shows the card's picture: drawing one is waste.
        $heroCovered = HeroScenes::coveringMedia($settings);

        $pending = [];
        foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
            if (isset($heroCovered[(string) $scene->scene_id])) {
                continue;
            }
            foreach ($scene->slots ?? [] as $slotKey => $slot) {
                if (!is_array($slot) || !in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                    continue;
                }
                // Stock slots have their own (free) fetcher.
                if (trim((string) ($slot['stock_query'] ?? '')) !== '') {
                    continue;
                }

                $built = ExplainerImagePrompt::forSlot($slot, $theme, !empty($retry[(string) $scene->scene_id]));

                $existing = $assets->get($scene->scene_id . '::' . $slotKey);
                if ($existing && Storage::disk('public')->exists($existing->path)) {
                    $isFill = str_starts_with((string) $existing->original_name, 'slot-fill:');
                    // A real upload always wins; an earlier fill is only
                    // redrawn when its prompt (theme/description) changed.
                    if (!$isFill || $existing->original_name === 'slot-fill:' . $built['hash']) {
                        continue;
                    }
                }

                $pending[] = [
                    'scene_id' => (string) $scene->scene_id,
                    'slot_key' => (string) $slotKey,
                    'prompt' => $built['prompt'],
                    'hash' => $built['hash'],
                ];
                if (count($pending) >= $budget) {
                    return $pending;
                }
            }
        }

        return $pending;
    }

    /**
     * How many pictures one render's AI-visuals pass may draw. The registry
     * budget is sized for a five-minute video; a longer tier has
     * proportionally more picture slots, and a fixed cap left the back half
     * of a long video as placeholder boxes.
     */
    public static function slotFillBudget(Project $project): int
    {
        $tierMax = self::tiers()[self::tierFor($project)]['max_seconds'] ?? 300;
        $budget = ExplainerRegistry::maxSlotFills() * max(1, (int) ceil($tierMax / 300));

        // Paid pictures are the user's purchase, confirmed with the count in
        // front of them: the cap only protects the FREE default fills, and
        // applied to a paid long video it just leaves placeholder boxes.
        return self::autoVisualsPaid($project) || empty(($project->settings ?? [])['auto_visuals_auto'])
            ? max($budget, 150)
            : $budget;
    }

    /**
     * What pressing Render would cost right now, itemised for the UI.
     *
     * @return array{free_render: bool, render: int, variants: int, images: int, image_cost: int, images_total: int, total: int}
     */
    public static function renderQuote(Project $project): array
    {
        $settings = $project->settings ?? [];
        $free = self::freeRenderAvailable($project);
        $base = $free ? 0 : self::rerenderCost();

        // The aspect-variant bundle is three renders' worth of compute. The
        // free render covers the main aspect; the two extra frames are billed
        // at (multiplier - 1) x the render price, whether or not it is free.
        $variants = 0;
        if (($settings['aspect_variants'] ?? false) === true) {
            $multiplier = (float) config('credits.aspect_variants_multiplier', 2.5);
            $variants = (int) ceil(self::rerenderCost() * max(0.0, $multiplier - 1));
        }

        $images = self::autoVisualsPaid($project) ? count(self::pendingSlotFills($project)) : 0;
        $imagesTotal = $images * self::imageCost();

        return [
            'free_render' => $free,
            'render' => $base,
            'variants' => $variants,
            'images' => $images,
            'image_cost' => self::imageCost(),
            'images_total' => $imagesTotal,
            'total' => $base + $variants + $imagesTotal,
        ];
    }

    /**
     * Give the storyboard charge back when analysis failed before a single
     * scene existed — the user paid for a storyboard and got none. A failed
     * RE-analysis of a project that already has its storyboard keeps the
     * charge (re-analysis is free; the storyboard was delivered). Idempotent.
     */
    public static function refundStoryboardIfUndelivered(Project $project): void
    {
        if ($project->explainerScenes()->exists()) {
            return;
        }

        app(\Modules\Billing\Services\CreditService::class)->refundReference(
            self::storyboardChargeRef($project),
            'Refund: storyboard could not be generated'
        );
    }

    /** Pricing constants for the UI (create page + editor). */
    public static function pricing(): array
    {
        return [
            'tiers' => self::tiers(),
            'default_tier' => self::defaultTier(),
            'free_renders' => (int) config('credits.explainer.free_renders', 1),
            'rerender_cost' => self::rerenderCost(),
            'ai_image_cost' => self::imageCost(),
            'aspect_variants_multiplier' => (float) config('credits.aspect_variants_multiplier', 2.5),
        ];
    }
}
