<?php

namespace Modules\Project\Services;

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\MediaBrief;
use Modules\Project\Support\LlmModels;
use Modules\Project\Support\VectorMotif;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VectorMotifService — the pass that DRAWS what the storyboard asked for.
 *
 * The composer decides that a beat wants a picture of a specific thing and
 * writes one sentence saying what ("a glass bottle melted down and re-formed
 * as a jar"). It does not draw it. This does, in a separate focused call, for
 * the same reason `MathVisualSynthesisService` exists: a 300-token answer to a
 * single question succeeds where rule #23 of a 200-line system prompt is
 * ignored — and a model composing a nine-scene storyboard has no attention
 * left for coordinate geometry.
 *
 * What makes this safe to hand to a model at all is that it is drawing inside
 * a coordinate system with guarantees. The view is a fixed 100x100 square, the
 * primitives are a closed set, the colours are semantic names, and
 * {@see VectorMotif::sanitize()} clamps every number afterwards. The renderer
 * then measures the drawing's real bounding box and fits it to the frame, so
 * scale and centring — the two things a model reliably gets wrong when it
 * cannot see its own output — are not its problem.
 *
 * Everything degrades to null. The caller then leaves the slot as a pending
 * request or turns it into an ordinary picture slot; a beat never renders
 * empty because a drawing failed.
 */
class VectorMotifService
{
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // A composition task, not a correctness-critical one: this rides the
        // ordinary explainer model rather than the math valve.
        $this->model = LlmModels::for('explainer');
    }

    public function available(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Draw one motif.
     *
     * @param  string $subject   What to draw, in the composer's own words.
     * @param  string $narration The beat's narration, for what to emphasise.
     * @param  string $topic     The video's title, for register and vocabulary.
     * @return array{shapes: array<int,array<string,mixed>>, caption: string}|null
     */
    public function draw(string $subject, string $narration = '', string $topic = ''): ?array
    {
        $subject = trim($subject);
        if (!$this->available() || $subject === '') {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => trim(
                "VIDEO: " . mb_substr($topic, 0, 160) . "\n"
                . "THIS BEAT SAYS: " . mb_substr($narration, 0, 500) . "\n"
                . "DRAW: " . mb_substr($subject, 0, 200)
            )],
        ];

        $icons = array_flip(ExplainerRegistry::iconNames());
        $exists = static fn (string $name) => isset($icons[$name]);

        // One corrective retry. The failure this catches is the model drawing
        // a single shape, or naming icons that are not in the library — both
        // recoverable by saying so, neither worth a second full attempt.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => $messages,
                    // Drawing is composition, not invention: a hot model
                    // scatters shapes. Low, but not zero — 0 produces the same
                    // four-rectangle diagram for every subject.
                    'temperature' => 0.35,
                    'max_tokens' => 1200,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));

            if (!$response->successful()) {
                Log::info('VectorMotifService: request failed', ['status' => $response->status()]);
                return null;
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'vector_motif');

            $raw = (string) $response->json('choices.0.message.content');
            $candidate = json_decode($raw, true);
            if (is_array($candidate)) {
                $result = VectorMotif::sanitize($candidate, $exists);
                if ($result['ok']) {
                    return ['shapes' => $result['shapes'], 'caption' => $result['caption']];
                }
                $why = $result['warnings'] === []
                    ? 'it had fewer than two shapes, or nothing but labels'
                    : implode('; ', array_slice($result['warnings'], 0, 4));
                $messages[] = ['role' => 'assistant', 'content' => $raw];
                $messages[] = ['role' => 'user', 'content' =>
                    "That drawing was rejected: {$why}. Draw the subject again with at least four "
                    . 'shapes that are not labels, using only the listed kinds, colours and icon '
                    . 'names, with every coordinate between 0 and 100. Return ONLY the JSON.',
                ];
                continue;
            }

            Log::info('VectorMotifService: response was not JSON');
            return null;
        }

        Log::info('VectorMotifService: motif never survived sanitising', ['subject' => $subject]);

        return null;
    }

    /**
     * Draw the NEXT beat as a change to the drawing already on screen.
     *
     * Two adjacent beats about the same thing should not cut between two
     * unrelated pictures — that is the difference between a slideshow and a
     * piece. Given the previous motif, the model is asked for the SAME drawing
     * changed: shapes that persist keep their ids and positions, shapes that
     * are done are dropped, and what the new beat adds arrives on its own cue.
     *
     * Shared ids are what the caller then uses to decide the two scenes deserve
     * a match cut, so keeping them is not cosmetic.
     *
     * Falls back to `draw()` on failure: an unrelated drawing is much better
     * than none, and the caller cannot tell the difference except that the cut
     * will not be a match.
     *
     * @param  array<int,array<string,mixed>> $previous The drawing on screen.
     * @return array{shapes: array<int,array<string,mixed>>, caption: string}|null
     */
    public function evolve(
        array $previous,
        string $subject,
        string $narration = '',
        string $topic = ''
    ): ?array {
        $subject = trim($subject);
        if (!$this->available() || $subject === '' || $previous === []) {
            return null;
        }

        // Only the authored fields go back to the model: sending the clamped
        // defaults for every shape triples the prompt and teaches it to write
        // them out, which wastes its output budget on things it need not say.
        $trimmed = array_map(static function (array $shape): array {
            $keep = array_intersect_key($shape, array_flip([
                'id', 'kind', 'cx', 'cy', 'r', 'x', 'y', 'w', 'h', 'round',
                'x1', 'y1', 'x2', 'y2', 'd', 'name', 'text', 'size', 'anchor',
                'stroke', 'fill',
            ]));

            return $keep;
        }, $previous);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => trim(
                "VIDEO: " . mb_substr($topic, 0, 160) . "\n\n"
                . "THE DRAWING ALREADY ON SCREEN:\n"
                . json_encode($trimmed, JSON_UNESCAPED_SLASHES) . "\n\n"
                . "THE NEXT BEAT SAYS: " . mb_substr($narration, 0, 500) . "\n"
                . "DRAW: " . mb_substr($subject, 0, 200) . "\n\n"
                . "This is the SAME picture, one beat later. Return the whole drawing again, but: "
                . "keep the id AND roughly the position of every shape that is still part of it, "
                . "drop the ones that are not, and add what this beat needs with its own \"at\" or "
                . "\"word\" cue. Move persisting shapes with \"then\" rather than teleporting them. "
                . "Return ONLY the JSON."
            )],
        ];

        $icons = array_flip(ExplainerRegistry::iconNames());
        $exists = static fn (string $name) => isset($icons[$name]);

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 1400,
                'response_format' => ['type' => 'json_object'],
            ], 'low'));

        if (!$response->successful()) {
            Log::info('VectorMotifService: evolve request failed', ['status' => $response->status()]);
            return null;
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'vector_motif_evolve');

        $candidate = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($candidate)) {
            return null;
        }

        $result = VectorMotif::sanitize($candidate, $exists);
        if (!$result['ok']) {
            Log::info('VectorMotifService: evolved motif did not survive sanitising');
            return null;
        }

        return ['shapes' => $result['shapes'], 'caption' => $result['caption']];
    }

    /**
     * Draw every `vector_motif` slot a storyboard asked for (iter 62).
     *
     * This lives here, and is called from the JOB, because the storyboard has
     * two producers — the tree composer and the giant-call fallback — and the
     * first version of this pass sat inside the fallback, where it never ran.
     * Four demo storyboards came back with a motif slot and no shapes before
     * that was noticed. Anything that must happen to EVERY storyboard belongs
     * where the two paths converge.
     *
     * A motif arrives as a `subject` and no shapes. This fills in the shapes,
     * up to the registry's per-video cap — each one is a focused LLM call, and
     * a video whose every beat is a little diagram is as monotonous as one
     * whose every beat is a bullet list.
     *
     * Failure is never fatal and never leaves a hole. A request past the cap,
     * or one the drawing pass could not satisfy, is turned into an ordinary
     * IMAGE slot carrying the same subject as its description and search
     * query, so the beat still gets a picture through the routes that already
     * exist — stock, AI or an upload. That degrade matters more than the
     * feature: the alternative is a slot that renders as empty space.
     */
    public function drawAll(array $parsed, string $topic = ''): array
    {
        $scenes = array_values((array) ($parsed['scenes'] ?? []));
        if ($scenes === []) {
            return $parsed;
        }

        // Find the pending requests first, so the cap applies to the video and
        // not to whichever scenes happen to come first in an inner loop.
        $pending = [];
        foreach ($scenes as $si => $scene) {
            foreach ((array) (is_array($scene) ? ($scene['slots'] ?? []) : []) as $key => $slot) {
                if (!is_array($slot) || ($slot['content_type'] ?? '') !== 'vector_motif') {
                    continue;
                }
                if (is_array($slot['shapes'] ?? null) && $slot['shapes'] !== []) {
                    continue;
                }
                if (trim((string) ($slot['subject'] ?? '')) === '') {
                    continue;
                }
                $pending[] = [$si, $key];
            }
        }

        if ($pending === []) {
            return $parsed;
        }

        $cap = ExplainerRegistry::maxVectorMotifs();
        $drawn = 0;
        // The drawing on screen in the PREVIOUS scene, if it was a motif. Two
        // adjacent beats about the same thing should not cut between two
        // unrelated pictures (iter 63) — the second is drawn as a change to
        // the first, which is what makes a match cut possible.
        $previous = null;
        $previousScene = null;

        foreach ($pending as [$si, $key]) {
            $slot = $scenes[$si]['slots'][$key];
            $subject = (string) $slot['subject'];
            $narration = (string) ($scenes[$si]['narration']['text'] ?? '');
            $result = null;

            if ($drawn < $cap) {
                try {
                    // Evolve only from the scene IMMEDIATELY before: a drawing
                    // two beats back is not "already on screen", and asking the
                    // model to continue a picture the viewer has forgotten
                    // produces a drawing that serves neither beat.
                    if ($previous !== null && $previousScene === $si - 1) {
                        $result = $this->evolve($previous, $subject, $narration, $topic);
                    }
                    $result ??= $this->draw($subject, $narration, $topic);
                } catch (\Throwable $e) {
                    Log::info('VectorMotifService: motif drawing unavailable', ['error' => $e->getMessage()]);
                    $result = null;
                }
            }

            if ($result !== null) {
                $slot['shapes'] = $result['shapes'];
                if ($result['caption'] !== '') {
                    $slot['caption'] = $result['caption'];
                }
                $scenes[$si]['slots'][$key] = $slot;
                $drawn++;

                // A MATCH CUT between two drawings that share shapes: the ids
                // that survived the evolution are the evidence that the second
                // picture continues the first, so the edit between them stops
                // being an ordinary cut and becomes a dissolve the eye reads as
                // one continuous object. Only ever set on a scene whose
                // predecessor really is the drawing it grew from.
                if ($previous !== null && $previousScene === $si - 1) {
                    $shared = array_intersect(
                        array_column($previous, 'id'),
                        array_column($result['shapes'], 'id')
                    );
                    if (count($shared) >= 2) {
                        $scenes[$si]['transition'] = 'match_dissolve';
                        Log::info('VectorMotifService: match cut between drawings', [
                            'scene' => $si,
                            'shared_shapes' => count($shared),
                        ]);
                    }
                }

                $previous = $result['shapes'];
                $previousScene = $si;
                continue;
            }

            $previous = null;
            $previousScene = null;

            // Degrade to a picture request rather than to nothing.
            $scenes[$si]['slots'][$key] = [
                'content_type' => 'image',
                'asset_request' => [
                    'description' => $subject,
                    'search_query' => MediaBrief::deriveQuery($subject),
                    'media_kind' => 'image',
                    'guidance' => 'A clear, simple shot of ' . rtrim($subject, '.') . '.',
                ],
            ];
            Log::info('VectorMotifService: motif request degraded to an image slot', [
                'scene' => $si,
                'reason' => $drawn >= $cap ? 'per-video cap' : 'drawing failed',
            ]);
        }

        $parsed['scenes'] = array_values($scenes);
        Log::info('VectorMotifService: motifs drawn', ['drawn' => $drawn, 'requested' => count($pending)]);

        return $parsed;
    }

    /**
     * The system prompt.
     *
     * Written as a drawing brief, not a schema dump: the spec is short enough
     * to state completely, and the part the model actually needs help with is
     * WHAT MAKES A DIAGRAM READ — big simple shapes, one idea, labels only
     * where a shape is ambiguous. The worked example is the bottle-to-jar
     * motif, which is in here because it is the one that was rendered and
     * eyeballed first: an example the author has seen on screen is worth more
     * than three the author has only imagined.
     */
    private function systemPrompt(): string
    {
        $icons = implode(', ', ExplainerRegistry::iconNames());

        return <<<PROMPT
You draw a small, flat, vector DIAGRAM of one thing, for one beat of an explainer video.
Return ONLY JSON: {"shapes":[...], "caption":"<=6 words, optional"}

THE SURFACE
A 100 x 100 square. Every coordinate is 0-100, y grows DOWNWARD. Do not think about the
video's shape or size: the renderer measures your drawing and fits it to the frame, so a
drawing that fills 40x40 in the corner comes out exactly as well as one that fills the view.

SHAPES (nothing else exists)
{"kind":"circle","cx":,"cy":,"r":}
{"kind":"rect","x":,"y":,"w":,"h":,"round":}          x,y is the TOP-LEFT corner
{"kind":"line","x1":,"y1":,"x2":,"y2":}
{"kind":"arrow","x1":,"y1":,"x2":,"y2":}              head is drawn at x2,y2
{"kind":"path","d":"M .. L .. C .. Z"}                 outlines: a bottle, a leaf, a roof
{"kind":"icon","name":"<from the library>","x":,"y":,"size":}   x,y is its CENTRE
{"kind":"label","text":"<=28 chars","x":,"y":,"size":,"anchor":"start|middle|end"}

EVERY shape also takes:
  "stroke": accent | ink | muted | paper | none      (outline colour)
  "fill":   accent | ink | muted | paper | none      (interior; labels use fill for text)
  "width":  0.4-5      stroke weight in view units (1.4-2 reads well)
  "anim":   draw | pop | rise | fade | sweep          how it arrives
  "at":     0-0.85     when it arrives, as a fraction of the beat
  "word":   "<a word from the narration>"             arrives when the narrator says it
  "life":   float | sway | orbit | pulse | breathe    optional endless motion once settled
  "then":   [ {"at":0.6, "cx":70, "cy":30}, ... ]     where it MOVES TO later (max 3 steps)

MOVEMENT IS THE POINT
"then" is what separates a diagram from a still. A step names a later cue ("at" or
"word") and only the fields that CHANGE — position, size, opacity, colour — and the
shape travels there smoothly. The electron leaves the panel. The packet advances to the
next router. The bar grows. The arrow's tip reaches the target. Give at least one shape
a "then" whenever the beat describes something HAPPENING rather than something existing.

RULES THAT MAKE IT READ
0. NAME EVERY SHAPE. The "id" says what the thing IS ("wall", "water", "turbine"), never
   what kind it is ("rect1", "circle2"). A later beat may continue this drawing, and the
   ids are how a shape is recognised as the SAME object across the cut.
1. FEWER, BIGGER shapes. 5-10 is a diagram; 16 is a mess. Nothing smaller than about 6 units.
2. Draw the THING, not a flowchart of the thing. Boxes with words in them are what every
   other card already does — if your drawing is three rectangles and three labels, it has
   failed. Outline the actual object with a path, or use an icon from the library.
3. ONE accent. Colour the one part the beat is about in "accent"; draw the rest in "ink" or
   "muted". A drawing where everything is accent has no focus.
4. At most 3 labels, only where a shape would otherwise be ambiguous. The narrator is
   already saying the words.
5. LEAVE ROOM. Keep shapes off each other; labels sit below or beside what they name, never
   on top of it.
6. Sequence it. Give shapes different "at" values (or "word" cues from the narration) so the
   drawing BUILDS as the narrator speaks. Use "draw" for lines, paths and arrows; "pop" or
   "rise" for solid shapes.
7. WHEN SEVERAL SHAPES MOVE, THEY MUST NOT END UP IN THE SAME PLACE. Five electrons
   that all travel to one point land as a single smudge. Space their destinations, or
   move one and let the others stay.
8. No gradients, shadows, glows or 3D — none exist here. Flat outlines and solid fills only.

ICON LIBRARY (the only legal "name" values): {$icons}

WORKED EXAMPLE
DRAW: a glass bottle melted down and re-formed as a jar
{"shapes":[
 {"id":"bottle","kind":"path","d":"M 26 28 L 26 20 L 34 20 L 34 28 C 34 34 39 36 39 44 L 39 68 L 21 68 L 21 44 C 21 36 26 34 26 28 Z","stroke":"ink","fill":"none","width":1.6,"anim":"draw","at":0},
 {"id":"cap","kind":"rect","x":25,"y":16,"w":10,"h":4,"round":1,"stroke":"none","fill":"muted","anim":"pop","at":0.12},
 {"id":"old-label","kind":"label","text":"old bottle","x":30,"y":78,"size":5.5,"fill":"muted","anim":"fade","at":0.18},
 {"id":"furnace","kind":"icon","name":"flame","x":50,"y":40,"size":16,"stroke":"accent","width":1.5,"anim":"draw","at":0.34,"life":"pulse"},
 {"id":"flow","kind":"arrow","x1":42,"y1":56,"x2":60,"y2":56,"stroke":"accent","width":1.8,"anim":"draw","at":0.42},
 {"id":"melt","kind":"circle","cx":30,"cy":50,"r":3,"stroke":"none","fill":"accent","anim":"pop","at":0.3,"then":[{"at":0.55,"cx":50,"cy":46},{"at":0.72,"cx":76,"cy":52}]},
 {"id":"jar","kind":"rect","x":65,"y":40,"w":22,"h":28,"round":3,"stroke":"ink","fill":"none","width":1.6,"anim":"draw","at":0.6},
 {"id":"lid","kind":"rect","x":63,"y":34,"w":26,"h":6,"round":2,"stroke":"none","fill":"accent","anim":"rise","at":0.7},
 {"id":"new-label","kind":"label","text":"new jar","x":76,"y":78,"size":5.5,"fill":"ink","anim":"fade","at":0.76}
],"caption":"one bottle, endlessly"}
PROMPT;
    }
}
