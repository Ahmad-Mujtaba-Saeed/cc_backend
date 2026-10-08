<?php

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Mcp\Jobs\PreparePresenterVideoJob;
use Modules\Mcp\Models\McpUpload;
use Modules\Mcp\Services\McpVideoService;
use Modules\Mcp\Support\McpScenes;

/**
 * The upload page behind a link the model gave the user (/upload?t=…).
 *
 * The link token IS the grant (hashed at rest, expiring, single purpose), so
 * these routes need no session: the user may open the link on a phone that
 * never signed in. Files arrive in ordered chunks — a 15-minute phone
 * recording is gigabytes, far past one request's body limit — and are
 * appended to a private file that is never served.
 *
 *   GET  /api/mcp/upload/{token}            what this link is for + progress
 *   POST /api/mcp/upload/{token}/chunk      offset, total, file (one chunk)
 *   POST /api/mcp/upload/{token}/complete   assemble + hand off
 */
class McpUploadController extends Controller
{
    private const VIDEO_EXT = ['mp4', 'mov', 'm4v', 'webm', 'mkv'];
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    public function show(string $token): JsonResponse
    {
        $upload = $this->resolve($token);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }

        return response()->json(['success' => true, 'data' => $this->summary($upload)]);
    }

    public function chunk(Request $request, string $token): JsonResponse
    {
        $upload = $this->resolve($token, true);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }

        $data = $request->validate([
            'offset' => 'required|integer|min:0',
            'total' => 'required|integer|min:1',
            'name' => 'sometimes|string|max:255',
            'file' => 'required|file|max:' . (int) ceil(((int) config('mcp.upload.chunk_bytes')) * 2 / 1024),
        ]);

        $max = $upload->purpose === 'presenter'
            ? (int) config('mcp.upload.max_bytes')
            : (int) config('mcp.upload.image_max_bytes');
        $total = (int) $data['total'];
        if ($total > $max) {
            return $this->fail('This file is too large (limit ' . round($max / 1024 / 1024) . ' MB).', 413);
        }
        $name = (string) ($data['name'] ?? $upload->original_name ?? 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $upload->purpose === 'presenter' ? self::VIDEO_EXT : self::IMAGE_EXT;
        if (!in_array($ext, $allowed, true)) {
            return $this->fail('Please choose a ' . implode(', ', $allowed) . ' file.', 415);
        }

        // One writer per link: chunks append in order.
        return Cache::lock("mcp:upload:{$upload->id}", 120)->block(60, function () use ($upload, $data, $total, $name) {
            $upload->refresh();
            $offset = (int) $data['offset'];
            if ($offset !== (int) $upload->received_bytes) {
                // The client resumes from what we actually have.
                return response()->json([
                    'success' => false,
                    'code' => 'offset_mismatch',
                    'message' => 'Resume from the server offset.',
                    'data' => ['received_bytes' => (int) $upload->received_bytes],
                ], 409);
            }
            if ($upload->expected_bytes && $upload->expected_bytes !== $total) {
                return $this->fail('The file changed mid-upload — start again with the same file.', 409);
            }

            $disk = Storage::disk('local');
            $part = $upload->partPath();
            $disk->makeDirectory(dirname($part));
            $in = fopen($data['file']->getRealPath(), 'rb');
            $out = fopen($disk->path($part), $offset === 0 ? 'wb' : 'ab');
            if (!$in || !$out) {
                return $this->fail('The server could not store this chunk.', 500);
            }
            $bytes = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $received = $offset + (int) $bytes;
            if ($received > $total) {
                return $this->fail('More bytes than announced.', 422);
            }
            $upload->update([
                'status' => 'uploading',
                'original_name' => mb_substr($name, 0, 255),
                'expected_bytes' => $total,
                'received_bytes' => $received,
            ]);

            return response()->json(['success' => true, 'data' => ['received_bytes' => $received]]);
        });
    }

    public function complete(string $token): JsonResponse
    {
        $upload = $this->resolve($token, true);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }
        if (!$upload->expected_bytes || $upload->received_bytes !== $upload->expected_bytes) {
            return $this->fail('The upload is not complete yet.', 409);
        }

        $disk = Storage::disk('local');
        $part = $upload->partPath();
        if (!$disk->exists($part) || $disk->size($part) !== $upload->expected_bytes) {
            return $this->fail('The uploaded file is incomplete — please upload it again.', 409);
        }
        $project = $upload->project;

        if ($upload->purpose === 'presenter') {
            $final = "mcp_uploads/{$upload->project_id}/presenter_{$upload->id}." . strtolower(pathinfo((string) $upload->original_name, PATHINFO_EXTENSION));
            $disk->move($part, $final);
            $upload->update(['status' => 'processing', 'path' => $final]);
            McpVideoService::locked($project, function ($p) {
                $s = $p->settings ?? [];
                $s['mcp']['presenter'] = ['status' => 'processing', 'step' => 'queued'];
                $p->update(['settings' => $s]);
            });
            // The studio's own queue (mcp-worker), never the paid render queue.
            \Modules\Mcp\Support\McpRouting::dispatch(new PreparePresenterVideoJob($project, $upload));

            return response()->json(['success' => true, 'data' => $this->summary($upload->fresh()), 'message' => 'Received. We are preparing your recording — you can go back to Claude now.']);
        }

        // A picture for the media shelf: check it really is one, then keep it.
        $binary = $disk->get($part);
        $size = @getimagesizefromstring($binary);
        if (!$size || !in_array($size[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $disk->delete($part);
            $upload->update(['status' => 'failed', 'error' => 'not an image']);

            return $this->fail('That file is not a JPEG, PNG or WebP picture.', 415);
        }
        $ext = match ($size[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
        $name = McpScenes::mediaName((string) ($upload->asset_name ?: pathinfo((string) $upload->original_name, PATHINFO_FILENAME)));
        $relative = "projects/{$project->id}/explainer/mcp_media/{$name}_" . Str::random(6) . ".{$ext}";
        Storage::disk('public')->put($relative, $binary);
        $disk->delete($part);
        (new McpVideoService($project->user))->putMedia($project, $name, [
            'path' => $relative,
            'kind' => 'image',
            'source' => 'upload',
            'title' => (string) $upload->original_name,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ]);
        $upload->update(['status' => 'ready', 'path' => $relative]);

        return response()->json(['success' => true, 'data' => $this->summary($upload->fresh()), 'message' => "Added to the video as \"{$name}\". You can go back to Claude now."]);
    }

    private function resolve(string $token, bool $writable = false): McpUpload|JsonResponse
    {
        $upload = McpUpload::findByToken($token);
        if (!$upload || !$upload->project) {
            return $this->fail('This upload link is not valid.', 404);
        }
        if ($writable) {
            if ($upload->expired()) {
                return $this->fail('This upload link has expired — ask Claude for a new one.', 410);
            }
            if (in_array($upload->status, ['processing', 'ready'], true)) {
                return $this->fail('This link has already been used. Ask Claude for a new link to upload another file.', 409);
            }
        }

        return $upload;
    }

    private function summary(McpUpload $upload): array
    {
        $presenter = (array) ($upload->project->settings['mcp']['presenter'] ?? []);

        return [
            'purpose' => $upload->purpose,
            'video_title' => $upload->project->title,
            'asset_name' => $upload->asset_name,
            'status' => $upload->status,
            'expired' => $upload->expired(),
            'received_bytes' => (int) $upload->received_bytes,
            'expected_bytes' => $upload->expected_bytes,
            'chunk_bytes' => (int) config('mcp.upload.chunk_bytes'),
            'max_bytes' => $upload->purpose === 'presenter' ? (int) config('mcp.upload.max_bytes') : (int) config('mcp.upload.image_max_bytes'),
            'max_minutes' => (int) round(config('mcp.max_video_seconds', 900) / 60),
            'accept' => $upload->purpose === 'presenter' ? '.' . implode(',.', self::VIDEO_EXT) : '.' . implode(',.', self::IMAGE_EXT),
            'processing' => $upload->purpose === 'presenter' ? array_intersect_key($presenter, array_flip(['status', 'step', 'error'])) : null,
            'error' => $upload->error,
        ];
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
