<?php

namespace Modules\Project\Support;

use Modules\Project\Models\Project;

/**
 * Was this explainer made over MCP (by the user's own LLM, modules/Mcp)?
 *
 * Those videos are built scene by scene by the model and are never edited in
 * the storyboard, so the Project module needs to know about them in a few
 * places: the processor factory routes them to the free render pipeline, and
 * the storyboard endpoints refuse to edit them (editing there could reach the
 * paid features MCP videos are deliberately kept away from).
 */
final class McpOrigin
{
    public const ORIGIN = 'mcp';

    public static function is(Project|array|null $projectOrSettings): bool
    {
        $settings = $projectOrSettings instanceof Project
            ? ($projectOrSettings->settings ?? [])
            : (array) ($projectOrSettings ?? []);

        return ($settings['origin'] ?? null) === self::ORIGIN;
    }

    /** Presenter mode: the user's own talking-head recording drives the timeline. */
    public static function isPresenter(Project|array|null $projectOrSettings): bool
    {
        $settings = $projectOrSettings instanceof Project
            ? ($projectOrSettings->settings ?? [])
            : (array) ($projectOrSettings ?? []);

        return self::is($settings) && ($settings['mcp']['mode'] ?? 'narrated') === 'presenter';
    }
}
