<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Modules\AccessControl\Models\ApiCredential;
use Modules\Project\Contracts\YoutubeDownloaderInterface;

/**
 * Wraps the selected provider's downloader with API-key failover.
 *
 * Keys come from the admin-managed api_credentials pool (never .env). On
 * initDownload() each active key is tried in order — default key first —
 * and a key that errors is recorded (failure_count / last_error, shown in
 * the admin UI) before the next one is attempted. The key resolution is
 * lazy so constructing this class never fails: projects that don't use a
 * YouTube URL are unaffected by missing keys.
 *
 * pollUntilReady() delegates to whichever concrete service succeeded;
 * extractVideoId()/streamToStorage() need no key and come from the base.
 */
class FailoverYoutubeDownloader extends AbstractYoutubeDownloader
{
    private ?YoutubeDownloaderInterface $active = null;

    public function __construct(private string $provider)
    {
    }

    public function initDownload(string $ytUrl): array
    {
        // A malformed URL fails every key identically — reject it before
        // consuming failover attempts.
        $this->extractVideoId($ytUrl);

        $credentials = ApiCredential::forProvider($this->provider);

        if ($credentials->isEmpty()) {
            throw new \Exception(
                "No active {$this->provider} API key configured. "
                . 'Add one in Settings → Integrations.'
            );
        }

        $lastError = null;

        foreach ($credentials->values() as $index => $credential) {
            $service = $this->buildService($credential);

            try {
                $result = $service->initDownload($ytUrl);

                $credential->markSuccess();
                $this->active = $service;

                if ($index > 0) {
                    Log::info('FailoverYoutubeDownloader: fallback key succeeded', [
                        'provider' => $this->provider,
                        'credential_id' => $credential->id,
                        'keys_tried' => $index + 1,
                    ]);
                }

                return $result;
            } catch (\Exception $e) {
                $lastError = $e;
                $credential->markFailure($e->getMessage());

                Log::warning('FailoverYoutubeDownloader: key failed, trying next', [
                    'provider' => $this->provider,
                    'credential_id' => $credential->id,
                    'credential_label' => $credential->label,
                    'error' => $e->getMessage(),
                    'remaining_keys' => $credentials->count() - $index - 1,
                ]);
            }
        }

        throw new \Exception(sprintf(
            'All %d %s API key(s) failed. Last error: %s',
            $credentials->count(),
            $this->provider,
            $lastError?->getMessage() ?? 'unknown'
        ));
    }

    public function pollUntilReady(string $progressUrl, int $maxAttempts = 20, int $pollIntervalSeconds = 10): string
    {
        if ($this->active === null) {
            throw new \LogicException('pollUntilReady() called before a successful initDownload()');
        }

        return $this->active->pollUntilReady($progressUrl, $maxAttempts, $pollIntervalSeconds);
    }

    private function buildService(ApiCredential $credential): YoutubeDownloaderInterface
    {
        return match ($this->provider) {
            'apify' => new ApifyVideoDownloadService($credential->credential),
            default => new YTVideoDownloadService($credential->credential),
        };
    }
}
