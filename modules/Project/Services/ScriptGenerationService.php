<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScriptGenerationService
{
    public function generateScript(string $sourcePrompt, array $settings = []): ?string
    {
        try {
            $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                throw new \Exception('OPENAI_API_KEY is not set');
            }

            // Get duration and calculate word counts based on it
            $duration = (int) ($settings['duration_seconds'] ?? 20);
            $duration = max(15, min(35, $duration));
            
            // Map duration to word count targets
            // Shorter videos: ~40-50 words total (creepier, more impactful)
            // Longer videos: more words per scene but maintain pacing
            $totalWordCount = $this->calculateWordCount($duration);
            
            $visualStyle = $settings['visual_style'] ?? 'cinematic';
            $tone = $settings['tone'] ?? 'creepy';
            $purpose = $settings['purpose'] ?? 'horror short';
            $targetLength = $settings['target_length'] ?? "{$duration} seconds";

            // The horror template uses the viral "dark documentary" short format
            // (cold-open hook, escalation, chilling final fact) with rich,
            // sheet-anchored image prompts; other templates keep the generic build.
            if (($settings['template'] ?? '') === 'horror_stories') {
                $sceneCount = max(6, min(20, (int) ($settings['scene_count'] ?? 10)));
                $prompt = $this->buildDocumentaryHorrorPrompt($sourcePrompt, $totalWordCount, $tone, $sceneCount, $targetLength);
                $systemPrompt = $this->buildDocumentaryHorrorSystemPrompt($tone, $sceneCount);
                // Rich 25-40 word image prompts × N scenes overflow the generic
                // 1200-token budget and the model silently truncates the JSON.
                $settings['max_tokens'] = max((int) ($settings['max_tokens'] ?? 0), 400 + 140 * $sceneCount);
            } else {
                $prompt = $this->buildPrompt($sourcePrompt, $duration, $totalWordCount, $visualStyle, $tone, $purpose, $targetLength);
                $systemPrompt = $this->buildSystemPrompt($visualStyle, $tone, $purpose, $duration);
            }

            $payload = [
                'model' => $settings['model'] ?? LlmModels::for('general'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $settings['max_tokens'] ?? 1200,
                'temperature' => $settings['temperature'] ?? 0.5,
                'presence_penalty' => $settings['presence_penalty'] ?? 0.0,
                'frequency_penalty' => $settings['frequency_penalty'] ?? 0.0,
            ];
            if (($settings['template'] ?? '') === 'horror_stories') {
                // Guarantees parseable JSON — the scene parser never sees fences.
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $httpResponse = Http::withToken($apiKey)
                ->timeout($settings['timeout'] ?? 60)
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$httpResponse->successful()) {
                Log::error('Script generation OpenAI request failed', [
                    'status' => $httpResponse->status(),
                    'body' => $httpResponse->body(),
                ]);
                return null;
            }

            CostTracker::recordChat($payload['model'], $httpResponse->json('usage'), 'script_generation');

            $data = $httpResponse->json();
            $script = $data['choices'][0]['message']['content'] ?? null;

            if (empty($script)) {
                Log::error('OpenAI returned empty script for image based shorts', ['response' => $data]);
                return null;
            }

            // Try to validate JSON output
            $jsonData = json_decode($script, true);
            if (!is_array($jsonData) || !isset($jsonData['scenes']) || !is_array($jsonData['scenes'])) {
                Log::warning('Script generation did not return valid JSON, will attempt to parse as-is', [
                    'script_preview' => substr($script, 0, 200),
                    'json_error' => json_last_error_msg()
                ]);
            }

            Log::info('Script generation completed', [
                'duration_seconds' => $duration,
                'total_word_count' => $totalWordCount,
                'style' => $visualStyle,
                'tone' => $tone,
                'target_length' => $targetLength,
                'source_prompt_length' => strlen($sourcePrompt),
                'is_json' => !is_null($jsonData),
            ]);

            return trim($script);
        } catch (\Exception $e) {
            Log::error('Script generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate appropriate total word count for the given duration
     * Allows more words for longer videos while keeping short ones punchy and scary
     */
    private function calculateWordCount(int $duration): int
    {
        $wordCounts = [
            15 => 45,   // ~3 words per second - keep it tight and scary
            20 => 65,   // ~3.25 words per second - still punchy
            25 => 85,   // ~3.4 words per second - add more detail
            30 => 110,  // ~3.7 words per second - fuller stories
            35 => 140,  // ~4 words per second - room for plot
        ];
        return $wordCounts[$duration] ?? 65;
    }

    /**
     * The viral "dark documentary" short format (the world.wide.story school):
     * cold-open hook, one concrete protagonist, escalating beats, and a final
     * line that leaves the viewer unsettled. Scenes carry camera direction
     * (shot / motion / scare) and every image prompt re-anchors the same
     * character + setting sheets so the generated images stay consistent.
     */
    private function buildDocumentaryHorrorSystemPrompt(string $tone, int $sceneCount): string
    {
        return "You write scripts for a viral dark-storytelling shorts channel (the style behind "
            . "accounts that tell chilling true-feeling mysteries: Everest's frozen climbers, forbidden islands, "
            . "vanished towns). Your response MUST be valid JSON only — no markdown, no commentary.\n\n"
            . "THE FORMAT'S LAWS (non-negotiable):\n"
            . "1. SCENE 1 IS THE HOOK — the single most shocking fact or image of the whole story, "
            . "stated in one or two short sentences (max 12 words total). Open mid-story. NEVER introduce "
            . "('this is the story of…' is banned). Good hooks: 'He sat down to rest. He never got up.' / "
            . "'There's an island you're not allowed to survive.'\n"
            . "2. ONE concrete protagonist or place. Real-feeling specifics — years, distances, counts, names — "
            . "invented if needed, but always concrete. Vague horror is dead horror.\n"
            . "3. Every scene ESCALATES: each narration adds one new unsettling fact that makes the previous "
            . "one worse. No filler, no repetition.\n"
            . "4. THE LAST SCENE lands a chilling, unresolved fact in present tense — the 'it's still happening' "
            . "note ('His body is still there. Climbers use him as a landmark.'). Never a moral, never comfort.\n"
            . "5. VOICE: short punchy sentences. Present tense. Max ~12 words per sentence. "
            . "Banned: 'little did they know', 'suddenly', 'nightmare', rhetorical filler questions. "
            . "The {$tone} tone comes from facts and imagery, not adjectives.\n\n"
            . "VISUAL DIRECTION:\n"
            . "- First define character_sheet: ONE reusable visual description of the protagonist "
            . "(max 25 words: build, clothing with colors, distinctive features). If the story has no person, "
            . "describe the central subject (the island, the house).\n"
            . "- Define setting_sheet: ONE reusable description of the location/era/weather/light (max 20 words).\n"
            . "- Every scene 'image' is a 25-40 word cinematic shot description of THIS scene's action, framing "
            . "and light. Refer to the protagonist briefly ('the climber', 'the house') — the full sheets are "
            . "appended to every image automatically, so never re-describe and never contradict them. "
            . "Think like a film director: foreground/background, where the light comes from, what's barely visible.\n"
            . "- Vary 'shot' across scenes: wide (establish), medium, closeup (emotion), extreme_closeup (detail/reveal). "
            . "Never the same shot twice in a row.\n"
            . "- 'motion' is the camera move for that image: push_in (tension builds), pull_back (reveal scale/isolation), "
            . "drift_left / drift_right (uneasy calm). Favor push_in as tension rises.\n"
            . "- 'scare' is true on AT MOST " . ($sceneCount >= 12 ? 2 : 1) . " scene(s) — the single peak reveal. Everywhere else false.\n\n"
            . "OUTPUT: only this JSON shape, with EXACTLY {$sceneCount} scenes:\n"
            . "{\"character_sheet\": \"…\", \"setting_sheet\": \"…\", \"scenes\": ["
            . "{\"image\": \"…\", \"narration\": \"…\", \"shot\": \"wide\", \"motion\": \"push_in\", \"scare\": false}]}";
    }

    private function buildDocumentaryHorrorPrompt(string $sourcePrompt, int $totalWordCount, string $tone, int $sceneCount, string $targetLength): string
    {
        $avgWordsPerScene = max(4, (int) round($totalWordCount / max(1, $sceneCount)));
        $wordRange = max(3, $avgWordsPerScene - 2) . '-' . ($avgWordsPerScene + 3);

        return "TOPIC / IDEA FROM THE CREATOR:\n{$sourcePrompt}\n\n"
            . "Build the strongest possible dark-documentary short from this idea.\n"
            . "If the topic is vague, invent concrete specifics (a year, a place, a name) that make it feel real.\n\n"
            . "CONSTRAINTS:\n"
            . "- Video length: {$targetLength}; total narration ≈ {$totalWordCount} words.\n"
            . "- EXACTLY {$sceneCount} scenes; each narration {$wordRange} words.\n"
            . "- Scene 1 = the hook (the most shocking beat of the entire story).\n"
            . "- Final scene = the chilling present-tense fact that lingers.\n"
            . "- Tone: {$tone}, carried by concrete detail.\n"
            . "- character_sheet / setting_sheet reused word-for-word inside every image prompt.\n\n"
            . "Return ONLY the JSON object described in the system message.";
    }

    private function buildPrompt(string $sourcePrompt, int $duration, int $totalWordCount, string $visualStyle, string $tone, string $purpose, string $targetLength): string
    {
        $avgWordsPerScene = round($totalWordCount / 10, 0);
        $wordRange = max(3, $avgWordsPerScene - 2) . '-' . ($avgWordsPerScene + 3);
        
        return "Create a gripping, narrative-driven AI image-based horror video script.\n\n"
            . "CORE REQUIREMENTS:\n"
            . "- Duration: {$targetLength}\n"
            . "- Total narration: ~{$totalWordCount} words\n"
            . "- Visual style: {$visualStyle}\n"
            . "- Tone: {$tone}\n\n"
            . "VISUAL COHERENCE (CRITICAL):\n"
            . "- Create ONE continuous story that flows logically across 8-12 scenes\n"
            . "- Each image must follow from the previous - avoid jump cuts to unrelated scenes\n"
            . "- Maintain consistent characters, locations, and environment throughout\n"
            . "- If a character or setting appears in multiple scenes, describe it consistently\n"
            . "- Keep lighting/atmosphere consistent unless the story explicitly changes it\n"
            . "- The images should form a visual narrative, not a collection of random scary scenes\n\n"
            . "IMAGE DESCRIPTIONS (SYNC REQUIREMENT):\n"
            . "- Be SPECIFIC about details: colors, textures, character positions, spatial relationships\n"
            . "- Include anchoring details that help AI maintain consistency (e.g., 'same room', 'same person')\n"
            . "- Use 4-8 words per image, not generic descriptions\n"
            . "- Example GOOD sync: 'Woman's face in shadows' → 'Woman turning toward camera' → 'Woman's eyes open in horror'\n"
            . "- Example BAD (incoherent): Random scary images with no connection\n\n"
            . "NARRATION SYNC:\n"
            . "- Each narration ({$wordRange} words) must match its corresponding image\n"
            . "- Flow naturally from scene to scene\n"
            . "- Create emotional progression: hook → tension → climax → resolution\n\n"
            . "SOURCE PROMPT:\n{$sourcePrompt}\n\n"
            . "REQUIRED JSON OUTPUT (SYNC FORMAT):\n"
            . "Return ONLY valid JSON:\n"
            . "{\n"
            . "  \"scenes\": [\n"
            . "    {\"image\": \"specific, detailed image description matching {$visualStyle}\", \"narration\": \"narration for this image\"},\n"
            . "    {\"image\": \"next scene, visually coherent with previous\", \"narration\": \"narration continues story\"},\n"
            . "    ...(continue for 8-12 scenes)...\n"
            . "  ]\n"
            . "}\n\n"
            . "CRITICAL REQUIREMENTS:\n"
            . "- Output ONLY the JSON object\n"
            . "- Each scene has 'image' (4-8 specific words) and 'narration' (natural, easy to read)\n"
            . "- Images must form a COHERENT VISUAL SEQUENCE\n"
            . "- No markdown, no code blocks, no explanations";
    }

    private function buildSystemPrompt(string $visualStyle, string $tone, string $purpose, int $duration): string
    {
        $durationText = $duration . '-second';
        return "You are a master storyteller specializing in short-form horror content. "
            . "Your response MUST be valid JSON only - no explanations, no code blocks, no markdown.\n\n"
            . "Create a {$durationText} horror narrative with STRICT VISUAL CONSISTENCY:\n\n"
            . "NARRATIVE COHERENCE:\n"
            . "- Create a single, linear story arc with clear progression\n"
            . "- Each scene image MUST logically follow the previous scene\n"
            . "- Characters, settings, and objects must be consistent across scenes\n"
            . "- The visual journey should feel like one continuous nightmare, not jump cuts\n"
            . "- Environmental details (lighting, time of day, location) should remain consistent unless explicitly changing\n\n"
            . "IMAGE DESCRIPTION REQUIREMENTS:\n"
            . "- Include specific details that will make the AI generate similar compositions\n"
            . "- Describe consistent elements: same characters, same room/setting, same mood\n"
            . "- When a character appears in multiple scenes, describe them the SAME WAY each time\n"
            . "- Maintain visual theme: if horror is dark/shadowy, keep it dark/shadowy throughout\n"
            . "- Example of GOOD progression:\n"
            . "  Scene 1: 'Dark bedroom, figure in shadows'\n"
            . "  Scene 2: 'Same dark bedroom, figure moving closer'\n"
            . "  Scene 3: 'Dark bedroom close-up, figure's face emerging'\n"
            . "- Example of BAD progression (incoherent):\n"
            . "  Scene 1: 'Bright daylight forest'\n"
            . "  Scene 2: 'Dark abandoned hospital'\n"
            . "  Scene 3: 'Underwater alien city'\n\n"
            . "TONE & IMPACT:\n"
            . "- Focus on: psychological tension, vivid visual imagery, pacing, emotional resonance\n"
            . "- The {$tone} tone should permeate every word\n"
            . "- Make viewers feel unsettled, disturbed, or frightened\n"
            . "- Use specific, concrete details rather than abstract descriptions\n"
            . "- Each narration should be 5-8 words and easy to read aloud\n\n"
            . "OUTPUT: Return ONLY a JSON object with a 'scenes' array. "
            . "Each scene has 'image' (specific, consistent 4-8 words) and 'narration' (readable) fields. "
            . "Ensure VISUAL CONTINUITY - scenes should form a cohesive visual narrative.";
    }
}
