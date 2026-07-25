<?php

namespace Modules\AccessControl\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An API key/token for an external provider (rapidapi, apify), managed by
 * admins at runtime. A provider can hold several keys: consumers fetch them
 * via forProvider() (default key first) and fail over to the next active key
 * when one errors, recording success/failure per key so the admin UI can
 * surface unhealthy keys.
 *
 * This table is the ONLY source of these secrets at runtime — the services
 * deliberately no longer fall back to .env values.
 */
class ApiCredential extends Model
{
    public const PROVIDERS = ['rapidapi', 'apify', 'pixabay', 'pexels'];

    protected $table = 'api_credentials';

    protected $fillable = [
        'provider',
        'label',
        'credential',
        'is_default',
        'is_active',
        'failure_count',
        'last_error',
        'last_error_at',
        'last_used_at',
        'last_success_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'failure_count' => 'integer',
        'last_error_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];

    /**
     * Active keys for a provider in failover try-order: default first,
     * then oldest to newest.
     */
    public static function forProvider(string $provider): Collection
    {
        return self::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    public static function hasActive(string $provider): bool
    {
        return self::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Make this key the provider's default (and ensure it is usable).
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            self::query()
                ->where('provider', $this->provider)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    public function markSuccess(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'last_success_at' => now(),
            'failure_count' => 0,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    public function markFailure(string $error): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'failure_count' => $this->failure_count + 1,
            'last_error' => Str::limit($error, 500),
            'last_error_at' => now(),
        ])->save();
    }

    /**
     * Never expose the full secret to the frontend.
     */
    public function maskedCredential(): string
    {
        $value = (string) $this->credential;
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('•', max(4, $length));
        }

        return substr($value, 0, 4) . '••••' . substr($value, -4);
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'credential_masked' => $this->maskedCredential(),
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'failure_count' => $this->failure_count,
            'last_error' => $this->last_error,
            'last_error_at' => $this->last_error_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
        ];
    }

    /**
     * All keys (masked) grouped by provider for the admin settings UI.
     *
     * @return array<string, array<int, array>>
     */
    public static function groupedForAdmin(): array
    {
        $grouped = array_fill_keys(self::PROVIDERS, []);

        self::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->each(function (self $credential) use (&$grouped) {
                $grouped[$credential->provider][] = $credential->toAdminArray();
            });

        return $grouped;
    }
}
