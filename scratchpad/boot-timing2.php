<?php
// Per-provider boot timing (what makes every request slow?).
//   docker compose exec app php scratchpad/boot-timing2.php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$timings = [];
$app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, function () {});
$t = microtime(true);
foreach ([
    Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    Illuminate\Foundation\Bootstrap\RegisterProviders::class,
] as $step) {
    $s = microtime(true);
    $app->bootstrapWith([$step]);
    printf("%-60s %.2fs\n", class_basename($step), microtime(true) - $s);
}
// boot providers one by one
$ref = new ReflectionProperty($app, 'serviceProviders');
$ref->setAccessible(true);
$boot = new ReflectionMethod($app, 'bootProvider');
$boot->setAccessible(true);
foreach ($ref->getValue($app) as $provider) {
    $s = microtime(true);
    $boot->invoke($app, $provider);
    $d = microtime(true) - $s;
    if ($d > 0.3) printf("  boot %-58s %.2fs\n", get_class($provider), $d);
}
printf("total %.2fs\n", microtime(true) - $t);
