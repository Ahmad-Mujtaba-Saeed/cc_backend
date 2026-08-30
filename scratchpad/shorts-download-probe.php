<?php
// Does allow_extended_duration change what the provider delivers for a long video?
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Modules\AccessControl\Models\ApiCredential;

$url = $argv[1] ?? 'https://www.youtube.com/watch?v=DcAHosNxz9E';
$ext = $argv[2] ?? '1';

$key = ApiCredential::forProvider('rapidapi')->first();
$host = config('services.rapidapi.download_host', 'youtube-info-download-api.p.rapidapi.com');
echo "host={$host}  allow_extended_duration={$ext}\n";

$r = Http::withHeaders(['x-rapidapi-host' => $host, 'x-rapidapi-key' => $key->credential])
    ->get("https://{$host}/ajax/download.php", [
        'format' => '480', 'url' => $url, 'audio_quality' => '128',
        'no_merge' => 'false', 'allow_extended_duration' => $ext,
    ]);
echo "init: " . $r->status() . " " . substr($r->body(), 0, 400) . "\n";
$progress = $r->json('progress_url');
if (!$progress) { exit(1); }

for ($i = 0; $i < 40; $i++) {
    sleep(6);
    $p = Http::withHeaders(['x-rapidapi-host' => $host, 'x-rapidapi-key' => $key->credential])->get($progress);
    $d = $p->json();
    echo "poll {$i}: progress=" . ($d['progress'] ?? '?') . " url=" . (empty($d['download_url']) ? 'no' : 'YES') . "\n";
    if (!empty($d['download_url'])) {
        // HEAD the file to see what is on offer without pulling it all down
        $h = Http::withOptions(['stream' => true])->get($d['download_url']);
        echo "  status=" . $h->status()
            . "  content-length=" . ($h->header('Content-Length') ?: '(none)')
            . "  transfer-encoding=" . ($h->header('Transfer-Encoding') ?: '(none)')
            . "  content-type=" . $h->header('Content-Type') . "\n";
        echo "  url=" . $d['download_url'] . "\n";
        break;
    }
}
