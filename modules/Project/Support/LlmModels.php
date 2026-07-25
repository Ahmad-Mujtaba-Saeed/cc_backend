<?php

namespace Modules\Project\Support;

use Modules\AccessControl\Models\AppSetting;

/**
 * The admin-controlled chat-model switch.
 *
 * Every LLM call in the app is OpenAI chat-completions, but the model is not
 * one value: each call site asks for a ROLE, and each role has its own env
 * default (see config/services.php `openai.*_model`) chosen deliberately —
 * the canvas director needs a stronger model than the script writer, and the
 * VLM services need a model that can actually see.
 *
 * An admin picks ONE model here and it overrides every role at once, without
 * a deploy. The default 'auto' means "leave the per-role env defaults alone",
 * so the switch is opt-in and an untouched install behaves exactly as before.
 *
 * The one guard: {@see VISION_CAPABLE}. A text-only global pick would silently
 * break VLM label placement and frame review (they send images), so the `vlm`
 * role ignores an override it cannot see through and keeps its own default.
 */
class LlmModels
{
    public const SETTING_KEY = 'llm_model';

    /** Sentinel meaning "no override — use each role's configured default". */
    public const AUTO = 'auto';

    /**
     * Curated selectable models, cheapest/lightest first. Extend this list to
     * offer more; anything not listed is rejected by the settings validator.
     */
    public const MODELS = [
        'gpt-4o-mini' => 'GPT-4o mini — cheapest, fast, vision-capable',
        'gpt-4o' => 'GPT-4o — stronger reasoning, vision-capable',
        'gpt-4.1-mini' => 'GPT-4.1 mini — cheap, larger context, vision-capable',
        'gpt-4.1' => 'GPT-4.1 — strongest, larger context, vision-capable',
    ];

    /**
     * Models that accept image inputs. Only these may serve the `vlm` role.
     */
    public const VISION_CAPABLE = ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'];

    /**
     * Per-role fallbacks, used whenever the override is 'auto' (or invalid).
     * These mirror the constructors that used to read config() directly.
     */
    private const ROLE_CONFIG = [
        'explainer' => ['services.openai.explainer_model', 'gpt-4o-mini'],
        'director' => ['services.openai.director_model', 'gpt-4o'],
        'vlm' => ['services.openai.vlm_model', 'gpt-4o-mini'],
        // Non-explainer templates (clip selection, script generation). These
        // were hardcoded to gpt-4o-mini and had no env knob of their own.
        'general' => ['services.openai.general_model', 'gpt-4o-mini'],
    ];

    /**
     * Adapt a chat-completions payload to the model family it targets.
     *
     * GPT-5 reasoning models change the API contract in three ways that
     * would 400 our existing calls: `temperature` is rejected (only the
     * default is allowed), `max_tokens` is replaced by
     * `max_completion_tokens`, and reasoning depth is set via
     * `reasoning_effort`. Every call site whose model can resolve to a
     * gpt-5* (the math valve, the text-review pass) passes its payload
     * through here; for any other model this is a no-op, so the 4o-mini
     * paths stay byte-identical.
     *
     * `$effort`: 'minimal' for extraction/nomination work (fast, cheap),
     * 'low' for the maths composition calls where a beat of reasoning is
     * exactly what we are paying for.
     */
    public static function tune(array $payload, string $effort = 'minimal'): array
    {
        $model = (string) ($payload['model'] ?? '');
        if (!str_starts_with($model, 'gpt-5')) {
            return $payload;
        }

        unset($payload['temperature']);
        $payload['reasoning_effort'] = $payload['reasoning_effort'] ?? $effort;
        if (array_key_exists('max_tokens', $payload)) {
            // Reasoning tokens bill against max_completion_tokens, so a
            // budget sized for the ANSWER alone starves or truncates the
            // reply. A floor is not enough — project 91's first nano run had
            // the tree's 1700-token working call come back truncated
            // ("working call unusable") because 'low' reasoning ate the cap
            // from the inside. Add effort-sized HEADROOM on top of the
            // caller's answer budget; a cap is a ceiling, not a spend, so
            // the headroom costs nothing on well-behaved calls.
            $headroom = $payload['reasoning_effort'] === 'minimal' ? 1024 : 3072;
            $payload['max_completion_tokens'] = max((int) $payload['max_tokens'] + $headroom, 1000);
            unset($payload['max_tokens']);
        }

        return $payload;
    }

    /** The admin's selection, or AUTO when unset/unrecognised. */
    public static function selected(): string
    {
        $model = (string) AppSetting::get(self::SETTING_KEY, self::AUTO);

        return array_key_exists($model, self::MODELS) ? $model : self::AUTO;
    }

    /**
     * The model a given role should actually use.
     *
     * Roles: 'explainer' (script writing, analysis, composing, packaging…),
     * 'math' (the maths escalation valve), 'director' (canvas/composition),
     * 'vlm' (vision review — never handed a text-only model).
     */
    public static function for(string $role): string
    {
        $override = self::selected();

        if ($override !== self::AUTO) {
            // A text-only pick must not reach the vision services.
            if ($role !== 'vlm' || in_array($override, self::VISION_CAPABLE, true)) {
                return $override;
            }
        }

        return self::configured($role);
    }

    /**
     * The role's env/config default, ignoring any admin override.
     *
     * `math` is the odd one: it is an opt-in escalation on top of the
     * explainer model, so an unset value means "same as explainer".
     */
    public static function configured(string $role): string
    {
        if ($role === 'math') {
            $math = config('services.openai.explainer_model_math');

            return is_string($math) && $math !== '' ? $math : self::configured('explainer');
        }

        [$key, $default] = self::ROLE_CONFIG[$role] ?? self::ROLE_CONFIG['explainer'];

        $model = config($key, $default);

        return is_string($model) && $model !== '' ? $model : $default;
    }

    /**
     * Settings-UI state: what the admin picked, plus what each role resolves
     * to once the vision guard has had its say.
     */
    public static function state(): array
    {
        return [
            'model' => self::selected(),
            'options' => self::MODELS,
            'vision_capable' => self::VISION_CAPABLE,
            'effective' => [
                'explainer' => self::for('explainer'),
                'math' => self::for('math'),
                'director' => self::for('director'),
                'vlm' => self::for('vlm'),
                'general' => self::for('general'),
            ],
        ];
    }
}
