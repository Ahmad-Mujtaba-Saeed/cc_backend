<?php

namespace Modules\Project\Support;

/**
 * Natural-language delivery directions for gpt-4o-mini-tts.
 *
 * The OpenAI engine has no "emotion" parameter — delivery is steered by an
 * `instructions` string ("Narrate dramatically with suspense and slow
 * pacing"). Kokoro has no equivalent, so these are only attached on the
 * openai path. Each template gets a base persona and its own settings
 * (tone, commentary_style, rewrite_style…) sharpen it.
 */
class TtsInstructions
{
    public static function for(?string $templateType, array $settings = []): ?string
    {
        return match ($templateType) {
            'ai_horror_shorts' => self::horror($settings),
            'yt_compilation_short' => self::compilation($settings),
            'yt_automation_short' => self::automation($settings),
            'ai_image_based_shorts' => self::imageShorts($settings),
            'ai_explainer_video' => self::explainer($settings),
            default => null,
        };
    }

    private static function horror(array $settings): string
    {
        $base = 'You are narrating a short horror story for a vertical video. '
            . 'Keep the listener unsettled; never sound cheerful or salesy. '
            . 'Articulate clearly — every word is shown as an on-screen caption.';

        return $base . ' ' . match ($settings['tone'] ?? 'creepy') {
            'tense' => 'Speak with tight, urgent intensity: a slightly quickened pace, '
                . 'clipped sentence endings, and a sense that danger is very close.',
            'jump_scare' => 'Start quiet and controlled, almost a whisper, then strike '
                . 'key phrases with sudden sharp emphasis before dropping back to calm.',
            default => 'Speak in a low, hushed, ominous voice — slow and deliberate, '
                . 'like a campfire ghost story. Let dread build and linger at the end of sentences.',
        };
    }

    private static function compilation(array $settings): string
    {
        $base = 'You are the voice-over commentator for a fast-paced video compilation. '
            . 'Each line is a short reaction to a clip, so land it quickly and cleanly.';

        return $base . ' ' . match ($settings['commentary_style'] ?? 'energetic') {
            'sarcastic' => 'Deliver every line dry and deadpan, with a wry, knowing edge — '
                . 'understated amusement rather than excitement.',
            'dramatic' => 'Deliver every line with big movie-trailer gravitas: weighty pauses, '
                . 'deep emphasis, building intensity.',
            'minimal' => 'Deliver every line calm and matter-of-fact, understated and precise, '
                . 'with no hype.',
            default => 'Deliver every line with high sports-commentator energy: fast, punchy, '
                . 'genuinely excited, punching the key word of each line.',
        };
    }

    private static function automation(array $settings): string
    {
        $base = 'You are narrating a short-form vertical video. Keep momentum high and hold '
            . 'attention from the first word; articulate clearly for on-screen captions.';

        return $base . ' ' . match ($settings['rewrite_style'] ?? 'professional') {
            'casual' => 'Sound like a friend talking: relaxed, warm and conversational, with natural ups and downs.',
            'energetic' => 'Sound pumped: quick pace, bright tone, strong emphasis on key words.',
            'educational' => 'Sound like a great teacher: clear, patient and confident, emphasising the ideas that matter.',
            'entertaining' => 'Sound playful and expressive, with plenty of vocal variety and a smile in the voice.',
            default => 'Sound polished and professional: confident, well-paced, crisp diction.',
        };
    }

    private static function imageShorts(array $settings): string
    {
        $base = 'You are narrating a cinematic short-form video built from AI imagery. '
            . 'Keep the delivery engaging and clear — every word appears as a caption.';

        $tone = match ($settings['tone'] ?? 'neutral') {
            'positive' => 'Keep the mood upbeat and optimistic, warm and encouraging.',
            'serious' => 'Keep the mood grounded and earnest — measured pace, real weight behind statements.',
            'humorous' => 'Keep the mood light and witty, with playful timing and a smile in the voice.',
            default => 'Keep the mood balanced and natural, letting the story carry the emotion.',
        };

        $purpose = match ($settings['purpose'] ?? 'general') {
            'marketing' => ' Sell the idea with confident, persuasive energy.',
            'educational' => ' Explain like a great teacher — clear, patient, emphasising key ideas.',
            'storytelling' => ' Narrate like an audiobook storyteller, drawing the listener in.',
            'persuasive' => ' Build conviction sentence by sentence, ending statements firmly.',
            default => '',
        };

        return $base . ' ' . $tone . $purpose;
    }

    private static function explainer(array $settings): string
    {
        return 'You are the narrator of a polished explainer video. Sound like a friendly, '
            . 'confident expert: warm, clear and well-paced, with natural emphasis on key '
            . 'terms and a brief lift of energy on the opening hook and the closing line. '
            . 'Articulate cleanly; avoid any theatrical or salesy tone.';
    }
}
