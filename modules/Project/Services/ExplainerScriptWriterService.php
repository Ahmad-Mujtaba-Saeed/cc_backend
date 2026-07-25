<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ExplainerScriptWriterService — "write my script for me".
 *
 * One LLM pass that turns a bare TITLE + target duration into a voice-over
 * script the explainer template can cut well: a hook, a run of self-contained
 * beats (one visualizable idea each, one paragraph per beat) and a payoff,
 * paced to the ~2.5 spoken words/second the storyboard analyzer assumes.
 *
 * This runs BEFORE a project exists (the create form's "Generate with AI"
 * button) and fills the editable script textarea — the user reviews and edits,
 * then the normal store → analyze flow takes the result. Unlike the other
 * explainer LLM passes there is no silent fallback: inventing a script the
 * user never saw would be worse than telling them to write it themselves,
 * so failure throws and the controller turns it into a friendly 503.
 */
class ExplainerScriptWriterService
{
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    /**
     * @param  array{tone?: string, audience?: string, language?: string, aspect_ratio?: string, guide?: string}  $options
     * @return array{script: string, word_count: int, estimated_seconds: int}
     */
    public function write(string $title, int $targetSeconds, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Script generation is not configured (missing OpenAI key).');
        }

        $targetWords = (int) round($targetSeconds * 2.5);
        $script = $this->requestScript($title, $targetSeconds, $targetWords, $options);
        $words = $this->wordCount($script);

        // One corrective retry when the draft misses the ±40% band — length is
        // the one property the storyboard pacing genuinely depends on.
        if ($words < (int) floor($targetWords * 0.6) || $words > (int) ceil($targetWords * 1.4)) {
            Log::info('ExplainerScriptWriter: draft off target, retrying', [
                'words' => $words,
                'target' => $targetWords,
            ]);
            $retry = $this->requestScript($title, $targetSeconds, $targetWords, $options, $script, $words);
            if ($this->wordCount($retry) > 0) {
                $script = $retry;
                $words = $this->wordCount($retry);
            }
        }

        return [
            'script' => $script,
            'word_count' => $words,
            'estimated_seconds' => (int) round($words / 2.5),
        ];
    }

    // -------------------------------------------------------------------------

    private function requestScript(
        string $title,
        int $targetSeconds,
        int $targetWords,
        array $options,
        ?string $previousDraft = null,
        ?int $previousWords = null
    ): string {
        $tone = trim((string) ($options['tone'] ?? '')) ?: 'clear and engaging';
        $audience = trim((string) ($options['audience'] ?? '')) ?: 'general viewers';
        $language = trim((string) ($options['language'] ?? '')) ?: 'English';
        $guide = trim((string) ($options['guide'] ?? ''));

        // The GUIDE is the user's own direction for this script — what to open
        // with, which order to teach in, which method to solve by. It outranks
        // the generic structure rules below, but never the format rules (JSON
        // shape, length band, spoken register), which the template depends on.
        $guideRule = $guide === '' ? '' : <<<'GUIDE'

- FOLLOW THE USER'S GUIDE. Along with the TITLE the user gives you a GUIDE: their instructions for how this particular script should go — what to open with, what to name or show first, which order to cover things in, which method or formula to solve with, what to emphasise or leave out. Treat it as the brief from the person who commissioned the video: where it conflicts with the generic structure advice in these rules, THE GUIDE WINS (if it says to open by naming the chapter, the first beat names the chapter, hook second). Cover everything it asks for, in the order it asks. The only things it cannot override are the output format, the length band, and the spoken-register rules. If the guide is vague or only covers part of the video, use your own judgement for the rest.
GUIDE;

        $system = <<<PROMPT
You write voice-over scripts for an automated explainer-video template. The template splits a script into 4-10 second scenes; each scene is one beat carrying ONE visual idea (a photo the viewer uploads, a stat card, a comparison, a quote, a list, a native equation card, a geometry diagram, a plotted graph).

Write a script for the TITLE the user gives you. The TITLE may be a topic, OR a full problem statement — possibly with the user's own hints on how to solve it. RULES:{$guideRule}
- Total length ≈ {$targetWords} words (that is {$targetSeconds} seconds at ~2.5 spoken words/second). Stay within ±20%.
- Structure: a HOOK in the first 1-2 sentences (a question, a surprising number, a bold claim) → a BODY of 4-12 self-contained beats, each 1-3 short sentences carrying exactly ONE idea a single visual could show → a PAYOFF that answers the hook or lands a takeaway.
- MATH MODE: applies whenever the TITLE's SUBJECT is mathematical — an equation, a geometric figure, a function or curve, a theorem, identity or formula, or the mathematical part of a physics question. Judge the SUBJECT, not the phrasing: "Why does a squared plus b squared equal c squared?" and "What is a derivative, visually?" are maths every bit as much as "Solve x squared plus five x minus twenty-four equals zero". A topic that merely mentions numbers (revenue, sports records, populations) is NOT maths — there the numbers are evidence, not the subject. In MATH MODE the equations, figures and graphs are DRAWN NATIVELY on screen from your words, so never describe a picture to show and never tell the viewer to "picture" or "imagine" one — just say the maths and let the storyboard render it. Speak it the way a teacher says it aloud ("x squared plus five x minus twenty-four equals zero", "one half base times height") — never spell out symbols, never write LaTeX. Two shapes:
  · SOLVING (an answer is wanted): one beat restating the GIVEN information; one beat naming the concept or formula that unlocks it; then ONE BEAT PER PHASE of the working; then the answer beat; the PAYOFF is the sanity check or the trick to remember. Follow the user's own solution hints when they gave any.
  · EXPLAINING or PROVING why something is true (nothing to compute): one beat stating the claim in plain words; one beat setting up the figure or quantities it talks about, naming them concretely; then ONE BEAT PER MOVE OF THE ARGUMENT; then the beat where the argument closes; the PAYOFF is what the result lets you do. Every beat must name a concrete drawable thing ("a right triangle with legs a and b", "the square sitting on side a", "the two smaller squares together") — a step you cannot point at is not a beat.
- MATH BEATS ARE PHASES, NOT KEYSTROKES. A beat is a PHASE of the reasoning that carries the two or three lines of working belonging to one idea, spoken as a short paragraph — "Combine the two logs into one using the product rule, so log base two of x minus one times x plus three equals three." One beat per individual algebraic move ("now subtract five", then "now divide by two") is WRONG: it shatters the working into a slideshow of disconnected single lines and the video loses the thread. Aim for 5-8 maths beats total, however long the working is — group the moves.
- SAY WHY, NOT JUST WHAT. Every maths beat must carry the REASON, not only the manipulation. Name the rule you are using and say in plain words what it does ("the product rule lets you add logs by multiplying what's inside") before or as you apply it. A beat that only announces a move ("each term gets multiplied by n") teaches nothing and is usually also wrong. If you cannot say WHY a step is allowed, you do not understand the step well enough to script it — work it out first.
- NEVER FAKE THE MATHS. Every line must be something you could defend at a blackboard. Do not write a "derivation" that is really a restatement of the result, and never invent a move that is not valid ("cos theta becomes cos n theta" is not a step, it is the answer smuggled in). If the honest argument for a theorem is induction, or Euler's formula, or multiplying it out for n equals two and seeing the angle-addition identities appear, then THAT is the script.
- One paragraph per beat, blank line between beats.
- Every beat must be VISUALIZABLE — name concrete things, numbers, places, comparisons, equations, shapes. Avoid abstractions no visual can show.
- Spoken register: short sentences, contractions welcome. No headings, no bullet markers, no stage directions, no "[pause]", no emojis, no markdown.
- Tone: {$tone}. Audience: {$audience}. Language: {$language}.

Return ONLY JSON: {"script": "...", "beat_count": N}
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $system],
            [
                'role' => 'user',
                'content' => "TITLE: {$title}" . ($guide === '' ? '' : "\n\nGUIDE:\n{$guide}"),
            ],
        ];
        if ($previousDraft !== null) {
            $messages[] = ['role' => 'assistant', 'content' => json_encode(['script' => $previousDraft])];
            $messages[] = [
                'role' => 'user',
                'content' => "Your draft was {$previousWords} words; the target is {$targetWords} ± 20%. Rewrite it at the same quality, still following the GUIDE, adjusting length only. Return ONLY the JSON.",
            ];
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 2500,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Script generation request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'explainer_script_writer');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        $script = trim((string) ($parsed['script'] ?? ''));
        // json_object mode makes fencing unlikely, but a model that wraps the
        // script itself in ``` would poison the storyboard analyzer downstream.
        $script = trim(preg_replace('/^```[a-z]*\s*|\s*```$/', '', $script));

        if ($script === '' || $this->wordCount($script) < 20) {
            throw new \Exception('Script generation returned an empty or unusable draft.');
        }

        return $script;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
