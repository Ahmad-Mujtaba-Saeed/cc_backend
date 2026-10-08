<?php

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Models\McpToken;
use Modules\Mcp\Services\McpRenderService;
use Modules\Project\Models\Project;
use Modules\Project\Support\McpOrigin;

/**
 * The dashboard's "Connect Claude" page (signed-in user, Sanctum):
 * connection secrets, how to plug them into each client, and the videos the
 * user's model made — finished videos only, never a storyboard to edit.
 */
class McpDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) config('mcp.enabled', true),
                'endpoint' => $this->endpoint(),
                // Personal keys only — OAuth access tokens are listed by connection.
                'tokens' => McpToken::where('user_id', $user->id)->whereNull('revoked_at')->whereNull('grant_id')
                    ->orderByDesc('created_at')->get()
                    ->map(fn (McpToken $t) => $this->tokenRow($t))->all(),
                // Apps the user connected with "Connect" (OAuth).
                'oauth_connections' => \Modules\Mcp\Models\McpOAuthGrant::with('client')
                    ->where('user_id', $user->id)->whereNull('revoked_at')
                    ->where(fn ($q) => $q->whereNull('refresh_expires_at')->orWhere('refresh_expires_at', '>', now()))
                    ->orderByDesc('created_at')->get()
                    ->map(fn ($g) => [
                        'id' => $g->id,
                        'name' => $g->client?->name ?? 'App',
                        'last_used_at' => optional($g->last_used_at)->toIso8601String(),
                        'last_client' => $g->last_client,
                        'created_at' => optional($g->created_at)->toIso8601String(),
                    ])->all(),
                'oauth' => [
                    'enabled' => true,
                    'issuer' => \Modules\Mcp\OAuth\McpOAuthService::issuer(),
                ],
                'limits' => [
                    'max_minutes' => (int) round(config('mcp.max_video_seconds', 900) / 60),
                    'renders_per_day' => (int) config('mcp.renders_per_day', 5),
                    'fps' => (int) config('mcp.default_fps', 60),
                ],
            ],
        ]);
    }

    public function createToken(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|nullable|string|max:80']);
        $user = auth()->user();
        $live = McpToken::where('user_id', $user->id)->whereNull('revoked_at')->whereNull('grant_id')->count();
        if ($live >= (int) config('mcp.max_tokens_per_user', 10)) {
            return response()->json(['success' => false, 'message' => 'You have the maximum number of connections. Revoke one first.'], 422);
        }
        [$token, $plain] = McpToken::mint($user, (string) ($data['name'] ?? 'Claude'));

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $this->tokenRow($token),
                // Shown ONCE — only the hash is stored.
                'secret' => $plain,
                'url_with_secret' => $this->endpoint() . '/' . $plain,
                'endpoint' => $this->endpoint(),
            ],
        ], 201);
    }

    public function revokeToken(int $id): JsonResponse
    {
        $token = McpToken::where('user_id', auth()->id())->where('id', $id)->whereNull('revoked_at')->whereNull('grant_id')->first();
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $token->update(['revoked_at' => now()]);

        return response()->json(['success' => true]);
    }

    /** The videos the user's model made, for the page's gallery. */
    public function videos(): JsonResponse
    {
        $projects = Project::where('user_id', auth()->id())
            ->where('settings->origin', McpOrigin::ORIGIN)
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $projects->map(fn (Project $p) => $this->videoRow($p))->all(),
        ]);
    }

    public function video(int $id): JsonResponse
    {
        $project = Project::where('user_id', auth()->id())->where('id', $id)->first();
        if (!$project || !McpOrigin::is($project)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->videoRow($project, true)]);
    }

    private function videoRow(Project $p, bool $detail = false): array
    {
        $s = $p->settings ?? [];
        $ready = $p->status === 'completed' && $p->output_path && Storage::disk('public')->exists($p->output_path);
        $row = [
            'id' => $p->id,
            'title' => $p->title,
            'mode' => $s['mcp']['mode'] ?? 'narrated',
            'aspect_ratio' => $p->aspect_ratio,
            'status' => match ($p->status) {
                'queued' => 'queued',
                'processing' => 'rendering',
                'completed' => 'completed',
                'failed' => 'failed',
                default => 'building',
            },
            'progress' => (int) $p->progress,
            'scenes' => $p->explainerScenes()->count(),
            'duration' => $p->duration ? round((float) $p->duration, 1) : null,
            'fps' => (int) ($s['render_fps'] ?? 30),
            'error' => $p->status === 'failed' ? $p->error_message : null,
            'thumbnail_url' => $p->thumbnail_path ? Storage::disk('public')->url($p->thumbnail_path) : null,
            'video_url' => $ready ? Storage::disk('public')->url($p->output_path) : null,
            'created_at' => optional($p->created_at)->toIso8601String(),
            'updated_at' => optional($p->updated_at)->toIso8601String(),
        ];
        if ($detail && $ready) {
            $row['download_url'] = McpRenderService::signedDownload($p, 'video');
            $row['srt_url'] = !empty($s['srt_path']) ? Storage::disk('public')->url($s['srt_path']) : null;
        }

        return $row;
    }

    private function tokenRow(McpToken $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'hint' => $t->token_hint . '…',
            'last_used_at' => optional($t->last_used_at)->toIso8601String(),
            'last_client' => $t->last_client,
            'created_at' => optional($t->created_at)->toIso8601String(),
        ];
    }

    private function endpoint(): string
    {
        return rtrim((string) config('mcp.public_url', config('app.url')), '/') . '/mcp';
    }
}
