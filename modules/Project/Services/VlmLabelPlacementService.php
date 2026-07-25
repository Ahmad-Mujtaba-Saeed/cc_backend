<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VlmLabelPlacementService — positions labeled_diagram part labels.
 *
 * The planner only writes label TEXTS (it cannot know where a part will land
 * in an image that doesn't exist yet); after the slot illustration is
 * generated, ONE cheap vision call looks at the actual image and returns a
 * normalized (x, y) per part, which becomes the slot's callout pins. The
 * renderer draws leader lines to those pins; when this call fails or a part
 * isn't visible, the card falls back to its legend row — so this service can
 * only ever upgrade the scene, never break it. Tracked as 'vlm_label_place'.
 */
class VlmLabelPlacementService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: (string) env('OPENAI_API_KEY');
        $this->model = LlmModels::for('vlm');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  string  $imageAbsPath  the diagram image on disk
     * @param  string[]  $labels  2-4 part names to locate
     * @return array<int, array{x: float, y: float, text: string, anchor: string}>|null
     *         null when placement could not run or found fewer than 2 parts
     */
    public function place(string $imageAbsPath, array $labels): ?array
    {
        $labels = array_values(array_filter(array_map(
            fn ($l) => mb_substr(trim((string) $l), 0, 80),
            $labels
        ), fn ($l) => $l !== ''));
        $labels = array_slice($labels, 0, 4);

        if (!$this->isConfigured() || count($labels) < 2 || !is_file($imageAbsPath)) {
            return null;
        }

        $dataUri = $this->encodeDownscaled($imageAbsPath);
        if ($dataUri === null) {
            return null;
        }

        $list = implode("\n", array_map(fn ($l) => "- {$l}", $labels));
        $content = [
            [
                'type' => 'text',
                'text' => "This illustration will get leader-line labels drawn over it. For EACH part name below, return where that part sits in the image as normalized coordinates: x from 0 (left edge) to 1 (right edge), y from 0 (top edge) to 1 (bottom edge), pointing at the CENTER of that part. Skip any part that is not clearly visible. Use the part names VERBATIM.\n\nParts:\n{$list}\n\nReturn ONLY JSON: {\"labels\":[{\"text\":\"<part name verbatim>\",\"x\":0.42,\"y\":0.31}, ...]}",
            ],
            [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUri, 'detail' => 'low'],
            ],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'temperature' => 0,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (\Throwable $e) {
            Log::warning('VlmLabelPlacementService: request threw (non-fatal): ' . $e->getMessage());

            return null;
        }

        if (!$response->successful()) {
            Log::warning('VlmLabelPlacementService: request failed (non-fatal)', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return null;
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'vlm_label_place');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        $placedRaw = is_array($parsed) ? (array) ($parsed['labels'] ?? []) : [];

        // Match returned rows back to the requested labels so the model can
        // neither invent labels nor reword them; coordinates hug a small
        // margin so a pin never sits ON the frame edge.
        $out = [];
        foreach ($labels as $label) {
            foreach ($placedRaw as $row) {
                if (!is_array($row) || trim((string) ($row['text'] ?? '')) !== $label) {
                    continue;
                }
                $x = $row['x'] ?? null;
                $y = $row['y'] ?? null;
                if (!is_numeric($x) || !is_numeric($y)) {
                    continue;
                }
                $out[] = [
                    'x' => round(max(0.03, min(0.97, (float) $x)), 4),
                    'y' => round(max(0.03, min(0.97, (float) $y)), 4),
                    'text' => $label,
                    'anchor' => 'auto',
                ];
                break;
            }
        }

        if (count($out) < 2) {
            Log::info('VlmLabelPlacementService: fewer than 2 parts located (legend fallback)', [
                'requested' => count($labels),
                'placed' => count($out),
            ]);

            return null;
        }

        return $out;
    }

    /**
     * Base64 data-URI of the image, downscaled to ≤768px wide (GD when
     * available) so the vision call stays cheap; the original bytes go out
     * unscaled when GD can't read the format.
     */
    private function encodeDownscaled(string $imageAbsPath): ?string
    {
        $bytes = null;
        if (function_exists('imagecreatefromstring')) {
            try {
                $src = @imagecreatefromstring((string) file_get_contents($imageAbsPath));
                if ($src !== false) {
                    $w = imagesx($src);
                    $h = imagesy($src);
                    if ($w > 768) {
                        $scaled = imagescale($src, 768, (int) round($h * 768 / $w));
                        if ($scaled !== false) {
                            imagedestroy($src);
                            $src = $scaled;
                        }
                    }
                    ob_start();
                    imagejpeg($src, null, 82);
                    $bytes = ob_get_clean();
                    imagedestroy($src);
                }
            } catch (\Throwable) {
                $bytes = null;
            }
        }
        if ($bytes) {
            return 'data:image/jpeg;base64,' . base64_encode($bytes);
        }

        // GD unavailable/unreadable format: ship the original bytes with
        // their real mime type.
        $bytes = @file_get_contents($imageAbsPath);
        if (!$bytes) {
            return null;
        }
        $info = @getimagesize($imageAbsPath);
        $mime = is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
