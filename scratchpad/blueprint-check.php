<?php

/**
 * blueprint-check — registry, skin resolution, font-pack mapping and payload
 * exposure for the blueprint skin (loop iter 26). The skin carries a FIXED
 * navy palette (renderer theme.tsx BLUEPRINT_THEME), so the one server-side
 * guarantee that matters is that everything agrees on WHEN it is active:
 * resolveSkin is the single shared rule and the payload exposes its answer.
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/blueprint-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\RemotionRenderService;
use Modules\Project\Support\ExplainerRegistry;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

// 1. Registry: blueprint is the fourth skin, flagged as the ONLY one that
//    replaces the colour scheme; flat stays the default.
$skins = ExplainerRegistry::skins();
check('skinNames = flat,outline,print,blueprint', ExplainerRegistry::skinNames() === ['flat', 'outline', 'print', 'blueprint']);
check('default skin still flat', ExplainerRegistry::defaultSkin() === 'flat');
check('every skin has label + use_when + overrides_theme', collect($skins)->every(
    fn ($s) => is_string($s['label'] ?? null) && is_string($s['use_when'] ?? null) && is_bool($s['overrides_theme'] ?? null)
));
check('blueprint is the only overrides_theme skin', array_keys(array_filter($skins, fn ($s) => $s['overrides_theme'] ?? false)) === ['blueprint']);

// 2. resolveSkin (now public static — the payload and the render share it):
//    explicit beats auto, junk falls through, empty corner lands on flat.
check('explicit blueprint beats auto print', RemotionRenderService::resolveSkin(['skin' => 'blueprint', 'skin_auto' => 'print']) === 'blueprint');
check("stored 'auto' falls to suggestion", RemotionRenderService::resolveSkin(['skin' => 'auto', 'skin_auto' => 'blueprint']) === 'blueprint');
check('junk explicit → suggestion', RemotionRenderService::resolveSkin(['skin' => 'chrome', 'skin_auto' => 'outline']) === 'outline');
check('nothing stored → flat', RemotionRenderService::resolveSkin([]) === 'flat');

// 3. Font pack: blueprint's labels are mono BY DESIGN, so auto maps to the
//    tech pack — but an explicit pack always wins, and the print→classic
//    mapping is untouched.
$svc = app(RemotionRenderService::class);
$packOf = function (array $settings) use ($svc): string {
    $m = new ReflectionMethod($svc, 'resolveFontPack');
    $m->setAccessible(true);

    return $m->invoke($svc, $settings);
};
check('blueprint + auto pack → tech', $packOf(['skin' => 'blueprint']) === 'tech');
check('blueprint + explicit classic stays classic', $packOf(['skin' => 'blueprint', 'font_pack' => 'classic']) === 'classic');
check('blueprint beats font_pack_auto', $packOf(['skin' => 'blueprint', 'font_pack_auto' => 'editorial']) === 'tech');
check('print still maps to classic', $packOf(['skin' => 'print']) === 'classic');
check('flat leaves font_pack_auto in charge', $packOf(['skin' => 'flat', 'font_pack_auto' => 'editorial']) === 'editorial');
check('auto skin resolving to blueprint also maps to tech', $packOf(['skin' => 'auto', 'skin_auto' => 'blueprint']) === 'tech');

// 4. The storyboard payload exposes skin_resolved (the UI dims the colour
//    scheme from it — it must be the same rule, not a re-implementation).
$src = file_get_contents(__DIR__ . '/../modules/Project/Http/Controllers/ExplainerController.php');
check("payload carries skin_resolved via RemotionRenderService::resolveSkin", str_contains($src, "'skin_resolved' => \Modules\Project\Services\RemotionRenderService::resolveSkin("));

// 5. The auto-theme suggestion may legitimately pick blueprint: the suggest
//    whitelist is skinNames(), so the new name is pickable without a prompt
//    change (guarantees-over-prompts: the whitelist IS the guarantee).
check('suggest whitelist contains blueprint', in_array('blueprint', ExplainerRegistry::skinNames(), true));

// 6. Registry bumped for the new option.
check('registry version >= 32', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 32);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
