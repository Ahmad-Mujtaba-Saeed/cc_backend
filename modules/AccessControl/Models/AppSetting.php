<?php

namespace Modules\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime application setting (key/value). Read everywhere through the cached
 * static helpers so a switch made by an admin takes effect immediately for
 * subsequent requests/jobs without a deploy.
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = ['key', 'value'];

    private const CACHE_PREFIX = 'app_setting:';

    /**
     * Read a setting, falling back to $default when unset.
     */
    public static function get(string $key, $default = null)
    {
        $value = Cache::rememberForever(self::CACHE_PREFIX . $key, function () use ($key) {
            // Sentinel so a genuinely-null stored value isn't re-queried forever.
            return self::query()->where('key', $key)->value('value') ?? '__null__';
        });

        return $value === '__null__' ? $default : $value;
    }

    /**
     * Upsert a setting and refresh its cache entry.
     */
    public static function set(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
