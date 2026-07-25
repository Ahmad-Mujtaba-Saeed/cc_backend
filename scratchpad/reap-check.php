<?php

/**
 * Stale-project reaper guard test.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/reap-check.php
 *
 * The whole risk of this command is a FALSE reap — killing a render that is
 * merely slow. Everything below runs inside a transaction that is always
 * rolled back, so it never touches real project rows.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Project;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

DB::beginTransaction();

try {
    $owner = DB::table('users')->value('id');
    if ($owner === null) {
        echo "no users in this database — cannot build fixtures\n";
        DB::rollBack();
        exit(1);
    }

    $make = function (string $status, int $idleMinutes) use ($owner): Project {
        $p = Project::create([
            'user_id' => $owner,
            'title' => 'reap-check fixture',
            'template_type' => 'ai_explainer_video',
            'status' => $status,
        ]);
        // Bypass the model's timestamp handling so updated_at really is old.
        DB::table('projects')->where('id', $p->id)
            ->update(['updated_at' => now()->subMinutes($idleMinutes)]);

        return $p->refresh();
    };

    $statusOf = fn (Project $p) => DB::table('projects')->where('id', $p->id)->value('status');

    echo "\n== fixtures\n";
    $staleRender = $make('processing', 600);   // 10h — dead
    $staleAnalyze = $make('analyzing', 600);   // 10h — dead
    $slowRender = $make('processing', 90);     // 1.5h — slow but legitimate
    $freshRender = $make('processing', 1);     // just started
    $doneProject = $make('completed', 5000);   // old but not in a live status
    $draftProject = $make('draft', 5000);      // old but not in a live status
    echo "  built 6 fixtures\n";

    echo "\n== dry-run changes nothing\n";
    Artisan::call('projects:reap-stale', ['--minutes' => 240, '--dry-run' => true]);
    check('dry-run leaves the stale render alone', $statusOf($staleRender) === 'processing');
    check('dry-run leaves the stale analysis alone', $statusOf($staleAnalyze) === 'analyzing');

    echo "\n== real run at the 240 min default\n";
    Artisan::call('projects:reap-stale', ['--minutes' => 240]);

    check('stale processing reaped', $statusOf($staleRender) === 'failed', (string) $statusOf($staleRender));
    check('stale analyzing reaped', $statusOf($staleAnalyze) === 'failed', (string) $statusOf($staleAnalyze));
    check('SLOW render NOT reaped (1.5h < 4h)', $statusOf($slowRender) === 'processing', (string) $statusOf($slowRender));
    check('FRESH render NOT reaped', $statusOf($freshRender) === 'processing', (string) $statusOf($freshRender));
    check('completed project untouched', $statusOf($doneProject) === 'completed', (string) $statusOf($doneProject));
    check('draft project untouched', $statusOf($draftProject) === 'draft', (string) $statusOf($draftProject));

    $msg = DB::table('projects')->where('id', $staleRender->id)->value('error_message');
    check('reaped project explains itself', is_string($msg) && str_contains($msg, 'Interrupted'), (string) $msg);

    echo "\n== threshold is honoured\n";
    Artisan::call('projects:reap-stale', ['--minutes' => 60]);
    check('slow render reaped once the threshold drops below its idle time',
        $statusOf($slowRender) === 'failed', (string) $statusOf($slowRender));
    check('fresh render still safe at 60 min', $statusOf($freshRender) === 'processing', (string) $statusOf($freshRender));

    echo "\n== rerun is a no-op\n";
    $before = $statusOf($staleRender);
    Artisan::call('projects:reap-stale', ['--minutes' => 240]);
    check('already-failed projects are not re-reaped', $statusOf($staleRender) === $before);
} finally {
    DB::rollBack();
}

echo "\n== rollback\n";
check('no fixture survived the rollback',
    Project::where('title', 'reap-check fixture')->count() === 0);
check('real project 13 untouched by this test',
    in_array((string) DB::table('projects')->where('id', 13)->value('status'), ['processing', 'failed'], true));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
