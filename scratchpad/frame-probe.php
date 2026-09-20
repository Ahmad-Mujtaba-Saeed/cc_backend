<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Key one raw drawing and report what the frame detector sees on it.
$spec = json_decode(file_get_contents(__DIR__ . '/flow-201.json'), true);
$el = null;
foreach ($spec as $card) {
    foreach ($card['slot']['elements'] as $e) {
        if (($e['title'] ?? '') === 'Bales') { $el = $e; }
    }
}
if (!$el) { exit("no bales part\n"); }
echo "stencil: {$el['image_path']}\n";

$png = Illuminate\Support\Facades\Storage::disk('public')->get($el['image_path']);
$im = imagecreatefromstring($png);
$w = imagesx($im); $h = imagesy($im);
$solid = fn ($x, $y) => ((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 96;
$row = function ($y) use ($solid, $w) { $n = 0; for ($x = 0; $x < $w; $x++) { $n += $solid($x, $y) ? 1 : 0; } return $n / $w; };
$col = function ($x) use ($solid, $h) { $n = 0; for ($y = 0; $y < $h; $y++) { $n += $solid($x, $y) ? 1 : 0; } return $n / $h; };
printf("stencil %dx%d\n", $w, $h);
for ($i = 0; $i < 12; $i++) {
    printf("  row %2d %.2f   row -%2d %.2f   col %2d %.2f   col -%2d %.2f\n",
        $i, $row($i), $i, $row($h - 1 - $i), $i, $col($i), $i, $col($w - 1 - $i));
}
