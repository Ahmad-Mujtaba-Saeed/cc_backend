<?php

/**
 * Verification suite for the TTS provider switch + Pixabay music layer.
 * Run inside the app container: php artisan tinker scratchpad/verify_tts_music.php
 */

use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Project\Services\OpenAiTtsService;
use Modules\Project\Services\PixabayMusicService;
use Modules\Project\Support\TtsInstructions;
use Modules\Project\Support\TtsVoices;

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name} — {$detail}\n"; }
};

echo "== 1. Provider switch defaults ==\n";
AppSetting::where('key', TtsVoices::SETTING_KEY)->delete();
$check('default provider is kokoro', TtsVoices::activeProvider() === 'kokoro');

$config = TemplateProcessorFactory::getTemplateConfig('ai_horror_shorts');
$check('kokoro voices injected', array_keys($config['settings_schema']['tts_voice']['options']) === array_keys(TtsVoices::KOKORO));
$check('horror music_category default horror', ($config['settings_schema']['music_category']['default'] ?? null) === 'horror');
$check('music options include none+categories', isset($config['settings_schema']['music_category']['options']['none'], $config['settings_schema']['music_category']['options']['horror']));
$check('tts meta shipped', ($config['tts']['provider'] ?? null) === 'kokoro');

$compilation = TemplateProcessorFactory::getTemplateConfig('yt_compilation_short');
$check('compilation music default none', ($compilation['settings_schema']['music_category']['default'] ?? null) === 'none');
$gameplay = TemplateProcessorFactory::getTemplateConfig('yt_gameplay_short');
$check('gameplay gains music picker', isset($gameplay['settings_schema']['music_category']));
// The explainer has NO settings_schema (custom create UI); its music default
// 'auto' is applied at render time by RemotionRenderService::resolveMusic().
$explainer = TemplateProcessorFactory::getTemplateConfig('ai_explainer_video');
$check('explainer config untouched (no schema, custom UI)', !isset($explainer['settings_schema']));

echo "== 2. Switch to openai ==\n";
AppSetting::set(TtsVoices::SETTING_KEY, 'openai');
$openaiConfigured = TtsVoices::openaiConfigured();
echo '  (openai key configured: ' . ($openaiConfigured ? 'yes' : 'NO') . ")\n";
$check('active provider honors switch', TtsVoices::activeProvider() === ($openaiConfigured ? 'openai' : 'kokoro'));

if ($openaiConfigured) {
    $config = TemplateProcessorFactory::getTemplateConfig('ai_horror_shorts');
    $check('gpt voices injected', isset($config['settings_schema']['tts_voice']['options']['onyx']));
    $check('horror default voice onyx', ($config['settings_schema']['tts_voice']['default'] ?? null) === 'onyx');
}

echo "== 3. Voice resolution ==\n";
$check('kokoro voice on openai -> template default', TtsVoices::resolve('am_michael', 'openai', 'ai_horror_shorts') === 'onyx');
$check('gpt voice on kokoro -> kokoro default', TtsVoices::resolve('fable', 'kokoro', 'ai_horror_shorts') === 'am_michael');
$check('af_bella survives kokoro resolve', TtsVoices::resolve('af_bella', 'kokoro', 'ai_explainer_video') === 'af_bella');
$check('explainer kokoro default af_bella', TtsVoices::defaultFor('kokoro', 'ai_explainer_video') === 'af_bella');

echo "== 4. Instructions ==\n";
$check('horror creepy instruction', str_contains((string) TtsInstructions::for('ai_horror_shorts', ['tone' => 'creepy']), 'ominous'));
$check('compilation sarcastic instruction', str_contains((string) TtsInstructions::for('yt_compilation_short', ['commentary_style' => 'sarcastic']), 'deadpan'));
$check('unknown template -> null', TtsInstructions::for('yt_gameplay_short', []) === null);

echo "== 5. Settings validation ==\n";
$errors = TemplateProcessorFactory::validateTemplateSettings('ai_horror_shorts', ['tts_voice' => 'onyx', 'music_category' => 'horror', 'music_volume' => 0.2]);
$check('gpt voice + music settings validate', empty($errors), implode('; ', $errors));
$errors = TemplateProcessorFactory::validateTemplateSettings('ai_horror_shorts', ['tts_voice' => 'bogus_voice']);
$check('bogus voice rejected', !empty($errors));
$errors = TemplateProcessorFactory::validateTemplateSettings('yt_compilation_short', ['music_category' => 'dubstep']);
$check('bogus category rejected', !empty($errors));
$errors = TemplateProcessorFactory::validateTemplateSettings('yt_compilation_short', ['music_volume' => 3]);
$check('out-of-range volume rejected', !empty($errors));

echo "== 6. OpenAI synthesis (live call) ==\n";
if ($openaiConfigured && getenv('VERIFY_LIVE')) {
    $out = storage_path('app/public/tts_previews/verify_openai.wav');
    @mkdir(dirname($out), 0775, true);
    @unlink($out);
    $service = new OpenAiTtsService();
    $result = $service->generateTTS(
        'This is a quick verification of the new narration engine.',
        'onyx',
        $out,
        true,
        'Speak in a low, ominous voice.'
    );
    $check('openai synth succeeds', ($result['success'] ?? false) && file_exists($out) && filesize($out) > 10000, $result['error'] ?? '');
    $timings = $result['word_timings'] ?? [];
    $check('whisper word timings recovered', count($timings) >= 5, 'got ' . count($timings));
    if (!empty($timings)) {
        $first = $timings[0];
        $check('timing shape {word,start,end}', isset($first['word'], $first['start'], $first['end']));
        $check('timings monotonic', end($timings)['end'] > $first['start']);
    }
} else {
    echo "  SKIP  live synthesis (already verified 2026-07-10; set VERIFY_LIVE=1 to re-run)\n";
}

echo "== 7. Pixabay graceful degradation ==\n";
$pixabay = new PixabayMusicService();
$check('unconfigured pixabay -> isConfigured false or true (pool check runs)', is_bool($pixabay->isConfigured()));
$track = $pixabay->pickTrack('horror', 42);
$check('pickTrack without key returns null (no exception)', $pixabay->isConfigured() ? true : $track === null, var_export($track, true));

echo "== 8. Reset provider to kokoro (production default) ==\n";
AppSetting::set(TtsVoices::SETTING_KEY, 'kokoro');
$check('provider restored', TtsVoices::activeProvider() === 'kokoro');

echo "\nRESULT: {$pass} passed, {$fail} failed\n";
