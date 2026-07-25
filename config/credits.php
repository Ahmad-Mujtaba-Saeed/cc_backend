<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Per-template credit cost
    |--------------------------------------------------------------------------
    |
    | How many credits each template charges per video render. Tiered by how
    | expensive the template is to run (local-only edits cost less; templates
    | that call paid AI generation cost more). The `default` applies to any
    | template_type not listed here.
    |
    */
    'templates' => [
        'simple_video'          => 2,
        'yt_automation_short'   => 3,
        'yt_gameplay_short'     => 2,
        'yt_compilation_short'  => 3,
        'ranking_moments_short' => 3,
        'ai_image_based_shorts' => 5,
        'ai_horror_shorts'      => 5,
        'ai_explainer_video'    => 6,
        'template_a'            => 2,
        'template_b'            => 2,
        'template_c'            => 2,
    ],

    'default' => 3,

    /*
    | Aspect-variant bundle (explainer §10.6): one request renders 16:9 + 9:16
    | + 1:1. The render charge is multiplied by this factor when the project
    | has settings['aspect_variants'] enabled (three renders' worth of compute,
    | shared analysis/TTS/images).
    */
    'aspect_variants_multiplier' => 2.5,
];
