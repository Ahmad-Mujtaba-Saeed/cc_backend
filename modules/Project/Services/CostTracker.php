<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Models\Project;
use Modules\Project\Models\RenderCostEvent;

/**
 * Central ledger for the real money each render spends on external AI
 * services (OpenAI chat completions, OpenAI TTS, Fal image generation).
 *
 * Design constraints:
 *  - Recording must NEVER break a render — every write is wrapped and
 *    failures only log.
 *  - Call sites deep in the pipeline (script analysis, directors, stylists)
 *    don't carry a Project. The queue worker processes one job at a time, so
 *    a worker-scoped static context set at each job's entry point
 *    (AbstractVideoProcessor constructor / AnalyzeExplainerScriptJob) is
 *    safe: `CostTracker::setContext($project)` then plain record calls.
 *  - Unit rates are admin-editable (AppSetting 'cost_rates', JSON) and are
 *    applied at record time; editing rates later never rewrites history.
 */
class CostTracker
{
    public const RATES_SETTING_KEY = 'cost_rates';

    /**
     * Default USD rates. Token rates are per 1M tokens; TTS per minute of
     * generated audio; images per generated image.
     */
    public const DEFAULT_RATES = [
        'gpt_4o_mini_input_per_1m' => 0.15,
        'gpt_4o_mini_output_per_1m' => 0.60,
        'gpt_4o_input_per_1m' => 2.50,
        'gpt_4o_output_per_1m' => 10.00,
        'gpt_5_nano_input_per_1m' => 0.05,
        'gpt_5_nano_output_per_1m' => 0.40,
        'openai_tts_per_minute' => 0.015,
        'fal_image_each' => 0.05,
    ];

    public const RATE_LABELS = [
        'gpt_4o_mini_input_per_1m' => 'gpt-4o-mini input — $ per 1M tokens',
        'gpt_4o_mini_output_per_1m' => 'gpt-4o-mini output — $ per 1M tokens',
        'gpt_4o_input_per_1m' => 'gpt-4o input — $ per 1M tokens',
        'gpt_4o_output_per_1m' => 'gpt-4o output — $ per 1M tokens',
        'gpt_5_nano_input_per_1m' => 'gpt-5-nano input — $ per 1M tokens',
        'gpt_5_nano_output_per_1m' => 'gpt-5-nano output — $ per 1M tokens (reasoning tokens included)',
        'openai_tts_per_minute' => 'OpenAI TTS — $ per minute of audio',
        'fal_image_each' => 'Fal AI — $ per generated image',
    ];

    private static ?int $projectId = null;
    private static ?int $userId = null;
    private static ?string $templateType = null;

    /**
     * Bind subsequent records to a project (job entry points). Pass null to
     * clear; the optional userId covers pre-project spends like the explainer
     * script writer.
     */
    public static function setContext(?Project $project, ?int $userId = null): void
    {
        self::$projectId = $project?->id;
        self::$userId = $project?->user_id ?? $userId;
        self::$templateType = $project?->template_type;
    }

    /** Effective rates: admin overrides merged over defaults. */
    public static function rates(): array
    {
        $stored = json_decode((string) AppSetting::get(self::RATES_SETTING_KEY, ''), true);
        $rates = self::DEFAULT_RATES;

        if (is_array($stored)) {
            foreach ($rates as $key => $default) {
                if (isset($stored[$key]) && is_numeric($stored[$key]) && (float) $stored[$key] >= 0) {
                    $rates[$key] = (float) $stored[$key];
                }
            }
        }

        return $rates;
    }

    /** Persist admin rate overrides (unknown keys ignored). */
    public static function updateRates(array $input): array
    {
        $clean = [];
        foreach (self::DEFAULT_RATES as $key => $default) {
            if (isset($input[$key]) && is_numeric($input[$key]) && (float) $input[$key] >= 0) {
                $clean[$key] = round((float) $input[$key], 6);
            }
        }

        if (!empty($clean)) {
            // Merge over any existing overrides so a partial update keeps the rest.
            $existing = json_decode((string) AppSetting::get(self::RATES_SETTING_KEY, ''), true);
            $clean = array_merge(is_array($existing) ? $existing : [], $clean);
            AppSetting::set(self::RATES_SETTING_KEY, json_encode($clean));
        }

        return self::rates();
    }

    /**
     * Record a chat-completions call from its response `usage` block.
     * Rate bucket picked by model prefix (gpt-4o-mini vs gpt-4o).
     */
    public static function recordChat(?string $model, ?array $usage, string $label): void
    {
        if (!is_array($usage)) {
            return;
        }

        $in = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);
        if ($in <= 0 && $out <= 0) {
            return;
        }

        $rates = self::rates();
        $model = (string) $model;
        if (str_starts_with($model, 'gpt-5')) {
            // gpt-5-nano (and any gpt-5 sibling until it earns its own
            // bucket) — without this branch "gpt-5-nano" carries no "mini"
            // and would bill at full gpt-4o rates, a ~50x overstatement.
            $rateIn = $rates['gpt_5_nano_input_per_1m'];
            $rateOut = $rates['gpt_5_nano_output_per_1m'];
        } else {
            $isMini = $model === '' || str_contains($model, 'mini');
            $rateIn = $rates[$isMini ? 'gpt_4o_mini_input_per_1m' : 'gpt_4o_input_per_1m'];
            $rateOut = $rates[$isMini ? 'gpt_4o_mini_output_per_1m' : 'gpt_4o_output_per_1m'];
        }

        $cost = ($in / 1_000_000) * $rateIn + ($out / 1_000_000) * $rateOut;

        self::record('openai_chat', $label, $in + $out, 'tokens', $cost, [
            'model' => $model,
            'prompt_tokens' => $in,
            'completion_tokens' => $out,
        ]);
    }

    /** Record an OpenAI TTS synthesis, billed by minutes of produced audio. */
    public static function recordOpenAiTts(float $seconds, int $chars, string $label): void
    {
        if ($seconds <= 0) {
            return;
        }

        $minutes = $seconds / 60;
        $cost = $minutes * self::rates()['openai_tts_per_minute'];

        self::record('openai_tts', $label, round($minutes, 4), 'minutes', $cost, [
            'seconds' => round($seconds, 2),
            'chars' => $chars,
        ]);
    }

    /** Record Fal image generations (only ever called for real generations — caches sit above). */
    public static function recordImages(int $count, string $label): void
    {
        if ($count <= 0) {
            return;
        }

        $cost = $count * self::rates()['fal_image_each'];

        self::record('fal_image', $label, $count, 'images', $cost);
    }

    /**
     * Audit a call to an external service that is free (or externally billed)
     * — stock-footage APIs and the like. Zero cost by default; still lands in
     * the ledger so usage stays visible in AI Spend.
     */
    public static function recordExternal(string $service, string $label, float $units = 1, string $unit = 'calls', float $cost = 0.0, array $meta = []): void
    {
        self::record($service, $label, $units, $unit, $cost, $meta);
    }

    private static function record(string $service, string $label, float $units, string $unit, float $cost, array $meta = []): void
    {
        try {
            RenderCostEvent::create([
                'project_id' => self::$projectId,
                'user_id' => self::$userId,
                'template_type' => self::$templateType,
                'service' => $service,
                'label' => $label,
                'units' => round($units, 4),
                'unit' => $unit,
                'cost_usd' => round($cost, 6),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CostTracker: failed to record cost event (ignored)', [
                'service' => $service,
                'label' => $label,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
