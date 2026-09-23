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
        'ai_explainer_video'    => 100, // re-render price; see 'explainer' below
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

    /*
    |--------------------------------------------------------------------------
    | AI explainer pricing
    |--------------------------------------------------------------------------
    |
    | The explainer is billed in three parts rather than one flat render fee:
    |
    |  - Generating the storyboard charges its DURATION TIER, picked on the
    |    create page. The tier caps how long the video may be, which is what
    |    actually drives the cost (scenes, narration, render minutes).
    |  - The first successful render of a project is free; every render after
    |    that charges `rerender_cost`.
    |  - AI pictures the user ASKS for cost `ai_image_cost` each: "Generate
    |    with AI" on a slot, and the slots the AI visuals switch fills at render
    |    when the user turned it on. Pictures the pipeline draws on its own
    |    (cinematic flow-card art, math visuals it turns on by default, stock
    |    b-roll) are part of the storyboard price and never billed extra.
    |
    | Tier keys are stored on the project (settings.duration_tier), so keep
    | them stable; labels, limits and prices are safe to change.
    */
    'explainer' => [
        'duration_tiers' => [
            'short'  => ['label' => 'Up to 5 min',  'max_seconds' => 300, 'cost' => 100],
            'medium' => ['label' => 'Up to 10 min', 'max_seconds' => 600, 'cost' => 250],
            'long'   => ['label' => 'Up to 15 min', 'max_seconds' => 900, 'cost' => 350],
        ],
        'default_tier' => 'short',
        'free_renders' => 1,
        'rerender_cost' => 100,
        'ai_image_cost' => 25,
    ],
];
