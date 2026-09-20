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
        // The ordinary explainer model (gpt-4o-mini) — the user's rule is mini
        // everywhere; drawing quality is bought with the prompt, not a model.
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
                        'max_tokens' => 4500,
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
                "That staging was rejected: {$why}. Design it again: 3 to 6 parts, at least three of them kind "
                . '"visual" with a "prompt" naming the thing to draw, each with a place, a depth, a camera and a cue '
                . 'word copied exactly from the narration; links between them; and at least one "then" that changes a '
                . 'part while the narrator talks. Return ONLY the JSON.',
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
     * card). The user's rule for this card: the parts are DRAWN for this
     * script at run time — so the gate asks for pictures rather than labels,
     * for a picture's state to change while the narrator talks, and for the
     * pieces to be connected.
     *
     * @return string[]
     */
    public static function weaknesses(array $slot): array
    {
        $elements = (array) ($slot['elements'] ?? []);
        $links = (array) ($slot['links'] ?? []);
        $faults = [];
        $drawn = array_filter($elements, fn ($el) => in_array((string) ($el['kind'] ?? ''), CinematicScene::DRAWN, true));
        if ($elements !== [] && count($drawn) < min(3, count($elements))) {
            $faults[] = 'too little is DRAWN — at least three parts must be kind "visual" with a "prompt" naming the thing itself, not text labels or numbers';
        }
        $changes = array_filter($elements, fn ($el) => !empty($el['then']));
        if (count($elements) >= 3 && $changes === []) {
            $faults[] = 'nothing changes while the narrator talks — give a part a "then" that swaps its status/tone on a later cue word';
        }
        if (count($elements) >= 3 && $links === []) {
            $faults[] = 'nothing is connected — add links showing how the pieces relate (what flows from which to which)';
        }
        // The same THING drawn over and over is the failure mode of a drawn
        // diagram: six parts that each say "a human heart ..." come back as six
        // identical hearts and the beat explains nothing. Exact repeats are
        // deliberate (three of one server), so only DIFFERENT wordings of the
        // same object count.
        $subjects = [];
        foreach ($elements as $el) {
            if (($el['kind'] ?? '') === 'visual' && trim((string) ($el['prompt'] ?? '')) !== '') {
                $subjects[mb_strtolower(trim((string) $el['prompt']))] = true;
            }
        }
        $nouns = [];
        foreach (array_keys($subjects) as $subject) {
            foreach (array_unique(self::nouns($subject)) as $noun) {
                $nouns[$noun] = ($nouns[$noun] ?? 0) + 1;
            }
        }
        arsort($nouns);
        $repeated = array_key_first($nouns);
        if ($repeated !== null && $nouns[$repeated] >= 3) {
            $faults[] = sprintf(
                'three or more parts draw the same object ("%s") — they will come back as identical pictures. Draw each '
                . 'piece as the familiar thing it works like (a pump, a one-way valve, a tank, a length of pipe), '
                . 'recognisable on its own with no label, or cast pieces that really are different things',
                $repeated
            );
        }

        $rows = [];
        foreach ($elements as $el) {
            $place = (string) ($el['place'] ?? 'center');
            $rows[str_starts_with($place, 'top') ? 0 : (str_starts_with($place, 'bottom') ? 2 : 1)] = true;
        }
        if (count($elements) >= 4 && count($rows) < 2) {
            $faults[] = 'every part sits in one row — put a follow-on step or a detail in another row';
        }

        return $faults;
    }

    /**
     * The words in a subject that name a thing — long enough to be a noun,
     * minus the vocabulary every subject shares (views, sizes, styles).
     *
     * @return string[]
     */
    private static function nouns(string $subject): array
    {
        $skip = [
            'with', 'and', 'the', 'from', 'that', 'into', 'over', 'under', 'near', 'onto', 'seen', 'view', 'front',
            'side', 'back', 'above', 'below', 'simple', 'small', 'large', 'tall', 'wide', 'flat', 'plain', 'showing',
            'shown', 'single', 'whole', 'part', 'parts', 'drawing', 'icon', 'diagram', 'illustration', 'picture',
            'human', 'several', 'three', 'four', 'five', 'together', 'standing', 'holding', 'inside', 'outside',
            'representing', 'depicting', 'showing', 'style', 'shaped', 'each', 'one', 'two', 'opening', 'openings',
        ];

        return array_values(array_filter(
            preg_split('/[^a-z]+/', mb_strtolower($subject)) ?: [],
            fn ($w) => mb_strlen($w) >= 4 && !in_array($w, $skip, true)
        ));
    }

    /** @param string[] $icons */
    private function systemPrompt(array $icons, string $aspect): string
    {
        $perRow = $aspect === '9:16' ? 2 : 3;
        $iconList = implode(', ', array_slice($icons, 0, 60));

        return <<<PROMPT
You design ONE key explanation beat of an explainer video as a DIAGRAM THAT BUILDS ITSELF while the narrator talks.

Every piece of the idea is a PICTURE OF THE THING ITSELF, drawn for this script: the server, the valve, the parcel, the heart chamber, the bank vault, the antenna. You do not draw it and you do not write markup — you NAME what must be drawn, in one sentence, and an image model draws it as flat black line art. The renderer keys that drawing out and paints it in the video's own ink, then typesets your words under it, so every part looks like a component built for this deck.

The renderer stages your parts in 3D: each part flies in out of focus when the narrator says its cue word, the camera pushes in on the part being explained while the rest blurs, the links you declare are drawn between the parts with packets flowing along them, and the card ends on a wide, sharp view of the whole diagram. It lays everything out and moves the camera — you never give screen positions, sizes or colours.

Return ONLY JSON:
{"heading": "<=60 chars: the idea in plain words", "elements": [3-6 parts], "links": [connections]}

THE MAIN KIND — a drawn part
{"id": "slug", "kind": "visual", "prompt": "<what to draw>", "title": "<=20 what it is", "status": "<=12 its state now", "note": "<=40 what it is doing", "tone": "accent|bad|muted", "place": "...", "depth": "near|mid|far", "word": "...", "camera": "push|angle|rack", "then": [{"word": "<later cue>", "status": "<=12", "tone": "accent|bad|muted", "note": "<=40"}]}
- "prompt" names ONE concrete object, plainly, in <=180 chars: "a tall server rack cabinet with stacked slots", "a kitchen tap with a curved spout", "a shipping container with doors", "a human heart with four chambers", "a coiled spring". Describe the OBJECT and its parts — never a style, a colour, a background or a mood, and never a scene with several different things in it.
- The image model CANNOT write. Never ask for text, labels, numbers, arrows, charts or diagrams in the picture. All words come from "title", "status" and "note", which the renderer typesets.
- Keep it simple enough to read at a glance: a few big shapes, not a busy illustration. A crowd is "three simple standing person figures side by side", not a hundred. Never name a mass of small things (flakes, grains, sparks, crowds of dots) — it comes back as a texture and is thrown away; name ONE of them, or the container it is in.
- Say it so a stranger could draw it. A word with another common meaning gets the other meaning: "a tank" is drawn as an army tank, "a mouse" as the animal, "a crane" as the bird. Add what it is made of or what it holds — "a large open water tank on legs", "a computer mouse with two buttons".
- Two parts that ARE the same kind of thing (three servers, two pipes) must repeat the SAME "prompt" word for word — identical text gets the identical drawing, which is what makes them read as three of one component.
- Otherwise every part must be a DIFFERENT object, and every one must be RECOGNISABLE ON ITS OWN, with no label: a person would name it at a glance. "a heart showing the left atrium" and "a heart showing the right atrium" come back as two identical hearts, and "a hollow muscular chamber with one opening" comes back as a meaningless blob — both explain nothing.
- So when the pieces are parts of one thing, draw the piece AS THE FAMILIAR THING IT WORKS LIKE: a pump, a one-way flap valve, a bellows, a length of pipe, a tank, a pair of lungs, a filter. The title tells the viewer which part it is; the drawing tells them what it DOES.
- "tone": accent = the good/active one, bad = the broken/blocked one, muted = the idle one. Leave it out for neutral.
- "then" is how the diagram CHANGES: on a later word the narrator says, the status, the tone and the note swap in place (90% turning into 34%, MISS into SET, LOCKED into OPEN). At least one part must change.

FOR A PIECE THAT IS ONLY WORDS
{"kind": "stat", "text": "<=12 the number", "sub": "what it measures"} · {"kind": "formula", "formula": "an equation the narration actually uses", "sub": "..."} · {"kind": "icon", "icon": "<one of: {$iconList}>", "text": "<=28", "sub": "..."} · {"kind": "text", "text": "<=90 one short line"}
Use these only when the piece truly is a number, an equation or a plain idea — most parts should be drawn.

STAGING
- 3 to 6 parts, listed IN THE ORDER THEY ARRIVE = the order the narration introduces them. Each part's "word" is ONE word copied exactly from the narration, all different, in order, none from the last sentence (the finished diagram needs time on screen).
- place (3x3 grid: top_left, top, top_right, left, center, right, bottom_left, bottom, bottom_right), laid out like a diagram: a flow runs left -> right; one thing fanning out to several = the one in the centre and the several stacked down the right; a follow-on step goes below the part it follows. At most {$perRow} parts per row.
- depth: the part the beat is ABOUT near, supporting parts mid, context far.
- camera: push = fly in close (the part the beat hinges on); angle = close and oblique (use once); rack = stay and shift focus to a neighbour.
- links: [{"from": "<id>", "to": "<id>", "label"?: "<=20 what travels", "tone"?: "accent|muted|bad", "flow"?: true when something moves along it}] — connect every part to at least one other.

TWO EXAMPLES of the quality bar. They are NOT templates — your subject has its own things to draw.

EXAMPLE 1. Narration: "Everyone hits the same server, and it is drowning at ninety percent. So you put a load balancer in front. It picks one of three servers for every request, and the load spreads out evenly."
{"heading": "What a load balancer does",
 "elements": [
  {"id": "crowd", "kind": "visual", "prompt": "a group of three simple standing person figures side by side", "title": "Everyone", "note": "every visitor", "place": "left", "depth": "far", "word": "everyone", "camera": "push"},
  {"id": "s1", "kind": "visual", "prompt": "a tall server rack cabinet with stacked slots", "title": "Server 1", "status": "90%", "tone": "bad", "place": "top_right", "depth": "mid", "word": "drowning", "camera": "push", "then": [{"word": "evenly", "status": "34%", "tone": "accent"}]},
  {"id": "lb", "kind": "visual", "prompt": "a network router box with three short antennas on top", "title": "Balancer", "note": "picks one", "place": "center", "depth": "near", "word": "balancer", "camera": "angle"},
  {"id": "s2", "kind": "visual", "prompt": "a tall server rack cabinet with stacked slots", "title": "Server 2", "status": "5%", "tone": "muted", "place": "right", "depth": "mid", "word": "three", "camera": "rack", "then": [{"word": "evenly", "status": "33%", "tone": "accent"}]},
  {"id": "s3", "kind": "visual", "prompt": "a tall server rack cabinet with stacked slots", "title": "Server 3", "status": "5%", "tone": "muted", "place": "bottom_right", "depth": "mid", "word": "servers", "camera": "rack", "then": [{"word": "evenly", "status": "33%", "tone": "accent"}]}],
 "links": [{"from": "crowd", "to": "lb", "flow": true}, {"from": "lb", "to": "s1", "flow": true}, {"from": "lb", "to": "s2", "flow": true}, {"from": "lb", "to": "s3", "flow": true}]}

EXAMPLE 2. Narration: "The boat glides into the lock, and the lower gate closes behind it. Water pours in from the upper river, and the level in the chamber rises until it matches the river ahead. The upper gate opens and the boat sails on, twenty feet higher than it started."
{"heading": "How a canal lock lifts a boat",
 "elements": [
  {"id": "boat", "kind": "visual", "prompt": "a small canal boat seen from the side, long flat hull with a cabin", "title": "The boat", "note": "waiting below", "place": "left", "depth": "mid", "word": "boat", "camera": "push"},
  {"id": "gate", "kind": "visual", "prompt": "a pair of tall wooden lock gates seen from the front", "title": "Lower gate", "status": "OPEN", "tone": "accent", "place": "center", "depth": "near", "word": "gate", "camera": "angle", "then": [{"word": "closes", "status": "SHUT", "tone": "bad"}]},
  {"id": "chamber", "kind": "visual", "prompt": "a deep rectangular water tank with stone walls, seen from the side", "title": "Chamber", "status": "LOW", "tone": "muted", "place": "right", "depth": "mid", "word": "chamber", "camera": "push", "then": [{"word": "rises", "status": "LEVEL", "tone": "accent", "note": "matches the river ahead"}]},
  {"id": "lift", "kind": "stat", "text": "20 ft", "sub": "higher than it started", "place": "bottom_right", "depth": "far", "word": "higher", "camera": "rack"}],
 "links": [{"from": "boat", "to": "gate", "flow": true}, {"from": "gate", "to": "chamber", "label": "water in", "flow": true}, {"from": "chamber", "to": "lift", "tone": "muted"}]}
PROMPT;
    }
}
