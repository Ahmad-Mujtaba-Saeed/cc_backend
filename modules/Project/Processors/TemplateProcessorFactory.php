<?php

namespace Modules\Project\Processors;

use Modules\Project\Models\Project;
use Modules\Project\Services\PixabayMusicService;
use Modules\Project\Support\TtsVoices;

class TemplateProcessorFactory
{
    /**
     * Default Pixabay music category per template. Everything not listed
     * defaults to 'none' — those templates never had background music, so it
     * stays opt-in. The explainer's 'auto' maps its storyboard mood.
     */
    private const MUSIC_CATEGORY_DEFAULTS = [
        'ai_horror_shorts' => 'horror',
        'ai_explainer_video' => 'auto',
    ];

    /**
     * Create appropriate processor based on template type.
     */
    public static function create(Project $project): AbstractVideoProcessor
    {
        $templateType = $project->template_type;

        return match ($templateType) {
            'yt_automation_short' => new AIVideoProcessor($project),
            'ai_image_based_shorts' => new AIImageShortsProcessor($project),
            'ai_horror_shorts' => new AIHorrorShortsProcessor($project),
            'yt_gameplay_short' => new YTGameplayShortProcessor($project),
            'yt_compilation_short' => new CompilationShortsProcessor($project),
            'ranking_moments_short' => new RankingMomentsProcessor($project),
            'ai_explainer_video' => new ExplainerVideoProcessor($project),
            default => throw new \InvalidArgumentException("Unknown template type: {$templateType}")
        };
    }

    /**
     * Get available template types.
     */
    public static function getAvailableTemplates(): array
    {
        // Names and descriptions here are what users read on the landing page,
        // the templates gallery and the create flow. They are deliberately
        // written as "what you put in -> what you get out", not as a list of
        // the internal pipeline stages, so someone who has never used the app
        // can pick the right template on the first read.
        return [
            'ai_explainer_video' => [
                'name' => 'AI Explainer Video',
                'description' => 'Paste a script or a topic and get a finished explainer video. The AI splits it into scenes, picks the right layout for each one (charts, diagrams, bullet slides, even a worked-out maths board), narrates it, syncs the captions and renders it in widescreen.',
                'icon' => 'presentation',
                'aspect_ratio' => '16:9',
                'requires_upload' => false
            ],
            'yt_automation_short' => [
                'name' => 'Repurpose Video to Short',
                'description' => 'Turn a video you already have into a punchy vertical short. The AI transcribes it, rewrites the script tighter in the tone you choose, re-records it in a studio AI voice, keeps the frame on whoever is speaking and burns in karaoke captions.',
                'icon' => 'brain',
                'aspect_ratio' => '9:16',
                'requires_upload' => true
            ],
            'yt_gameplay_short' => [
                'name' => 'Long Video to Shorts',
                'description' => 'Paste one long YouTube video and get several ready-to-post vertical shorts out of it. The AI finds the strongest moments, cuts the dead air out of each one, adds karaoke captions, and can stack satisfying gameplay footage underneath.',
                'icon' => 'play-circle',
                'aspect_ratio' => '9:16',
                'requires_upload' => true
            ],
            'yt_compilation_short' => [
                'name' => 'Themed Moments Compilation',
                'description' => 'Give 2-3 YouTube links and a theme like "30 Disaster Moments". The AI ranks the best clips across all of them, removes the original speech so nobody talks over your voiceover, writes fresh commentary and stitches one countdown compilation.',
                'icon' => 'film',
                'aspect_ratio' => '16:9',
                'requires_upload' => false
            ],
            'ranking_moments_short' => [
                'name' => 'Top Moments Ranking',
                'description' => 'One video in, a countdown ranking short out. The AI scores every moment, ranks the winners with star ratings on a colourful rank rail, adds a bold title card, and pops the captions one word at a time.',
                'icon' => 'trophy',
                'aspect_ratio' => '9:16',
                'requires_upload' => false
            ],
            'ai_image_based_shorts' => [
                'name' => 'AI Story Short from a Prompt',
                'description' => 'Type an idea and get a finished short with no footage at all. The AI writes the script, generates an image for every scene in the visual style you pick, animates the characters, narrates it and burns in the captions.',
                'icon' => 'sparkles',
                'aspect_ratio' => '9:16',
                'requires_upload' => false
            ],
            'ai_horror_shorts' => [
                'name' => 'AI Horror Story Short',
                'description' => 'Type a premise and get a chilling narrated horror short. The AI writes the story beat by beat, generates eerie visuals in your chosen style, layers on atmosphere and effects, and burns in the captions.',
                'icon' => 'ghost',
                'aspect_ratio' => '9:16',
                'requires_upload' => false
            ],
        ];
    }

    /**
     * Get template configuration for validation.
     */
    public static function getTemplateConfig(string $templateType): array
    {
        $config = match ($templateType) {
            'yt_automation_short' => AIVideoProcessor::getStaticTemplateConfig(),
            'ai_image_based_shorts' => AIImageShortsProcessor::getStaticTemplateConfig(),
            'ai_horror_shorts' => AIHorrorShortsProcessor::getStaticTemplateConfig(),
            'yt_gameplay_short' => YTGameplayShortProcessor::getStaticTemplateConfig(),
            'yt_compilation_short' => CompilationShortsProcessor::getStaticTemplateConfig(),
            'ranking_moments_short' => RankingMomentsProcessor::getStaticTemplateConfig(),
            'ai_explainer_video' => ExplainerVideoProcessor::getStaticTemplateConfigArray(),
            default => []
        };

        return self::injectRuntimeOptions($templateType, $config);
    }

    /**
     * Runtime decoration of the static per-processor schemas, so admin
     * switches take effect without touching every template:
     *
     *  - `tts_voice` options/default follow the ACTIVE narration engine
     *    (Kokoro voices vs gpt-4o-mini-tts voices, per-template default);
     *    the active provider ships as `config['tts']` for the create UI.
     *  - every template gains a Pixabay `music_category` picker (+ volume),
     *    defaulting per MUSIC_CATEGORY_DEFAULTS ('none' where the template
     *    never had music).
     */
    private static function injectRuntimeOptions(string $templateType, array $config): array
    {
        if (empty($config) || !is_array($config['settings_schema'] ?? null)) {
            return $config;
        }

        $schema = $config['settings_schema'];
        $provider = TtsVoices::activeProvider();

        $config['tts'] = [
            'provider' => $provider,
            'voices' => TtsVoices::forProvider($provider),
        ];

        if (isset($schema['tts_voice'])) {
            $schema['tts_voice']['options'] = TtsVoices::forProvider($provider);
            $schema['tts_voice']['default'] = TtsVoices::defaultFor($provider, $templateType);
        }

        $categoryOptions = ['none' => 'None'];
        if ($templateType === 'ai_explainer_video') {
            $categoryOptions['auto'] = 'Auto — match mood';
        }
        foreach (PixabayMusicService::CATEGORIES as $category) {
            $categoryOptions[$category] = ucfirst($category);
        }
        // The user's own uploads, private to them. Listed last because it is
        // empty until they add something.
        $categoryOptions[\Modules\Project\Services\UserMusicLibrary::CATEGORY] = 'My music (uploaded)';

        $schema['music_category'] = array_merge($schema['music_category'] ?? [], [
            'type' => 'select',
            'label' => 'Background Music',
            'options' => $categoryOptions,
            'default' => self::MUSIC_CATEGORY_DEFAULTS[$templateType] ?? 'none',
        ]);

        if (!isset($schema['music_volume'])) {
            $schema['music_volume'] = [
                'type' => 'number',
                'label' => 'Music Volume',
                'min' => 0,
                'max' => 1,
                'step' => 0.05,
                'default' => 0.12,
            ];
        }

        $config['settings_schema'] = $schema;

        return $config;
    }


    
    /**
     * Validate template-specific settings.
     */
    public static function validateTemplateSettings(string $templateType, array $settings): array
    {
        $errors = [];

        // Both engines' voices validate regardless of the active provider so a
        // project saved under one engine still validates after an admin switch
        // (the TTS router re-resolves at synth time).
        $validVoices = TtsVoices::allIds();

        // Pixabay background-music picker (injected into every template).
        if (isset($settings['music_category'])) {
            $validCategories = array_merge(
                ['none', 'auto', \Modules\Project\Services\UserMusicLibrary::CATEGORY],
                PixabayMusicService::CATEGORIES
            );
            if (!in_array(strtolower((string) $settings['music_category']), $validCategories, true)) {
                $errors[] = 'Invalid background music category selected';
            }
        }
        if (isset($settings['music_volume']) && $settings['music_volume'] !== '') {
            $volume = is_numeric($settings['music_volume']) ? (float) $settings['music_volume'] : null;
            if ($volume === null || $volume < 0 || $volume > 1) {
                $errors[] = 'Music volume must be between 0 and 1';
            }
        }
        if (isset($settings['music_track_id']) && $settings['music_track_id'] !== ''
            && !preg_match('/^[a-z0-9]{1,32}$/i', (string) $settings['music_track_id'])) {
            $errors[] = 'Invalid background music track selected';
        }

        switch ($templateType) {
            case 'yt_automation_short':
                // Validate TTS voice
                if (isset($settings['tts_voice']) && !in_array($settings['tts_voice'], $validVoices)) {
                    $errors[] = 'Invalid TTS voice selected';
                }

                // Validate rewrite style
                $validStyles = ['professional', 'casual', 'energetic', 'educational', 'entertaining'];
                if (isset($settings['rewrite_style']) && !in_array($settings['rewrite_style'], $validStyles)) {
                    $errors[] = 'Invalid rewrite style selected';
                }
                break;

            case 'ai_image_based_shorts':
                if (isset($settings['scene_count'])) {
                    $sceneCount = is_numeric($settings['scene_count']) ? (int) $settings['scene_count'] : null;
                    if ($sceneCount === null || $sceneCount < 1 || $sceneCount > 12) {
                        $errors[] = 'Scene count must be a number between 1 and 12.';
                    }
                }

                $validStyles = ['cinematic', 'storyboard', 'animated', 'photo_realistic', 'cartoon'];
                if (isset($settings['visual_style']) && !in_array($settings['visual_style'], $validStyles)) {
                    $errors[] = 'Invalid visual style selected';
                }

                if (isset($settings['tts_voice']) && !in_array($settings['tts_voice'], $validVoices)) {
                    $errors[] = 'Invalid TTS voice selected';
                }

                $validCaptions = ['modern_karaoke', 'classic_block', 'minimal_clean'];
                if (isset($settings['caption_template']) && !in_array($settings['caption_template'], $validCaptions)) {
                    $errors[] = 'Invalid caption template selected';
                }
                break;

            case 'ai_horror_shorts':
                if (isset($settings['scene_count'])) {
                    $sceneCount = is_numeric($settings['scene_count']) ? (int) $settings['scene_count'] : null;
                    if ($sceneCount === null || $sceneCount < 1 || $sceneCount > 12) {
                        $errors[] = 'Scene count must be a number between 1 and 12.';
                    }
                }

                $validStyles = ['cartoon_horror', 'gothic', 'cinematic', 'photo_realistic'];
                if (isset($settings['visual_style']) && !in_array($settings['visual_style'], $validStyles)) {
                    $errors[] = 'Invalid visual style selected';
                }

                if (isset($settings['tts_voice']) && !in_array($settings['tts_voice'], $validVoices)) {
                    $errors[] = 'Invalid TTS voice selected';
                }

                $validCaptions = ['modern_karaoke', 'classic_block', 'minimal_clean'];
                if (isset($settings['caption_template']) && !in_array($settings['caption_template'], $validCaptions)) {
                    $errors[] = 'Invalid caption template selected';
                }
                break;

            case 'yt_gameplay_short':
                // Validate input mode
                if (isset($settings['input_mode'])) {
                    $validModes = ['youtube_url', 'upload'];
                    if (!in_array($settings['input_mode'], $validModes)) {
                        $errors[] = 'Invalid input mode';
                    }
                }

                // Validate gameplay source
                if (isset($settings['gameplay_source'])) {
                    $validSources = ['backend_library', 'custom_upload'];
                    if (!in_array($settings['gameplay_source'], $validSources)) {
                        $errors[] = 'Invalid gameplay source';
                    }
                }

                // Validate caption template
                if (isset($settings['caption_template'])) {
                    $validTemplates = ['modern_karaoke', 'classic_block', 'minimal_clean'];
                    if (!in_array($settings['caption_template'], $validTemplates)) {
                        $errors[] = 'Invalid caption template';
                    }
                }

                // Validate caption position
                if (isset($settings['caption_position'])) {
                    $validPositions = ['top_section', 'bottom_section'];
                    if (!in_array($settings['caption_position'], $validPositions)) {
                        $errors[] = 'Invalid caption position';
                    }
                }

                // Validate number of shorts (1-4)
                if (isset($settings['num_shorts'])) {
                    $numShorts = is_numeric($settings['num_shorts']) ? (int) $settings['num_shorts'] : null;
                    if ($numShorts === null || $numShorts < 1 || $numShorts > 4) {
                        $errors[] = 'Number of shorts must be between 1 and 4';
                    }
                }
                break;

            case 'yt_compilation_short':
                // Validate YouTube URLs (1-3, flat string fields)
                $validUrlCount = 0;
                foreach (['youtube_url_1', 'youtube_url_2', 'youtube_url_3'] as $urlField) {
                    $url = trim((string) ($settings[$urlField] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    if (!preg_match('/(?:v=|youtu\\.be\\/|shorts\\/)([\\w-]{11})/', $url)) {
                        $errors[] = "Invalid YouTube URL provided in {$urlField}";
                    } else {
                        $validUrlCount++;
                    }
                }
                if (array_key_exists('youtube_url_1', $settings) && $validUrlCount === 0) {
                    $errors[] = 'At least one valid YouTube URL is required';
                }

                // Validate theme
                if (isset($settings['theme'])) {
                    $theme = trim((string) $settings['theme']);
                    if ($theme === '') {
                        $errors[] = 'Compilation theme is required';
                    } elseif (mb_strlen($theme) > 100) {
                        $errors[] = 'Compilation theme may not exceed 100 characters';
                    }
                }

                if (isset($settings['target_clip_count']) && !in_array((string) $settings['target_clip_count'], ['20', '30', '40', '50'], true)) {
                    $errors[] = 'Invalid number of moments selected';
                }

                if (isset($settings['max_clip_seconds']) && !in_array((string) $settings['max_clip_seconds'], ['4', '5', '6', '8'], true)) {
                    $errors[] = 'Invalid max clip length selected';
                }

                if (isset($settings['aspect_ratio']) && !in_array($settings['aspect_ratio'], ['16:9', '9:16'], true)) {
                    $errors[] = 'Invalid aspect ratio selected';
                }

                if (isset($settings['tts_voice']) && !in_array($settings['tts_voice'], $validVoices, true)) {
                    $errors[] = 'Invalid TTS voice selected';
                }

                if (isset($settings['caption_template']) && !in_array($settings['caption_template'], ['single_word_pop', 'modern_karaoke', 'classic_block', 'minimal_clean'], true)) {
                    $errors[] = 'Invalid caption template selected';
                }

                if (isset($settings['commentary_style']) && !in_array($settings['commentary_style'], ['energetic', 'sarcastic', 'dramatic', 'minimal'], true)) {
                    $errors[] = 'Invalid commentary style selected';
                }

                if (isset($settings['show_counter']) && !in_array($settings['show_counter'], ['yes', 'no'], true)) {
                    $errors[] = 'Invalid countdown counter option';
                }
                break;

            case 'ranking_moments_short':
                if (isset($settings['input_mode']) && !in_array($settings['input_mode'], ['youtube_url', 'upload'], true)) {
                    $errors[] = 'Invalid input mode';
                }

                if (isset($settings['ranking_title'])) {
                    $title = trim((string) $settings['ranking_title']);
                    if ($title === '') {
                        $errors[] = 'Ranking title is required';
                    } elseif (mb_strlen($title) > 60) {
                        $errors[] = 'Ranking title may not exceed 60 characters';
                    }
                }

                if (isset($settings['clip_count']) && !in_array((string) $settings['clip_count'], ['3', '4', '5'], true)) {
                    $errors[] = 'Number of moments must be between 3 and 5';
                }

                if (isset($settings['ranking_order']) && !in_array($settings['ranking_order'], ['countdown', 'top_first'], true)) {
                    $errors[] = 'Invalid play order selected';
                }

                if (isset($settings['caption_template']) && !in_array($settings['caption_template'], ['single_word_pop', 'modern_karaoke', 'classic_block', 'minimal_clean'], true)) {
                    $errors[] = 'Invalid caption template selected';
                }
                break;
        }

        return $errors;
    }

    /**
     * Get estimated processing time in seconds.
     */
    public static function getEstimatedProcessingTime(string $templateType, float $videoDuration): int
    {
        $multipliers = [
            'yt_automation_short' => 2.5,
            'ai_image_based_shorts' => 4.0,
            'ai_horror_shorts' => 4.5,
            'yt_gameplay_short' => 3.5,  // Download + transcribe + clip select + compose
            'yt_compilation_short' => 5.0,  // Multi-download + scene detect + transcribe + TTS + per-clip render
            'ranking_moments_short' => 4.0,  // Download + transcribe + rank + per-clip whisper + overlay burn
        ];

        $multiplier = $multipliers[$templateType] ?? 1.0;
        return (int) ($videoDuration * $multiplier);
    }
}
