<?php

/**
 * media-brief-live — does the PLANNER actually write the media brief?
 *
 *   docker compose exec app php scratchpad/media-brief-live.php
 *
 * MediaBrief guarantees every media slot has a search_query, a media_kind and
 * a guidance sentence — but a derived query is a fallback, not the goal. The
 * model knows the beat; it should be naming the shot. This runs the REAL
 * composer on one short script and reports how much of the brief came from
 * the model rather than from the deterministic backstop.
 *
 * One skeleton call plus one composer call — a couple of cents.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\MediaBrief;

$script = <<<'TXT'
In 1994 a former hedge fund analyst noticed something nobody else seemed to care about: the number of people using the internet was growing by two thousand percent a year.
He quit his job, drove across the country, and started selling books out of a garage in Seattle.
Books were the trick. They were small, they didn't spoil, and there were more titles in print than any physical shop could ever stock, so a catalogue on a screen genuinely beat a shelf.
Within thirty days the company was shipping to all fifty states and forty five countries, still packing every order on a kitchen table.
Then came the part everyone forgets. For the first six years the company lost money on purpose, ploughing every dollar back into warehouses and software while Wall Street called it a bubble.
The bubble burst in 2000 and the share price fell by more than ninety percent. Most of its online rivals disappeared that year.
The difference was that the warehouses were already built. When the market recovered, the company had infrastructure nobody could match, and it rented the spare computing capacity out to everyone else.
That side business is now called AWS, and it earns more profit than the entire retail operation it was built to support.
TXT;

echo "Planning the skeleton…\n";
$skeleton = (new ScriptSkeletonService())->planGeneric($script, '', 75);
if ($skeleton === []) {
    echo "no skeleton — the composer path is not exercised; stopping.\n";
    exit(1);
}
echo '  ' . count($skeleton['phases'] ?? $skeleton) . " phases\n";

echo "Composing…\n";
$raw = (new GenericStoryboardComposerService())->compose($script, $skeleton, 75);
if ($raw === null) {
    echo "composer returned null (it would fall back to the giant call); stopping.\n";
    exit(1);
}

$scenes = $raw['scenes'] ?? [];
echo count($scenes) . " scenes\n\n";

$media = 0;
$authored = ['search_query' => 0, 'guidance' => 0, 'media_kind' => 0];
$kinds = [];

foreach ($scenes as $scene) {
    foreach (($scene['slots'] ?? []) as $slotKey => $slot) {
        if (!in_array($slot['content_type'] ?? '', ['image', 'video'], true)) {
            continue;
        }
        $media++;
        $request = is_array($slot['asset_request'] ?? null) ? $slot['asset_request'] : [];

        foreach (array_keys($authored) as $field) {
            if (trim((string) ($request[$field] ?? '')) !== '') {
                $authored[$field]++;
            }
        }

        // What the slot ends up with after the guarantee pass.
        $brief = MediaBrief::build($request, (string) $slot['content_type'], '16:9');
        $kinds[$brief['media_kind']] = ($kinds[$brief['media_kind']] ?? 0) + 1;

        printf(
            "%-12s %-16s %s\n  query : %-30s %s\n  advice: %s\n\n",
            $scene['scene_id'] ?? '?',
            $slotKey,
            mb_substr((string) ($request['description'] ?? ''), 0, 70),
            $brief['search_query'],
            trim((string) ($request['search_query'] ?? '')) !== '' ? '(model)' : '(derived)',
            mb_substr($brief['guidance'], 0, 150)
        );
    }
}

if ($media === 0) {
    echo "no media slots in this storyboard — nothing to report.\n";
    exit(1);
}

echo "----------------------------------------------------\n";
printf("%d media slots\n", $media);
foreach ($authored as $field => $count) {
    printf("  %-13s written by the model: %d/%d (%d%%)\n", $field, $count, $media, round(100 * $count / $media));
}
printf("  media_kind mix: %s\n", json_encode($kinds));
