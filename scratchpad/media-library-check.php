<?php

/**
 * media-library-check — the free media library and the per-slot brief.
 *
 *   docker compose exec app php scratchpad/media-library-check.php
 *   docker compose exec app php scratchpad/media-library-check.php --live
 *
 * Without --live it is offline and deterministic (query derivation, guidance
 * text, provider table, the validator wiring). With --live it also hits the
 * keyless providers for real, which is the only way to know the endpoints and
 * response shapes still hold.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\MediaLibraryService;
use Modules\Project\Support\MediaBrief;
use Modules\Project\Support\ShotListValidator;

$live = in_array('--live', $argv, true);
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

echo "\n== 1. search_query: prompt language becomes searchable words ==\n";

// Every description below is a real one from the pipeline's own prompts or
// from a shipped project. Prompt-ese is the input; a stock search box is the
// consumer.
$cases = [
    'a busy trading floor with traders shouting at screens' => ['busy', 'trading'],
    'a lone figure silhouetted against a vast desert landscape at sunset' => ['figure', 'desert'],
    'a clean flat vector illustration of solar panels in a desert' => ['solar', 'panels'],
    'the Vreato dashboard with a YouTube URL pasted into the input field' => ['vreato', 'dashboard'],
];
foreach ($cases as $description => $expected) {
    $query = MediaBrief::deriveQuery($description);
    $words = explode(' ', $query);
    $ok = count($words) <= 4 && $query !== '';
    foreach ($expected as $word) {
        $ok = $ok && str_contains($query, $word);
    }
    check('query from "' . mb_substr($description, 0, 34) . '…"', $ok, $query);
}

// Filler-only descriptions must still yield something editable, never ''.
check(
    'filler-only description still yields a query',
    MediaBrief::deriveQuery('a clean simple modern background image') !== '',
    MediaBrief::deriveQuery('a clean simple modern background image')
);

// Prompt filler the model wraps around the subject is dropped, not searched.
$q = MediaBrief::cleanQuery('a wide cinematic shot of a busy newsroom');
check('cleanQuery strips "a wide shot of"', !str_contains($q, 'wide') && str_contains($q, 'newsroom'), $q);

// Quoted glyphs never search — they are stripped from the image prompt for
// the same reason (see imagebrief-check).
$q = MediaBrief::deriveQuery("a large number '500 Million' with search icons");
check('quoted glyphs dropped from the query', !str_contains($q, '500'), $q);

echo "\n== 2. media_kind: stills vs motion ==\n";

$still = MediaBrief::build(['description' => 'a wooden desk with an open notebook'], 'image');
check('a static subject stays an image', $still['media_kind'] === 'image', $still['media_kind']);

$moving = MediaBrief::build(['description' => 'water pouring into a glass'], 'image');
check('a moving subject offers footage too', $moving['media_kind'] === 'either', $moving['media_kind']);

$clip = MediaBrief::build(['description' => 'a quiet library'], 'video');
check('a video slot is always video', $clip['media_kind'] === 'video', $clip['media_kind']);

// The planner's own choice wins over the heuristic.
$explicit = MediaBrief::build(
    ['description' => 'a wooden desk', 'media_kind' => 'video'],
    'image'
);
check('planner media_kind is honoured', $explicit['media_kind'] === 'video', $explicit['media_kind']);

// ...but not an illegal one.
$junk = MediaBrief::build(['description' => 'a wooden desk', 'media_kind' => 'hologram'], 'image');
check('an illegal media_kind is repaired', $junk['media_kind'] === 'image', $junk['media_kind']);

echo "\n== 3. guidance: the sentence the user reads ==\n";

$brief = MediaBrief::build(['description' => 'a busy newsroom'], 'image', '16:9');
check('guidance mentions the subject', str_contains($brief['guidance'], 'busy newsroom'));
check('guidance gives framing advice', str_contains($brief['guidance'], 'Landscape'));
check('guidance says what to avoid', str_contains($brief['guidance'], 'Avoid'));

$portrait = MediaBrief::build(['description' => 'a busy newsroom'], 'image', '9:16');
check('9:16 asks for a vertical-safe shot', str_contains($portrait['guidance'], 'crop tall'));

// Anything a stock library cannot have should say so instead of sending the
// user hunting. This is the honest answer, and it points at the AI button.
$drawn = MediaBrief::build(['description' => 'a screenshot of the settings dashboard'], 'image');
check('an unstockable subject recommends AI', str_contains($drawn['guidance'], 'Generate with AI'), mb_substr($drawn['guidance'], -60));

// A guidance sentence is shown verbatim in the UI: it may never be cut
// mid-word by the length clamp.
$long = MediaBrief::build([
    'description' => str_repeat('an extraordinarily detailed and rather long description of a subject ', 8),
], 'image');
check('long guidance is clamped on a word boundary', str_ends_with($long['guidance'], '.') && mb_strlen($long['guidance']) <= 321, mb_strlen($long['guidance']) . ' chars');

// The planner's own sentence is kept as written.
$authored = MediaBrief::build([
    'description' => 'a busy newsroom',
    'guidance' => 'A real newsroom with people at desks — not a posed handshake.',
], 'image');
check('planner guidance is kept verbatim', str_starts_with($authored['guidance'], 'A real newsroom'), $authored['guidance']);

echo "\n== 4. the validator gives EVERY media slot a brief ==\n";

// A shot list the way a model actually returns one: no brief fields at all.
$validator = new ShotListValidator();
$result = $validator->validate([
    'aspect_ratio' => '9:16',
    'scenes' => [
        [
            'scene_id' => 'scene_1',
            'duration_seconds' => 6,
            'narration' => ['text' => 'Every morning, the floor opens with a roar.'],
            'layout_template' => 'split_side_by_side',
            'slots' => [
                'slot_left' => [
                    'content_type' => 'image',
                    'asset_request' => ['description' => 'a busy trading floor at the opening bell'],
                ],
                'slot_right' => [
                    'content_type' => 'text_block',
                    'heading' => 'The open',
                    'bullets' => ['9:30am sharp'],
                ],
            ],
        ],
    ],
]);

$slot = $result['scenes'][0]['slots']['slot_left'] ?? [];
$request = $slot['asset_request'] ?? [];
check('validator adds search_query', ($request['search_query'] ?? '') !== '', $request['search_query'] ?? '(none)');
check('validator adds guidance', ($request['guidance'] ?? '') !== '');
check('validator adds media_kind', in_array($request['media_kind'] ?? '', ['image', 'video', 'either'], true), $request['media_kind'] ?? '(none)');
check(
    'the AI image prompt is untouched',
    ($request['description'] ?? '') === 'a busy trading floor at the opening bell',
    $request['description'] ?? '(none)'
);
check('9:16 brief followed the project aspect', str_contains((string) ($request['guidance'] ?? ''), 'crop tall'));

// The user's own art direction (iter 50) must survive a re-validation.
$again = (new ShotListValidator())->validate([
    'aspect_ratio' => '16:9',
    'scenes' => [[
        'scene_id' => 'scene_1',
        'duration_seconds' => 6,
        'narration' => ['text' => 'One line.'],
        'layout_template' => 'single_focus',
        'slots' => ['slot_main' => [
            'content_type' => 'image',
            'asset_request' => [
                'description' => 'a busy newsroom',
                'instruction' => 'seen from above, at night',
                'search_query' => 'newsroom desks',
                'guidance' => 'A real newsroom, not a posed shot.',
            ],
        ]],
    ]],
]);
$request = $again['scenes'][0]['slots']['slot_main']['asset_request'] ?? [];
check('user instruction survives validation', ($request['instruction'] ?? '') === 'seen from above, at night');
check('planner search_query survives validation', ($request['search_query'] ?? '') === 'newsroom desks', $request['search_query'] ?? '');

echo "\n== 5. the provider table ==\n";

$library = new MediaLibraryService();
$providers = $library->providers();
check('five providers are declared', count($providers) === 5, implode(', ', array_column($providers, 'name')));

$keyless = array_values(array_filter($providers, fn ($p) => !$p['needs_key']));
check('two providers need no key', count($keyless) === 2, implode(', ', array_column($keyless, 'name')));
check('keyless providers are always configured', array_reduce($keyless, fn ($c, $p) => $c && $p['configured'], true));
check('the library is available without any admin key', $library->isAvailable());

$byName = array_column($providers, null, 'name');
check('video comes only from pexels/pixabay', array_reduce(
    $providers,
    fn ($c, $p) => $c && (!in_array('video', $p['kinds'], true) || in_array($p['name'], ['pexels', 'pixabay'], true)),
    true
));
check('CC providers demand attribution', $byName['openverse']['attribution_required'] && $byName['wikimedia']['attribution_required']);

// A video search must never route to an image-only provider.
$noVideo = $library->search('city skyline', 'video', 'landscape', ['openverse']);
check('an image-only provider returns nothing for video', $noVideo === []);

// An empty query is a no-op, not an exception.
check('empty query returns nothing', $library->search('   ', 'image') === []);

if (!$live) {
    echo "\n(skipping live provider calls — pass --live to hit the real APIs)\n";
} else {
    echo "\n== 6. LIVE: the keyless providers actually answer ==\n";

    // A provider can be unreachable from the machine running this — Wikimedia
    // Commons does not resolve on some networks at all. That is not a defect
    // in this code. What WOULD be a defect is taking longer than the timeout
    // budget to find out, because that time is a user watching a spinner. So a
    // dead provider must degrade FAST, and at least one keyless source must
    // answer or a no-key install has nothing to show.
    $keylessWorking = 0;
    foreach (['openverse', 'wikimedia'] as $provider) {
        $start = microtime(true);
        $hits = $library->search('solar panels', 'image', 'landscape', [$provider], 6);
        $elapsed = microtime(true) - $start;
        check("{$provider} answers or fails fast", $elapsed < 14, round($elapsed, 2) . 's, ' . count($hits) . ' hits');
        if ($hits === []) {
            echo "  --  {$provider} returned nothing (unreachable from this network?)\n";
            continue;
        }
        $keylessWorking++;
        $first = $hits[0];
        check("{$provider} hit has a downloadable url", str_starts_with((string) $first['download_url'], 'https://'), mb_substr($first['download_url'], 0, 60));
        check("{$provider} hit carries attribution", isset($first['credit']['source_url']));
        check("{$provider} hit declares its licence", trim((string) $first['license']) !== '', $first['license']);
    }
    check('at least one keyless provider works', $keylessWorking > 0, $keylessWorking . ' of 2');

    // Whatever is keyed up, a mixed search must interleave rather than dump
    // one provider's whole page before the next.
    $mixed = $library->search('city skyline', 'image', 'landscape', [], 12);
    $names = array_values(array_unique(array_column($mixed, 'provider')));
    check('mixed search returns results', count($mixed) > 0, count($mixed) . ' hits from ' . implode('+', $names));
    if (count($names) > 1) {
        check('results are interleaved, not concatenated', $mixed[0]['provider'] !== $mixed[1]['provider'], $mixed[0]['provider'] . ' then ' . $mixed[1]['provider']);
    }

    // The second identical search must be served from cache — this endpoint
    // is called on every panel open.
    $library->search('cached probe query', 'image', 'landscape', [], 6);
    $start = microtime(true);
    $library->search('cached probe query', 'image', 'landscape', [], 6);
    $elapsed = (microtime(true) - $start) * 1000;
    // The bar separates "served from cache" from "went back to the network",
    // which is seconds — not a millisecond-accurate budget for Redis over a
    // Docker bridge.
    check('a repeated search is cached', $elapsed < 700, round($elapsed) . 'ms');

    // A failed provider must NOT be cached: an earlier build froze the empty
    // list from one timeout for six hours, and the provider stayed "broken"
    // long after it had recovered.
    $probe = 'cache poison probe ' . random_int(1000, 9999);
    $library->search($probe, 'image', 'landscape', ['wikimedia'], 4);
    check(
        'a failed provider is not cached as empty',
        !Illuminate\Support\Facades\Cache::has('media:wikimedia:image:landscape:' . md5(mb_strtolower($probe)))
    );

    // ...but it IS benched for a few minutes, so the next search does not pay
    // its connect timeout all over again.
    $down = array_column($library->providers(), 'cooling_down', 'name');
    if ($down['wikimedia'] ?? false) {
        $start = microtime(true);
        $library->search('another probe ' . random_int(1000, 9999), 'image', 'landscape', ['wikimedia'], 4);
        $elapsed = (microtime(true) - $start) * 1000;
        check('a benched provider is skipped instantly', $elapsed < 100, round($elapsed) . 'ms');
    } else {
        echo "  --  wikimedia answered, so there is no cooldown to test
";
    }

    // The whole point of the pool: every provider at once, so a slow or dead
    // one costs its connect timeout instead of the sum of all of them.
    $start = microtime(true);
    $library->search('quiet forest path ' . random_int(1000, 9999), 'image', 'landscape', [], 12);
    $elapsed = microtime(true) - $start;
    check('an all-provider cold search stays under 10s', $elapsed < 10, round($elapsed, 2) . 's');
}

if ($live) {
    echo "\n== 7. LIVE END-TO-END: search a slot, then put a real file in it ==\n";

    // On a THROWAWAY project. An earlier version of this check ran against a
    // real project and the adopt deleted the picture that was already in the
    // slot, which is exactly what the endpoint is supposed to do — so the test
    // has to bring its own project.
    $user = \Modules\User\Models\User::query()->orderBy('id')->first();
    if ($user === null) {
        echo "  --  no user to own a probe project; skipping\n";
    } else {
        $project = \Modules\Project\Models\Project::create([
            'user_id' => $user->id,
            'title' => 'media-library-check probe',
            'template_type' => 'ai_explainer_video',
            'status' => 'draft',
            'aspect_ratio' => '16:9',
            'settings' => [],
        ]);
        \Modules\Project\Models\ExplainerScene::create([
            'project_id' => $project->id,
            'scene_id' => 'scene_1',
            'order' => 1,
            'duration_seconds' => 6,
            'narration' => 'A probe scene.',
            'layout_template' => 'split_side_by_side',
            'transition' => 'fade',
            'slots' => [
                'slot_left' => [
                    'content_type' => 'image',
                    'asset_request' => MediaBrief::build(
                        ['description' => 'a wind turbine on a green hill'],
                        'image',
                        '16:9'
                    ),
                ],
                'slot_right' => [
                    'content_type' => 'video',
                    'asset_request' => MediaBrief::build(
                        ['description' => 'wind turbines turning'],
                        'video',
                        '16:9'
                    ),
                ],
            ],
        ]);

        try {
            auth()->loginUsingId($user->id);
            $controller = new \Modules\Project\Http\Controllers\ExplainerController();

            foreach ([
                ['slot_left', 'image', 'wind turbine hill'],
                ['slot_right', 'video', 'wind turbines'],
            ] as [$slotKey, $wantKind, $term]) {
                $response = $controller->searchMedia(
                    \Illuminate\Http\Request::create('/x', 'GET', ['query' => $term, 'kind' => $wantKind]),
                    $project
                );
                $hits = json_decode($response->getContent(), true)['data']['results'] ?? [];
                check("searchMedia returns {$wantKind} results", $response->status() === 200 && $hits !== [], count($hits) . ' hits');
                if ($hits === []) {
                    continue;
                }

                $pick = $hits[0];
                $response = $controller->adoptMedia(
                    \Illuminate\Http\Request::create('/x', 'POST', [
                        'provider' => $pick['provider'],
                        'id' => $pick['id'],
                        'query' => $term,
                        'kind' => $pick['kind'],
                    ]),
                    $project,
                    'scene_1',
                    $slotKey
                );
                check("adoptMedia accepts a {$wantKind}", $response->status() === 200, 'HTTP ' . $response->status());

                $asset = \Modules\Project\Models\ExplainerAsset::where('project_id', $project->id)
                    ->where('slot_key', $slotKey)->first();
                if ($asset === null) {
                    check("a {$wantKind} asset row exists", false);
                    continue;
                }

                // The file has to be REAL. An earlier build handed Guzzle a
                // sink PATH, renamed the still-unflushed temp file into place
                // and reported success — the slot got a 0-byte file and the
                // render would have failed on it.
                $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($asset->path);
                $bytes = is_file($abs) ? filesize($abs) : 0;
                check("the {$wantKind} file is real, not an empty stub", $bytes > 10000, number_format($bytes) . ' bytes');

                if ($wantKind === 'image') {
                    $size = @getimagesize($abs);
                    check('the image decodes', $size !== false, $size ? "{$size[0]}x{$size[1]} {$size['mime']}" : 'undecodable');
                }

                // `library:` and not `stock:` — StoryboardDiff drops a stock
                // clip whose query changed, and a hand-picked file must not be
                // thrown away like that.
                check(
                    "the {$wantKind} is marked as hand-picked",
                    str_starts_with((string) $asset->original_name, 'library:'),
                    (string) $asset->original_name
                );
                $rank = new ReflectionMethod(\Modules\Project\Support\StoryboardDiff::class, 'rank');
                $rank->setAccessible(true);
                check(
                    "StoryboardDiff ranks it as the user's own",
                    $rank->invoke(null, [
                        'slot_key' => $slotKey,
                        'original_name' => (string) $asset->original_name,
                    ]) === 0
                );

                $slots = \Modules\Project\Models\ExplainerScene::where('project_id', $project->id)->first()->slots;
                check("attribution is stored on the {$wantKind} slot", ($slots[$slotKey]['media_credit']['provider'] ?? '') !== '', $slots[$slotKey]['media_credit']['provider_label'] ?? '(none)');
            }

            // A picture may not be dropped into a slot that wants footage.
            $response = $controller->adoptMedia(
                \Illuminate\Http\Request::create('/x', 'POST', [
                    'provider' => 'pexels', 'id' => 'pexels:photo:1', 'query' => 'x', 'kind' => 'image',
                ]),
                $project,
                'scene_1',
                'slot_right'
            );
            check('a still is refused for a video slot', $response->status() === 422, 'HTTP ' . $response->status());

            // An id that was not in the results is refused BEFORE anything is
            // downloaded — the endpoint never takes a URL from the client.
            $response = $controller->adoptMedia(
                \Illuminate\Http\Request::create('/x', 'POST', [
                    'provider' => 'pexels', 'id' => 'pexels:photo:000000', 'query' => 'wind turbine hill', 'kind' => 'image',
                ]),
                $project,
                'scene_1',
                'slot_left'
            );
            check('an unknown result id is refused', $response->status() === 409, 'HTTP ' . $response->status());
        } finally {
            // Take the probe project and every file it made back out again.
            foreach (\Modules\Project\Models\ExplainerAsset::where('project_id', $project->id)->get() as $asset) {
                if ($asset->path) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($asset->path);
                }
                $asset->delete();
            }
            \Modules\Project\Models\ExplainerScene::where('project_id', $project->id)->delete();
            \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory("projects/{$project->id}");
            $project->delete();
            echo "  --  probe project {$project->id} deleted\n";
        }
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
