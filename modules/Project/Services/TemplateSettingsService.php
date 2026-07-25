<?php

namespace Modules\Project\Services;

use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Processors\TemplateProcessorFactory;

/**
 * Admin-editable per-template controls: enabled/disabled, credit cost
 * override, and "trending" flag for the templates page hero slot.
 *
 * Stored as one JSON blob (AppSetting has no JSON casting — see
 * CostTracker for the same manual encode/decode pattern this mirrors).
 * Overrides are merged over computed defaults so a template added later
 * to TemplateProcessorFactory always appears with sane defaults even
 * with no stored override yet.
 */
class TemplateSettingsService
{
    public const SETTING_KEY = 'template_settings';

    public const MIN_CREDIT_COST = 1;
    public const MAX_CREDIT_COST = 1000;

    /** Effective settings for every known template: overrides merged over defaults. */
    public static function all(): array
    {
        $stored = json_decode((string) AppSetting::get(self::SETTING_KEY, ''), true);
        $stored = is_array($stored) ? $stored : [];

        $templates = TemplateProcessorFactory::getAvailableTemplates();
        $defaultCosts = (array) config('credits.templates', []);
        $defaultCost = (int) config('credits.default', 3);

        $result = [];
        foreach ($templates as $type => $meta) {
            $override = $stored[$type] ?? [];

            $result[$type] = [
                'enabled' => array_key_exists('enabled', $override)
                    ? (bool) $override['enabled']
                    : true,
                'credit_cost' => isset($override['credit_cost']) && is_numeric($override['credit_cost'])
                    ? self::clampCost((int) $override['credit_cost'])
                    : (int) ($defaultCosts[$type] ?? $defaultCost),
                'trending' => array_key_exists('trending', $override)
                    ? (bool) $override['trending']
                    : ($type === 'ai_explainer_video'),
            ];
        }

        return $result;
    }

    public static function isEnabled(string $templateType): bool
    {
        return self::all()[$templateType]['enabled'] ?? true;
    }

    /** Admin-overridden cost, or null if the template has no known entry. */
    public static function creditCost(string $templateType): ?int
    {
        $all = self::all();

        return isset($all[$templateType]) ? $all[$templateType]['credit_cost'] : null;
    }

    public static function isTrending(string $templateType): bool
    {
        return self::all()[$templateType]['trending'] ?? false;
    }

    /**
     * Persist admin edits. $input is keyed by template_type, each value an
     * associative array with any of enabled/credit_cost/trending. Unknown
     * template types and unknown fields are ignored. Merges over existing
     * overrides so a partial update (e.g. one row saved) keeps the rest.
     */
    public static function update(array $input): array
    {
        $known = array_keys(TemplateProcessorFactory::getAvailableTemplates());
        $existing = json_decode((string) AppSetting::get(self::SETTING_KEY, ''), true);
        $existing = is_array($existing) ? $existing : [];

        foreach ($input as $type => $fields) {
            if (!in_array($type, $known, true) || !is_array($fields)) {
                continue;
            }

            $row = $existing[$type] ?? [];

            if (array_key_exists('enabled', $fields)) {
                $row['enabled'] = (bool) $fields['enabled'];
            }
            if (array_key_exists('credit_cost', $fields) && is_numeric($fields['credit_cost'])) {
                $row['credit_cost'] = self::clampCost((int) $fields['credit_cost']);
            }
            if (array_key_exists('trending', $fields)) {
                $row['trending'] = (bool) $fields['trending'];
            }

            $existing[$type] = $row;
        }

        AppSetting::set(self::SETTING_KEY, json_encode($existing));

        return self::all();
    }

    private static function clampCost(int $cost): int
    {
        return max(self::MIN_CREDIT_COST, min(self::MAX_CREDIT_COST, $cost));
    }
}
