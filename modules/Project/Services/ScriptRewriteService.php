<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScriptRewriteService
{
    /**
     * Rewrite script using OpenAI.
     */
    public function rewriteScript(array $transcription, array $settings = []): ?string
    {
        try {
            Log::info('Starting script rewrite with OpenAI');

            $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                throw new \Exception('OPENAI_API_KEY is not set');
            }

            // Extract text from transcription
            $originalText = collect($transcription['segments'] ?? [])
                ->map(fn($segment) => $segment['text'] ?? '')
                ->implode(' ');

            if (empty($originalText)) {
                throw new \Exception('No text found in transcription');
            }

            // Get rewrite settings
            $rewriteStyle = $settings['rewrite_style'] ?? 'professional';
            $targetLength = $settings['target_length'] ?? 'similar';
            $tone = $settings['tone'] ?? 'neutral';
            $purpose = $settings['purpose'] ?? 'general';

            $prompt = $this->generateRewritePrompt($originalText, $rewriteStyle, $targetLength, $tone, $purpose);

            $model = $settings['model'] ?? LlmModels::for('general');

            $httpResponse = Http::withToken($apiKey)
                ->timeout($settings['timeout'] ?? 60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->getSystemPrompt($rewriteStyle, $tone, $purpose)],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $settings['max_tokens'] ?? 2000,
                    'temperature' => $settings['temperature'] ?? 0.7,
                    'presence_penalty' => $settings['presence_penalty'] ?? 0.1,
                    'frequency_penalty' => $settings['frequency_penalty'] ?? 0.1,
                ]);

            if (!$httpResponse->successful()) {
                Log::error('OpenAI request failed', [
                    'status' => $httpResponse->status(),
                    'body' => $httpResponse->body(),
                ]);
                throw new \Exception('OpenAI request failed with status ' . $httpResponse->status());
            }

            CostTracker::recordChat($model, $httpResponse->json('usage'), 'script_rewrite');

            $data = $httpResponse->json();
            $rewrittenScript = $data['choices'][0]['message']['content'] ?? null;
            if (empty($rewrittenScript)) {
                Log::error('OpenAI returned empty script', ['response' => $data]);
                throw new \Exception('OpenAI returned empty script');
            }

            Log::info('Script rewrite completed', [
                'original_length' => strlen($originalText),
                'rewritten_length' => strlen($rewrittenScript),
                'style' => $rewriteStyle,
                'tone' => $tone,
                'purpose' => $purpose
            ]);

            return $rewrittenScript;

        } catch (\Exception $e) {
            Log::error('Script rewrite failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get system prompt based on rewrite parameters.
     */
    private function getSystemPrompt(string $style, string $tone, string $purpose): string
    {
        $basePrompt = "You are a professional script writer and content creator. ";
        
        $stylePrompts = [
            'professional' => "Write in a professional, polished manner suitable for business contexts.",
            'casual' => "Write in a conversational, friendly tone that feels natural and engaging.",
            'energetic' => "Write with high energy and enthusiasm to captivate the audience.",
            'educational' => "Write in an informative, educational style that clearly explains concepts.",
            'entertaining' => "Write in an entertaining, engaging style that keeps viewers interested."
        ];

        $tonePrompts = [
            'neutral' => "Maintain a balanced, neutral tone throughout.",
            'positive' => "Use positive, uplifting language.",
            'serious' => "Use a serious, authoritative tone.",
            'humorous' => "Incorporate appropriate humor and wit."
        ];

        $purposePrompts = [
            'general' => "Create content suitable for general audiences.",
            'marketing' => "Focus on marketing and promotional aspects.",
            'educational' => "Prioritize educational value and clarity.",
            'storytelling' => "Focus on narrative and storytelling elements.",
            'persuasive' => "Write persuasively to influence the audience."
        ];

        return $basePrompt . " " . 
               ($stylePrompts[$style] ?? $stylePrompts['professional']) . " " .
               ($tonePrompts[$tone] ?? $tonePrompts['neutral']) . " " .
               ($purposePrompts[$purpose] ?? $purposePrompts['general']) . " " .
               "Rewrite the given script while maintaining the core message and key information.";
    }

    /**
     * Generate rewrite prompt for OpenAI.
     */
    private function generateRewritePrompt(string $originalText, string $style, string $targetLength, string $tone, string $purpose): string
    {
        $lengthInstructions = [
            'shorter' => 'Make it approximately 25% shorter while preserving all key points and main ideas.',
            'longer' => 'Make it approximately 25% longer by adding relevant details, examples, or explanations.',
            'similar' => 'Keep it roughly the same length while improving clarity and flow.'
        ];

        $styleSpecificInstructions = [
            'professional' => 'Use industry-appropriate terminology, maintain formal language, and ensure polished presentation.',
            'casual' => 'Use conversational language, include relatable examples, and maintain a friendly approach.',
            'energetic' => 'Use dynamic language, include engaging hooks, and maintain high energy throughout.',
            'educational' => 'Structure content for learning, include clear explanations, and add educational value.',
            'entertaining' => 'Include engaging elements, maintain interest, and add entertainment value.'
        ];

        return "Please rewrite this video script according to these requirements:

**Style:** {$style}
**Length:** {$lengthInstructions[$targetLength]}
**Tone:** {$tone}
**Purpose:** {$purpose}

**Style-specific instructions:** {$styleSpecificInstructions[$style]}

**Original script:**
{$originalText}

**Guidelines:**
- Maintain the original core message and key information
- Improve clarity, flow, and engagement
- Ensure the rewritten script flows naturally when spoken
- Keep important facts, figures, and main points intact
- Make it more suitable for the target audience and purpose

Please provide only the rewritten script without any additional commentary or explanations.";
    }

    /**
     * Validate rewritten script quality.
     */
    public function validateRewrittenScript(string $originalText, string $rewrittenText): array
    {
        $issues = [];
        
        // Check if it's too different (might have lost meaning)
        $originalWords = str_word_count($originalText);
        $rewrittenWords = str_word_count($rewrittenText);
        
        if ($rewrittenWords < $originalWords * 0.3) {
            $issues[] = 'Rewritten script is significantly shorter than original - may have lost important content';
        }
        
        if ($rewrittenWords > $originalWords * 3) {
            $issues[] = 'Rewritten script is significantly longer than original - may have added unnecessary content';
        }
        
        // Check for empty content
        if (empty(trim($rewrittenText))) {
            $issues[] = 'Rewritten script is empty';
        }
        
        // Check if it's exactly the same (no changes made)
        if ($originalText === $rewrittenText) {
            $issues[] = 'No changes were made to the original script';
        }
        
        return $issues;
    }
}
