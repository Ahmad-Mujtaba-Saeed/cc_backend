<?php

namespace Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Models\ApiCredential;

/**
 * Admin CRUD for the provider API key pool (gated by `manage-settings`).
 * Every mutation returns the refreshed grouped key list (masked) so the
 * settings UI can re-render without a second request.
 */
class ApiCredentialController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', ApiCredential::PROVIDERS)],
            'label' => ['nullable', 'string', 'max:100'],
            'credential' => ['required', 'string', 'max:1000'],
            'make_default' => ['sometimes', 'boolean'],
        ]);

        $credential = ApiCredential::create([
            'provider' => $data['provider'],
            'label' => $data['label'] ?? null,
            'credential' => trim($data['credential']),
            'is_active' => true,
            // The provider's first key automatically becomes its default.
            'is_default' => !ApiCredential::query()
                ->where('provider', $data['provider'])
                ->where('is_default', true)
                ->exists(),
        ]);

        if (!empty($data['make_default'])) {
            $credential->makeDefault();
        }

        return response()->json([
            'message' => 'API key added.',
            'credentials' => ApiCredential::groupedForAdmin(),
        ], 201);
    }

    public function update(Request $request, ApiCredential $credential): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'credential' => ['sometimes', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('credential', $data)) {
            $data['credential'] = trim($data['credential']);
            // A replaced secret starts with a clean failure history.
            $data['failure_count'] = 0;
            $data['last_error'] = null;
            $data['last_error_at'] = null;
        }

        $credential->fill($data)->save();

        // A deactivated default hands the default role to the next active key.
        if ($credential->is_default && !$credential->is_active) {
            $credential->forceFill(['is_default' => false])->save();
            ApiCredential::forProvider($credential->provider)->first()?->makeDefault();
        }

        return response()->json([
            'message' => 'API key updated.',
            'credentials' => ApiCredential::groupedForAdmin(),
        ]);
    }

    public function makeDefault(ApiCredential $credential): JsonResponse
    {
        $credential->makeDefault();

        return response()->json([
            'message' => 'Default key updated.',
            'credentials' => ApiCredential::groupedForAdmin(),
        ]);
    }

    public function destroy(ApiCredential $credential): JsonResponse
    {
        $provider = $credential->provider;
        $wasDefault = $credential->is_default;

        $credential->delete();

        if ($wasDefault) {
            ApiCredential::forProvider($provider)->first()?->makeDefault();
        }

        return response()->json([
            'message' => 'API key deleted.',
            'credentials' => ApiCredential::groupedForAdmin(),
        ]);
    }
}
