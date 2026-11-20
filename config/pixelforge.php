<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | Use a separate API key per environment (local, production). Each key has
    | its own webhook URL, signing secret and usage report, so each
    | environment only receives and reconciles its own generations.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhooks signed longer ago than this, to stop replays.
    'webhook_tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    'timeout_seconds' => 15,

    // Pixelforge allows 3 queued or processing generations per account at once.
    // We hold new submissions back locally rather than burn requests on 429s.
    'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),

    // How long to trust the cached GET /v1/pricing response.
    'pricing_cache_seconds' => 600,

    // Poll a generation if we have not heard about it (webhook or poll) for this long.
    'poll_after_seconds' => 45,

];
