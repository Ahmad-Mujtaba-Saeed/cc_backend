<?php

namespace Modules\Mcp\Console;

use Illuminate\Console\Command;
use Modules\Mcp\Services\McpCatalog;

/**
 * Pre-record the free narrator samples list_voices links to. The first
 * list_voices on a fresh server otherwise synthesizes them inline (each new
 * Kokoro voice is also downloaded once, ~10 s apiece) and the model waits.
 *
 *   docker compose exec app php artisan mcp:warm
 */
class WarmMcpStudioCommand extends Command
{
    protected $signature = 'mcp:warm';

    protected $description = 'Pre-record the MCP studio voice samples';

    public function handle(): int
    {
        $missing = 0;
        for ($pass = 0; $pass < 6; $pass++) {
            $voices = McpCatalog::voices(0, true);
            $missing = count(array_filter($voices, fn ($v) => str_starts_with((string) $v['engine'], 'kokoro') && empty($v['preview_url'])));
            $this->info('Voice samples missing: ' . $missing);
            if ($missing === 0) {
                break;
            }
        }

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
