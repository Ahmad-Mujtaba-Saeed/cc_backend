<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$m = new ReflectionMethod(Modules\Project\Services\SlotImageBriefService::class, 'clean');
$m->setAccessible(true);
$cases = [
  'A clear plastic bottle resting inside a blue recycling bin, surrounded by other recyclable materials, with a bright sunlight illuminating the scene',
  'A close-up of grey pellets scattered on a surface, resembling rice, in natural light',
  'A fleece jacket hanging on a rack, made from recycled plastic, with a tag visible indicating its material',
  'A visual representation of interconnected lines and nodes linking several points',
  'An animated flow of coins moving between two banks',
  'A bank card held against a card terminal on a shop counter, a shopper hand steady above it',
];
foreach ($cases as $c) {
  printf("in : %s\nout: %s\n\n", $c, $m->invoke(null, $c));
}
