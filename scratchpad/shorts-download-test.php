<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Modules\AccessControl\Models\ApiCredential;

$url  = $argv[1];
$ext  = $argv[2] ?? '1';
$fmt  = $argv[3] ?? '480';
$out  = "/var/www/storage/app/public/probe/dl_{$ext}_{$fmt}.mp4";

$key  = ApiCredential::forProvider('rapidapi')->first();
$host = config('services.rapidapi.download_host');

$r = Http::withHeaders(['x-rapidapi-host'=>$host,'x-rapidapi-key'=>$key->credential])
    ->get("https://{$host}/ajax/download.php", [
        'format'=>$fmt,'url'=>$url,'audio_quality'=>'128','no_merge'=>'false',
        'allow_extended_duration'=>$ext,
    ]);
$progress = $r->json('progress_url');
echo "init ok, streaming=" . (str_contains((string)$r->json('id'), 'stream') ? 'yes':'no') . "\n";

$dl = null;
for ($i=0; $i<40 && !$dl; $i++) {
    sleep(6);
    $d = Http::withHeaders(['x-rapidapi-host'=>$host,'x-rapidapi-key'=>$key->credential])->get($progress)->json();
    $dl = $d['download_url'] ?? null;
}
if (!$dl) { echo "no download url\n"; exit(1); }

$t0 = microtime(true);
$resp = Http::withOptions(['stream'=>true,'timeout'=>0,'read_timeout'=>120,'connect_timeout'=>30])->get($dl);
$h = fopen($out, 'wb');
$body = $resp->toPsrResponse()->getBody();
$n = 0;
while (!$body->eof()) { $c = $body->read(262144); if ($c==='') break; $n += fwrite($h, $c); }
fclose($h);
printf("downloaded %.1f MB in %.0fs -> %s\n", $n/1048576, microtime(true)-$t0, $out);
