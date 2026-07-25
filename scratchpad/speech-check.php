<?php

/**
 * Iter 25 — SpeechDictionary (what the narrator SAYS).
 *
 * Run in-container:  docker compose exec -T app php scratchpad/speech-check.php
 *
 * Two things under test: the rewrites fire where they should, and — the half
 * that matters more — they stay OUT of the places a naive substitution would
 * wreck (a bare "m" that is an algebra variable, a "vs" inside a word).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\SpeechDictionary;

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

$say = fn (string $t, array $h = []): string => SpeechDictionary::forSpeech($t, $h);

echo "\n== units are spoken, not spelled\n";
check('km/h', $say('The train runs at 90 km/h all day.') === 'The train runs at 90 kilometres per hour all day.',
    $say('The train runs at 90 km/h all day.'));
check('m/s^2', str_contains($say('gravity is 9.8 m/s^2 near the surface'), '9.8 metres per second squared'));
check('plain km', str_contains($say('it is 12 km away'), '12 kilometres away'));
check('percent', str_contains($say('growth hit 8% last year'), '8 percent last year'));
check('celsius', str_contains($say('water boils at 100 °C'), '100 degrees celsius'));
check('gigabytes', str_contains($say('the file is 4 GB'), '4 gigabytes'));
check('kilowatt hours', str_contains($say('it draws 3 kWh'), '3 kilowatt hours'));

echo "\n== abbreviations\n";
check('e.g.', str_contains($say('Use a cache, e.g. Redis.'), 'for example, Redis'), $say('Use a cache, e.g. Redis.'));
check('i.e.', str_contains($say('the median, i.e. the middle value'), 'that is, the middle value'));
check('vs', str_contains($say('speed vs accuracy'), 'speed versus accuracy'));
check('etc.', str_contains($say('bikes, buses, etc.'), 'et cetera'));
check('Dr.', str_contains($say('Dr. Chen ran the study'), 'Doctor Chen'));
check('ampersand', str_contains($say('research & development'), 'research and development'));

echo "\n== ...and the traps it must NOT fall into\n";
check('a bare algebraic m is left alone', $say('solve for m in the equation') === 'solve for m in the equation',
    $say('solve for m in the equation'));
check('"s" as a plural is not seconds', $say('the values s and t are given') === 'the values s and t are given');
check('a unit needs a NUMBER in front', $say('the km marker') === 'the km marker', $say('the km marker'));
check('"vs" inside a word survives', str_contains($say('the revs climbed'), 'revs climbed'));
check('a decade keeps its shape', str_contains($say('the 1980s were loud'), '1980s'));
check('an ordinary sentence is untouched',
    $say('This is a perfectly ordinary sentence about nothing at all.')
    === 'This is a perfectly ordinary sentence about nothing at all.');

echo "\n== project hints: the model proposes, the guard disposes\n";
$hints = [
    ['term' => 'Nguyen', 'say' => 'nwin'],
    ['term' => 'Xiaomi', 'say' => 'shao-mee'],
];
check('a hint is applied', str_contains($say('Nguyen founded it', $hints), 'nwin founded it'));
check('a hint is case-insensitive', str_contains($say('NGUYEN founded it', $hints), 'nwin founded it'));
check('a hint does not fire inside a longer word',
    str_contains($say('Nguyenesque styling', $hints), 'Nguyenesque'), $say('Nguyenesque styling', $hints));

$bad = [
    ['term' => 'a', 'say' => 'ay'],                                   // too short to be safe
    ['term' => 'GDP', 'say' => ''],                                    // nothing to say
    ['term' => 'SQL', 'say' => 'SQL'],                                 // a no-op
    ['term' => 'API', 'say' => 'the application programming interface of the thing'], // runaway
    ['term' => 'JSON', 'say' => 'jay|son <b>'],                        // not speakable
];
check('a one-character term is refused', !isset(SpeechDictionary::usableHints($bad)['a']));
check('an empty pronunciation is refused', !isset(SpeechDictionary::usableHints($bad)['GDP']));
check('a no-op hint is refused', !isset(SpeechDictionary::usableHints($bad)['SQL']));
check('a runaway pronunciation is refused', !isset(SpeechDictionary::usableHints($bad)['API']));
check('markup can never reach the voice', !isset(SpeechDictionary::usableHints($bad)['JSON']));
check('all five junk hints are dropped', SpeechDictionary::usableHints($bad) === []);

$many = [];
for ($i = 0; $i < 30; $i++) {
    $many[] = ['term' => "Term{$i}", 'say' => "say {$i}"];
}
check('hints are capped at 12', count(SpeechDictionary::usableHints($many)) === 12,
    (string) count(SpeechDictionary::usableHints($many)));

echo "\n== the written word is never touched\n";
check('the dictionary is applied to SPEECH only (processor wiring)',
    str_contains(
        file_get_contents(__DIR__ . '/../modules/Project/Processors/ExplainerVideoProcessor.php'),
        'SpeechDictionary::forSpeech($spoken, $speechHints)'
    ));
check('the job stores only guarded hints',
    str_contains(
        file_get_contents(__DIR__ . '/../modules/Project/Jobs/AnalyzeExplainerScriptJob.php'),
        'SpeechDictionary::usableHints'
    ));
check('the analyzer asks for pronunciations',
    str_contains(
        file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php'),
        '"pronunciations"'
    ));

echo "\n== it composes with MathSpeech (maths videos run both)\n";
$mathThenSpeech = SpeechDictionary::forSpeech(
    Modules\Project\Support\MathSpeech::forSpeech('the drop is 4.9 m/s^2 and x^2 grows fast')
);
check('notation AND units both land',
    str_contains($mathThenSpeech, 'squared') && !str_contains($mathThenSpeech, 'm/s'),
    $mathThenSpeech);

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
