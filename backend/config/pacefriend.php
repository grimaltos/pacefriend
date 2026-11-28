<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sliding Window & Diagnostic Thresholds
    |--------------------------------------------------------------------------
    */
    'clean_laps_window' => (int) env('PACEFRIEND_CLEAN_LAPS_WINDOW', 5),
    'outlier_pace_factor' => (float) env('PACEFRIEND_OUTLIER_PACE_FACTOR', 1.08),
    'min_chronic_occurrences' => (int) env('PACEFRIEND_MIN_CHRONIC_OCCURRENCES', 3),
    'min_corner_loss_ms' => (int) env('PACEFRIEND_MIN_CORNER_LOSS_MS', 80),
    'normalization_grid_points' => 250, // Every 0.4% of track (0.000 .. 0.996)

    /*
    |--------------------------------------------------------------------------
    | Dynamic Track-Condition Reference Matching
    |--------------------------------------------------------------------------
    */
    'matching' => [
        'track_temp_weight' => 0.75,
        'air_temp_weight' => 0.25,
        'thermal_band_c' => 3.0,
        'max_comparable_temp_delta_c' => (float) env('PACEFRIEND_MAX_TEMP_DELTA_C', 7.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Natural AI Voice Synthesis (Edge Neural TTS + ElevenLabs + Offline Fallback)
    |--------------------------------------------------------------------------
    */
    'voice' => [
        'driver' => env('PACEFRIEND_VOICE_DRIVER', 'edge'), // edge | elevenlabs | local
        'edge' => [
            'default_voice' => env('EDGE_TTS_VOICE', 'es-ES-AlvaroNeural'),
            'rate' => env('EDGE_TTS_RATE', '+4%'),
            'voices' => [
                'es-ES-AlvaroNeural' => 'Álvaro (Ingeniero · España)',
                'es-ES-XimenaNeural' => 'Ximena (Ingeniera · Natural)',
                'es-ES-ElviraNeural' => 'Elvira (Ingeniera · España)',
            ],
        ],
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'voice_id' => env('ELEVENLABS_VOICE_ID', 'pNInz6obpgDQGcFmaJgB'),
            'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
            'stability' => 0.55,
            'similarity_boost' => 0.80,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PRO Post-Session Report LLM Narrator (Gemini / OpenAI / Local Engineer)
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'driver' => env('PACEFRIEND_LLM_DRIVER', 'local'), // local | gemini | openai
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Natural Voice Push-To-Talk Race Engineer (Groq Whisper + Llama 3.3 + Local Hybrid)
    |--------------------------------------------------------------------------
    */
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => env('GROQ_API_URL', 'https://api.groq.com/openai/v1'),
        'stt_model' => env('GROQ_STT_MODEL', 'whisper-large-v3-turbo'),
        'chat_model' => env('GROQ_CHAT_MODEL', 'llama-3.3-70b-versatile'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garage 61 Developer API & Progressive Autopilot
    |--------------------------------------------------------------------------
    */
    'garage61' => [
        'base_url' => env('GARAGE61_API_URL', 'https://garage61.net/api/v1'),
        'max_lap_age_days' => (int) env('GARAGE61_MAX_LAP_AGE_DAYS', 90),
        'autopilot_temp_band_c' => 2.5,
        'progressive_min_faster_ms' => 600,
        'progressive_max_faster_ms' => 1600,
        'level_up_threshold_ms' => 250,
        'oauth' => [
            'client_id' => env('GARAGE61_CLIENT_ID'),
            'client_secret' => env('GARAGE61_CLIENT_SECRET'),
            'redirect_uri' => env('GARAGE61_REDIRECT_URI'),
            'authorize_url' => env('GARAGE61_AUTHORIZE_URL', 'https://garage61.net/app/account/oauth'),
            'token_url' => env('GARAGE61_TOKEN_URL', 'https://garage61.net/api/oauth/token'),
            'scopes' => env('GARAGE61_OAUTH_SCOPES', 'driving_data analyses'),
        ],
    ],
];
