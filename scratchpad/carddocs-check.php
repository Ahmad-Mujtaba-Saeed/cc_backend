<?php

/**
 * carddocs-check — every slot name the generic composer TELLS the model to
 * emit must actually exist on that template in the registry.
 *
 * This guards the class of bug that produced project 42's "Background visual
 * for this scene" images: CARD_DOCS advertised `slot_media` for the full-bleed
 * templates, whose real slot is `slot_background`, so the model's good
 * description landed on an illegal slot, got dropped, and the missing real
 * slot was back-filled with the validator's generic placeholder — which then
 * became the image prompt verbatim.
 *
 *   docker compose exec app php scratchpad/carddocs-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\ExplainerRegistry;

$pass = 0;
$fail = 0;

$ref = new ReflectionClass(GenericStoryboardComposerService::class);
$docs = $ref->getConstant('CARD_DOCS');
$templates = ExplainerRegistry::templates();

foreach ($docs as $template => $doc) {
    $real = array_keys($templates[$template]['slots'] ?? []);
    if (empty($real)) {
        $fail++;
        echo "FAIL  {$template}: not in the registry at all\n";
        continue;
    }

    // Every slot_* token the doc line mentions.
    preg_match_all('/\bslot_[a-z0-9_]+/i', (string) $doc, $m);
    $mentioned = array_values(array_unique($m[0]));

    $bogus = array_diff($mentioned, $real);
    if (empty($bogus)) {
        $pass++;
        echo "  ok  {$template}: " . implode(', ', $mentioned) . "\n";
    } else {
        $fail++;
        echo "FAIL  {$template}: doc says [" . implode(', ', $bogus)
            . '] but the template has [' . implode(', ', $real) . "]\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
