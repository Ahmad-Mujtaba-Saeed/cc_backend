<?php

namespace Modules\Project\Services;

use Modules\Project\Support\CinematicScene;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CinematicSceneService — the pass that STAGES a cinematic_card.
 *
 * The composer decides which beats carry the video's explanation and writes
 * one sentence saying what each must make the viewer understand (`brief`).
 * It does not stage them. This does, in a focused call per beat — the same
 * split as {@see VectorMotifService}: choosing which beat deserves the big
 * treatment and designing that treatment are different jobs, and a model
 * composing a nine-scene storyboard has no attention left for the second.
 *
 * The call runs AFTER the narration exists, which is what lets every part be
 * cued on a word the narrator actually says. What comes back is repaired by
 * {@see CinematicScene::sanitize()}; anything that fails twice leaves the slot
 * pending, and the validator degrades the beat to a text card — a beat never
 * renders empty because staging failed.
 */
class CinematicSceneService
{
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // Staging is composition, like the storyboard itself: the ordinary
        // explainer model.
        $this->model = LlmModels::for('explainer');
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Stage one beat.
     *
     * @return array<string, mixed>|null  a sanitised slot_cinematic
     */
    public function design(string $brief, string $narration, string $heading = '', string $topic = '', string $aspect = '16:9'): ?array
    {
        $brief = trim($brief);
        $narration = trim($narration);
        if (!$this->available() || ($brief === '' && $narration === '')) {
            return null;
        }

        $icons = ExplainerRegistry::iconNames();
        $lookup = array_flip($icons);
        $exists = static fn (string $name) => isset($lookup[$name]);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($icons, $aspect)],
            ['role' => 'user', 'content' => trim(
                'VIDEO: ' . mb_substr($topic, 0, 160) . "\n"
                . 'FRAME: ' . ($aspect === '9:16' ? 'vertical 9:16 (tall — prefer rows of 1-2 parts)' : 'wide 16:9') . "\n"
                . ($heading !== '' ? 'HEADING THE COMPOSER SUGGESTED: ' . mb_substr($heading, 0, 80) . "\n" : '')
                . 'WHAT THIS BEAT MUST MAKE CLEAR: ' . mb_substr($brief !== '' ? $brief : $narration, 0, 300) . "\n"
                . 'THE NARRATION (cue words must come from here): ' . mb_substr($narration, 0, 900)
            )],
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout(60)
                    ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                        'model' => $this->model,
                        'messages' => $messages,
                        'temperature' => 0.4,
                        'max_tokens' => 1800,
                        'response_format' => ['type' => 'json_object'],
                    ], 'low'));
            } catch (\Throwable $e) {
                Log::info('CinematicSceneService: request failed', ['error' => $e->getMessage()]);
                return null;
            }
            if (!$response->successful()) {
                Log::info('CinematicSceneService: request failed', ['status' => $response->status()]);
                return null;
            }
            CostTracker::recordChat($this->model, $response->json('usage'), 'cinematic_scene');

            $raw = (string) $response->json('choices.0.message.content');
            $candidate = json_decode($raw, true);
            if (!is_array($candidate)) {
                Log::info('CinematicSceneService: response was not JSON');
                return null;
            }
            if (($candidate['heading'] ?? '') === '' && $heading !== '') {
                $candidate['heading'] = $heading;
            }
            $candidate['brief'] = $brief;

            $result = CinematicScene::sanitize($candidate, $exists, $narration);
            $parts = count($result['slot']['elements'] ?? []);
            $weak = $result['ok'] ? self::weaknesses($result['slot']) : [];
            if ($result['ok'] && $parts >= 3 && $weak === []) {
                return $result['slot'];
            }

            $why = $weak !== []
                ? implode('; ', $weak)
                : ($result['warnings'] === []
                    ? "it had only {$parts} usable parts"
                    : implode('; ', array_slice($result['warnings'], 0, 4)));
            $messages[] = ['role' => 'assistant', 'content' => $raw];
            $messages[] = ['role' => 'user', 'content' =>
                "That staging was rejected: {$why}. Stage it again with 3 to 5 parts, each with a kind from the "
                . 'list, a place from the grid, a depth, a camera, and a cue word copied exactly from the narration. '
                . 'Return ONLY the JSON.',
            ];

            // A staging with two good parts is still better than a text card.
            if ($attempt === 1 && $result['ok']) {
                return $result['slot'];
            }
        }

        Log::info('CinematicSceneService: staging never survived sanitizing', ['brief' => $brief]);

        return null;
    }

    /**
     * Stage every cinematic_card in a raw storyboard that is still waiting for
     * its parts. Also takes revision drafts (`['scenes' => key => draft]`):
     * keys are preserved. Never throws; a beat whose staging fails is left
     * pending for the validator to degrade.
     */
    public function designAll(array $parsed, string $topic = '', string $aspect = '16:9'): array
    {
        $scenes = (array) ($parsed['scenes'] ?? []);
        foreach ($scenes as $si => $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'cinematic_card') {
                continue;
            }
            $slot = is_array($scene['slots']['slot_cinematic'] ?? null) ? $scene['slots']['slot_cinematic'] : [];
            $elements = $slot['elements'] ?? null;
            if (is_array($elements) && count($elements) >= CinematicScene::MIN_ELEMENTS) {
                continue; // the composer staged it itself; the validator repairs it
            }

            // A storyboard scene carries narration as {text}; a revision draft
            // carries it as a plain string.
            $narration = is_array($scene['narration'] ?? null)
                ? (string) ($scene['narration']['text'] ?? '')
                : (string) ($scene['narration'] ?? '');
            try {
                $staged = $this->design(
                    (string) ($slot['brief'] ?? ''),
                    $narration,
                    (string) ($slot['heading'] ?? ''),
                    $topic,
                    $aspect
                );
            } catch (\Throwable $e) {
                Log::info('CinematicSceneService: staging unavailable', ['error' => $e->getMessage()]);
                $staged = null;
            }
            if ($staged !== null) {
                $scenes[$si]['slots']['slot_cinematic'] = $staged;
            }
        }
        $parsed['scenes'] = $scenes;

        return $parsed;
    }

    /**
     * What makes a VALID staging still a poor one — worth one corrective
     * retry, never a rejection (a second weak staging still beats a text
     * card). The first live run staged both test videos as three plain text
     * labels in a single row: a slide with a camera on it.
     *
     * @return string[]
     */
    public static function weaknesses(array $slot): array
    {
        $elements = (array) ($slot['elements'] ?? []);
        $faults = [];
        if ($elements !== [] && array_unique(array_column($elements, 'kind')) === ['text']) {
            $faults[] = 'every part is plain text — make at least one an icon, a stat, a formula or an html drawing';
        }
        $rows = [];
        foreach ($elements as $el) {
            $place = (string) ($el['place'] ?? 'center');
            $rows[str_starts_with($place, 'top') ? 0 : (str_starts_with($place, 'bottom') ? 2 : 1)] = true;
        }
        if (count($elements) >= 3 && count($rows) < 2) {
            $faults[] = 'every part sits in one row — spread them over at least two rows';
        }

        return $faults;
    }

    /** @param string[] $icons */
    private function systemPrompt(array $icons, string $aspect): string
    {
        $iconList = implode(', ', $icons);
        $perRow = $aspect === '9:16' ? 2 : 3;

        return <<<PROMPT
You stage ONE key explanation beat of an explainer video as a cinematic card. The renderer puts every part on its own 3D layer: a part arrives out of depth and out of focus when the narrator says its cue word, the camera pushes in on the part being explained while the others fall soft, and the card ends on a wide, sharp shot of all the parts together. You choose the PARTS, where each sits, how deep, when it lands and how the camera treats it. The renderer computes every position, camera move and focus distance — never give coordinates.

Return ONLY JSON:
{"heading": "<=60 chars: the idea in plain words", "elements": [3-5 parts], "css": "optional — rules for html parts only, class selectors only"}

A part:
{"id": "short-slug", "kind": "text|stat|formula|icon|html", "text": "...", "sub": "...", "formula": "...", "icon": "...", "html": "...", "place": "...", "depth": "near|mid|far", "word": "...", "camera": "push|angle|rack"}

KINDS — pick the one that SHOWS the part best:
- text: a short phrase <=40 chars naming one piece of the idea ("A harmless copy of the spike"). Optional sub <=60 for one detail.
- stat: a figure <=12 chars that the NARRATION states ("7%", "\$7,612", "3 billion"); sub says what it measures. Never invent a number.
- formula: an equation the narration uses, in linear notation (A = P*(1 + r)^t, frac{a}{b}, sqrt{x}, x^2); sub says what it gives.
- icon: one of these icon names EXACTLY: {$iconList}. text = its 1-3 word label, sub optional.
- html: a SMALL drawing of something the viewer must SEE — a cell with its parts, a row of bars, a ticket stub, a queue of boxes, a before/after pair. Author it for a box 600px wide with HTML + CSS (flex or grid for anything with words, inline <svg> only for shapes). Colour only with var(--accent), var(--text), var(--muted), var(--panel), var(--line); type with var(--font-display), var(--font-body), var(--font-mono). No images, scripts, links, animations, shadows or gradients. At most 2 html parts.

STAGING:
- 3 to 5 parts, listed IN THE ORDER THEY ARRIVE, which is the order the narration introduces them.
- word: ONE word copied exactly from the narration, said at the moment the part should appear. A different word for each part, in narration order, and none in the last sentence — the card needs time to show everything together before the beat ends.
- place: a 3x3 grid — top_left, top, top_right, left, center, right, bottom_left, bottom, bottom_right. Let the LAYOUT explain: cause on the left and effect on the right; the whole on top and its parts below; input, process in the centre, output. Parts in one row share its width, at most {$perRow} per row.
- depth: vary it. The thing the explanation is ABOUT goes near, supporting detail mid, background context far. Never all on one depth.
- camera: push = fly in close (the part the beat hinges on — use at least one); angle = close and oblique (use it once, for drama); rack = stay where the camera is and just shift focus (for a part sitting right next to the previous one). Mix them.
- Keep every text short: this is a picture being explained, not a slide of sentences. A label is 1-4 words; the detail goes in sub.
- Parts are the THINGS or STAGES the explanation is made of — nouns ("the microphone", "the inverted wave", "silence"). Never a verb or an adjective on its own ("Rises", "Falls", "Critical"); that belongs in a sub line.
- SHOW, do not just label: at least one part must be an icon, a stat, a formula or an html drawing. A staging that is nothing but text parts is a slide, and it will be rejected.
- Use at least TWO rows, so the camera has somewhere to travel.

EXAMPLE. Narration: "Solar panels are made of silicon cells. When sunlight hits a cell, it knocks electrons loose, and the metal lines on the cell collect them as a current. An inverter then turns that into the kind of power your home uses."
{"heading": "How a solar panel makes power", "elements": [
 {"id": "sun", "kind": "icon", "icon": "sun", "text": "Sunlight", "place": "top_left", "depth": "far", "word": "sunlight", "camera": "push"},
 {"id": "cell", "kind": "html", "html": "<div class=\"cell\"><div class=\"grid\"><i></i><i></i><i></i><i></i><i></i><i></i></div><p>silicon cell</p></div>", "place": "top", "depth": "near", "word": "cell", "camera": "angle"},
 {"id": "electrons", "kind": "text", "text": "Electrons knocked loose", "sub": "collected by the metal lines", "place": "top_right", "depth": "mid", "word": "electrons", "camera": "rack"},
 {"id": "inverter", "kind": "icon", "icon": "plug", "text": "Inverter", "sub": "turns it into household power", "place": "bottom", "depth": "mid", "word": "inverter", "camera": "push"}],
 "css": ".cell{border:2px solid var(--accent);padding:14px;text-align:center} .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px} .grid i{display:block;height:60px;border:1px solid var(--line);background:var(--panel)} .cell p{font-family:var(--font-mono);color:var(--muted);margin:10px 0 0}"}
PROMPT;
    }
}
