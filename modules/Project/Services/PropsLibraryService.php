<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PropsLibraryService
 *
 * A SHARED library of small AI-generated decorative props ("glowing compass
 * rose", "paper plane", ...). Props are deliberately generic, so each unique
 * prompt is generated ONCE — keyed by a hash of the normalised prompt — and
 * reused across every project forever. Generation cost trends to zero as the
 * library grows.
 *
 * Props are generated as a single isolated object on a PURE BLACK background;
 * the renderer draws them with `mix-blend-mode: screen`, which makes black
 * transparent on the (always dark) canvas themes — cheap cut-outs without
 * needing alpha from the image model.
 */
class PropsLibraryService
{
    private const DIR = 'props';

    /**
     * Resolve library paths for the given prompts, generating any that are
     * missing. Best-effort: prompts that fail to generate map to null.
     *
     * @param  string[]  $prompts
     * @return array<string, string|null> prompt => disk-relative path or null
     */
    public function resolve(array $prompts, ?int $projectId = null): array
    {
        $result = [];
        $missing = [];

        foreach (array_unique(array_filter(array_map('trim', $prompts))) as $prompt) {
            $rel = self::DIR . '/' . $this->keyFor($prompt) . '.png';
            if (Storage::disk('public')->exists($rel)) {
                $result[$prompt] = $rel;
            } else {
                $result[$prompt] = null;
                $missing[] = $prompt;
            }
        }

        if (empty($missing)) {
            return $result;
        }

        try {
            $fullPrompts = array_map(
                fn ($p) => "{$p}, single isolated object, centered, floating on a pure solid black background, "
                    . 'no text, no watermark, soft internal glow, high detail render',
                $missing
            );

            $images = (new ImageGenerationService())->generateImages(
                $fullPrompts,
                count($fullPrompts),
                'digital_art',
                [
                    'project_id' => $projectId,
                    'template' => 'explainer_prop',
                    'aspect_ratio' => '1:1',
                    'prompt_modifiers' => 'black background, isolated subject, minimal, icon-like',
                    // Each prop is an unrelated object: no characters, and one
                    // seed per prop so a batch doesn't converge on one shape.
                    'character_consistency' => false,
                    'vary_seed' => true,
                ]
            );

            foreach ($missing as $i => $prompt) {
                $absolute = $images[$i] ?? null;
                if (!$absolute || !is_file($absolute)) {
                    continue;
                }
                $rel = self::DIR . '/' . $this->keyFor($prompt) . '.png';
                Storage::disk('public')->put($rel, file_get_contents($absolute));
                $result[$prompt] = $rel;
            }
        } catch (\Throwable $e) {
            // Decorative only — the render simply proceeds without the props.
            Log::warning('PropsLibraryService: generation failed (non-fatal): ' . $e->getMessage());
        }

        return $result;
    }

    private function keyFor(string $prompt): string
    {
        return md5(mb_strtolower(trim(preg_replace('/\s+/', ' ', $prompt))));
    }
}
