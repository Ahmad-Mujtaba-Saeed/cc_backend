<?php

namespace Modules\Mcp\Support;

/**
 * Where MCP studio work runs, in one place.
 *
 *  - Queue: renders and recording prep go to the `mcp` connection/queue,
 *    drained only by the `mcp-worker` container — never by the worker that
 *    renders the paid templates, so a free 2-hour render can't sit in front
 *    of a paying customer's job.
 *  - Render server: optionally a separate Remotion process/machine
 *    (MCP_REMOTION_URL) so studio renders don't compete for the same CPU.
 */
final class McpRouting
{
    public static function connection(): string
    {
        return (string) config('mcp.queue.connection', 'mcp');
    }

    public static function queue(): string
    {
        return (string) config('mcp.queue.name', 'mcp');
    }

    /** Send a job to the studio's own queue. */
    public static function dispatch(object $job): void
    {
        dispatch($job->onConnection(self::connection())->onQueue(self::queue()));
    }

    /** The render server MCP renders, previews and compiles use. */
    public static function renderUrl(): string
    {
        return rtrim((string) (config('mcp.render_url') ?: config('services.remotion.url', 'http://localhost:3020')), '/');
    }

    /** Where that render server fetches storage files from (null = the main setting). */
    public static function assetBase(): ?string
    {
        $base = config('mcp.asset_base_url');

        return is_string($base) && $base !== '' ? rtrim($base, '/') : null;
    }
}
