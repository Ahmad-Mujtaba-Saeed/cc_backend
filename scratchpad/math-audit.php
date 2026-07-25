<?php

/**
 * math-audit — reads finished storyboards and reports the defects that are
 * cheap to detect but expensive to eyeball: radicals that never reached the
 * typesetter's notation, native cards sitting in uploadable slots, control-char
 * mojibake, arithmetic the steps get wrong, and routing surprises.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/math-audit.php 98 99 100
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Support\MathTruth;

$ids = array_slice($argv, 1);
if (!$ids) {
    fwrite(STDERR, "usage: math-audit.php <project id> [...]\n");
    exit(1);
}

$issues = 0;
$report = function (string $tag, string $msg) use (&$issues): void {
    $issues++;
    echo "    !! [{$tag}] {$msg}\n";
};

/** Walks every string leaf of a slot tree. */
function leaves(array $node, string $path = ''): array
{
    $out = [];
    foreach ($node as $k => $v) {
        $p = $path === '' ? (string) $k : "{$path}.{$k}";
        if (is_array($v)) {
            $out += leaves($v, $p);
        } elseif (is_string($v) && trim($v) !== '') {
            $out[$p] = $v;
        }
    }

    return $out;
}

foreach ($ids as $id) {
    $project = Project::find($id);
    if (!$project) {
        echo "#{$id} MISSING\n";
        continue;
    }
    $settings = $project->settings ?? [];
    $scenes = ExplainerScene::where('project_id', $project->id)->orderBy('order')->get();

    echo "\n=== #{$project->id}  {$project->title}\n";
    echo "    status={$project->status} mode=" . ($settings['composition_mode'] ?? 'slides')
        . " board=" . ($settings['board_style_auto'] ?? '-')
        . " tree=" . (!empty($settings['composed_by_tree']) ? 'yes' : 'no')
        . " attempts=" . ($settings['analysis_attempts'] ?? '?')
        . " scenes=" . $scenes->count() . "\n";
    echo "    why: " . ($settings['test_scenario'] ?? '-') . "\n";

    if ($project->status === 'failed') {
        $report('failed', (string) $project->error_message);
        continue;
    }
    if ($scenes->isEmpty()) {
        $report('empty', 'no scenes were produced');
        continue;
    }

    $templates = [];
    foreach ($scenes as $scene) {
        $templates[] = $scene->layout_template;
        foreach ((array) $scene->slots as $slotKey => $slot) {
            $type = $slot['content_type'] ?? '?';

            // (1) THE UPLOAD BUG: a math video must never ask for user media.
            if (in_array($type, ['image', 'video'], true)) {
                $report('upload-slot', "{$scene->scene_id} {$slotKey} is a {$type} slot on {$scene->layout_template}"
                    . " (asks the user to upload; auto_visuals="
                    . (!empty($settings['auto_visuals_auto']) ? 'on' : 'OFF') . ')');
            }

            foreach (leaves((array) $slot) as $path => $text) {
                // (2) THE RADICAL BUG: after canonicalisation the ONLY spelling
                // the typesetter can draw is sqrt{...}. Anything else renders
                // as the literal letters "sqrt".
                //
                // Arrow endpoints are exempt: they are truncated PREFIXES of a
                // step used to anchor an arrow ("(6 +- 2 sqrt"), never drawn.
                $isArrowAnchor = (bool) preg_match('/arrows\.\d+\.(from|to)$/', $path);
                if (!$isArrowAnchor && preg_match('/(?<![A-Za-z])sqrt\s*(?![\{])/i', $text)) {
                    $report('raw-sqrt', "{$scene->scene_id} {$slotKey}.{$path}: \"{$text}\"");
                }
                // (3) Transport mojibake — control chars on screen.
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                    $report('mojibake', "{$scene->scene_id} {$slotKey}.{$path}: " . bin2hex(substr($text, 0, 32)));
                }
                // (4) LaTeX habits that were supposed to be stripped. A single
                // '$' is money ("$80 after a 20% discount"), not a math
                // delimiter — it takes a PAIR to be a leak.
                if (str_contains($text, '\\') || substr_count($text, '$') >= 2) {
                    $report('latex-leak', "{$scene->scene_id} {$slotKey}.{$path}: \"{$text}\"");
                }
            }

            // (5) Arithmetic: every closed step must actually be true.
            if ($type === 'math_steps') {
                foreach ((array) ($slot['steps'] ?? []) as $i => $step) {
                    $expr = (string) ($step['expr'] ?? '');
                    if ($expr === '') {
                        continue;
                    }
                    // Only CLOSED arithmetic can be judged. "9^2 + 12^2 = c^2"
                    // is not false, it is an equation being solved — holds()
                    // samples it and says false, which is the wrong question.
                    if (MathTruth::unknowns($expr) === 0 && MathTruth::holds($expr) === false) {
                        $report('false-step', "{$scene->scene_id} step " . ($i + 1) . ": \"{$expr}\"");
                    }
                }
            }
        }
    }

    echo "    cards: " . implode(' → ', array_map(fn ($t) => str_replace('_card', '', (string) $t), $templates)) . "\n";

    $lint = $settings['lint_report']['items'] ?? [];
    foreach ($lint as $item) {
        $level = strtoupper((string) ($item['level'] ?? 'info'));
        echo "    lint {$level} " . ($item['code'] ?? '?') . ': ' . ($item['message'] ?? '') . "\n";
    }
}

echo "\n" . ($issues === 0 ? "clean — no issues found\n" : "{$issues} issue(s) found\n");
exit($issues === 0 ? 0 : 1);
