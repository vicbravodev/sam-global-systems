<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_DEFAULT', 'openai'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Clef (medición en sombra, temporal)
    |--------------------------------------------------------------------------
    |
    | Evalúa en paralelo, sin decidir nada, lo mismo que evaluó GPT, para
    | comparar. Se apaga sola pasada `shadow_until`. Spec:
    | docs/superpowers/specs/2026-10-03-clef-shadow-evaluation-design.md
    |
    */

    'clef' => [
        'enabled' => (bool) env('AI_CLEF_SHADOW_ENABLED', false),
        'shadow_until' => env('AI_CLEF_SHADOW_UNTIL'),
        'models' => ['clef', 'clef-flash'],
        'sample_rate' => (float) env('AI_CLEF_SHADOW_SAMPLE_RATE', 1.0),
        'send_images' => (bool) env('AI_CLEF_SHADOW_SEND_IMAGES', true),
        'max_images' => 4,
        'max_image_bytes' => 4 * 1024 * 1024,
        'max_total_image_bytes' => 8 * 1024 * 1024,
        'timeout_seconds' => 15,
        'pricing_per_million_input' => ['clef' => 0.24, 'clef-flash' => 0.09],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'models' => [
                'text' => [
                    'default' => env('OPENAI_TEXT_MODEL', 'gpt-5.4'),
                ],
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Pricing
    |--------------------------------------------------------------------------
    |
    | USD per 1M tokens, keyed by the model id as returned by the provider
    | API (`meta->model`). Used by `App\Domains\AI\Support\ModelPricing` to
    | estimate the cost persisted in `ai_inference_logs.cost_estimate`.
    | Models without an entry resolve to a cost of 0.0.
    |
    */

    'pricing' => [
        'gpt-5.4' => ['input' => 2.50, 'cached_input' => 0.25, 'output' => 15.00],
        'gpt-5.4-mini' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 4.50],
        'gpt-5.4-nano' => ['input' => 0.20, 'cached_input' => 0.02, 'output' => 1.25],
        'gpt-5.4-pro' => ['input' => 30.00, 'output' => 180.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Re-evaluation Coalescing
    |--------------------------------------------------------------------------
    |
    | Deferred media lands in bursts (a panic can upload a dozen-plus clips in
    | under a minute) and every assessed item requests a re-evaluation of its
    | event. Media-triggered `ReevaluateEventJob`s are unique per event and
    | delayed by this debounce window so a burst collapses into a single run
    | that sees every assessment present at execution time.
    |
    */

    'reevaluation' => [
        'media_debounce_seconds' => (int) env('AI_REEVALUATION_MEDIA_DEBOUNCE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Categories Excluded From AI Evaluation
    |--------------------------------------------------------------------------
    |
    | Event categories whose classification already comes authoritatively from
    | the provider (Samsara safety events: harsh braking, speeding, distraction,
    | drowsy, mobile usage…), or whose volume makes per-event AI analysis
    | wasteful (maintenance: the offline-asset watchdog emits one device_offline
    | per silent asset, so a fleet-wide outage floods hundreds of events and
    | can exhaust the tenant's monthly token quota, starving critical events
    | like panic). Skipped events are still persisted and feed correlation for
    | high-value incidents (panic, jamming). Resolved by
    | `App\Domains\AI\Support\AIEvaluationGate`.
    |
    */

    'skip_evaluation_categories' => ['safety', 'maintenance'],

    /*
    |--------------------------------------------------------------------------
    | Automation Level → Human Review Threshold
    |--------------------------------------------------------------------------
    |
    | The tenant's "Nivel de autonomía" (TenantAIProfile::automation_level)
    | sets the AI confidence below which the decision engine requires human
    | review (`confidence < threshold`). Conservative sits above 1.0 so every
    | AI decision is reviewed; assisted keeps the historical 0.5 (also the
    | level of tenants without a profile). Emergencies (panic, collision,
    | rollover) open their incident on the fast path and never reach this.
    | Resolved by `App\Domains\TenantConfig\Actions\ResolveTenantDecisionRules`.
    |
    */

    'automation_levels' => [
        'human_review_threshold' => [
            'conservative' => 1.01,
            'assisted' => 0.5,
            'semi_automatic' => 0.4,
            'highly_automated' => 0.3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Types Excluded From AI Evaluation
    |--------------------------------------------------------------------------
    |
    | Low-value event types skipped by event type code, whatever their
    | category. NOTE: the decision engine runs on `AIEvaluationCompleted`, so
    | a skipped event gets no decision and no incident (same as the skipped
    | categories above) — remove a type here if a tenant's decision rules
    | must act on it.
    |
    */

    /*
    | Tipos cuyo hecho ya lo establece una regla determinista de SAM: el motor
    | de reglas los resuelve como evento real sin llamar a la IA (no se paga),
    | y a diferencia de `skip_evaluation_event_types` SÍ dejan evaluación, así
    | que el motor de decisiones corre y abre el incidente.
    |
    | - after_hours_movement: se movió fuera del horario que configuró el
    |   cliente; la base y los sitios de cliente ya los descarta la propia
    |   regla (telematics.after_hours_safe_geofence_categories).
    */
    'rule_resolved_event_types' => ['after_hours_movement'],

    'skip_evaluation_event_types' => [
        'geofence_entry',
        'geofence_exit',
        'vehicle_idle',
        'driving_context',
        'defensive_driving',
        // 'unmapped' NO se omite a propósito: puede ser una alerta nueva del
        // proveedor aún sin regla de mapeo y no debe descartarse en silencio.
        'no_seatbelt',
        'hos_violation',
        'smoking_drinking',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant AI Quota
    |--------------------------------------------------------------------------
    |
    | Per-tenant guard against floods of non-critical events: monthly tokens
    | (in + out) and daily AI calls. Over quota, non-critical events fall back
    | to rules-only and their images are not sent to the vision model.
    | Critical-severity events ALWAYS reach the model regardless of quota.
    |
    */

    'copilot' => [
        // Turn-wide token budget (input + output across steps); beyond it the agent must answer without more tools.
        'max_turn_tokens' => (int) env('COPILOT_MAX_TURN_TOKENS', 60000),
    ],

    'quota' => [
        'monthly_token_limit' => (int) env('AI_QUOTA_MONTHLY_TOKEN_LIMIT', 5_000_000),
        'daily_call_limit' => (int) env('AI_QUOTA_DAILY_CALL_LIMIT', 2_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision (Media Assessment) Limits
    |--------------------------------------------------------------------------
    |
    | Images are validated by magic bytes (jpeg/png/webp/gif) and size before
    | any model call; invalid or oversize files are recorded as `low_quality`
    | at no cost. A burst of stills around a panic is capped per event so a
    | single alert cannot fan out into dozens of paid vision calls.
    |
    */

    'media' => [
        'max_image_bytes' => (int) env('AI_MEDIA_MAX_IMAGE_BYTES', 8 * 1024 * 1024),
        'max_images_per_event' => (int) env('AI_MEDIA_MAX_IMAGES_PER_EVENT', 8),

        // Provider media downloads (SecureMediaDownloader): https only, to
        // these hosts (suffix match or `*` glob; comma-separated in env),
        // streamed to a temp file under a hard size cap and timeout.
        'allowed_download_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'AI_MEDIA_ALLOWED_DOWNLOAD_HOSTS',
            'samsara.com,samsara-*.s3.amazonaws.com,amazonaws.com,cloudfront.net',
        ))), static fn (string $host): bool => $host !== '' && $host !== '0')),
        'max_download_bytes' => (int) env('AI_MEDIA_MAX_DOWNLOAD_BYTES', 200 * 1024 * 1024),
        'download_timeout' => (int) env('AI_MEDIA_DOWNLOAD_TIMEOUT', 120),
    ],

];
