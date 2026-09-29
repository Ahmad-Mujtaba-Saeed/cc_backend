<?php
// Where does a request's time go? Boots the app like public/index.php and times each phase.
//   docker compose exec app php scratchpad/boot-timing.php
$t0 = microtime(true);
require __DIR__ . '/../vendor/autoload.php';
$t1 = microtime(true);
$app = require __DIR__ . '/../bootstrap/app.php';
$t2 = microtime(true);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/billing/plans', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$response = $kernel->handle($request);
$t3 = microtime(true);
printf("autoload %.2fs | bootstrap %.2fs | handle %.2fs | status %d\n", $t1 - $t0, $t2 - $t1, $t3 - $t2, $response->getStatusCode());
$t4 = microtime(true);
try { Illuminate\Support\Facades\DB::select('select 1'); } catch (Throwable $e) { echo 'db error ', $e->getMessage(), "\n"; }
printf("db ping %.2fs\n", microtime(true) - $t4);
$t5 = microtime(true);
try { Illuminate\Support\Facades\Cache::get('probe'); } catch (Throwable $e) { echo 'cache error ', $e->getMessage(), "\n"; }
printf("cache get %.2fs (driver %s)\n", microtime(true) - $t5, config('cache.default'));
$t6 = microtime(true);
try { Illuminate\Support\Facades\Redis::connection()->ping(); } catch (Throwable $e) { echo 'redis error ', $e->getMessage(), "\n"; }
printf("redis ping %.2fs\n", microtime(true) - $t6);
printf("session driver %s, log channel %s, db host %s\n", config('session.driver'), config('logging.default'), config('database.connections.mysql.host'));
