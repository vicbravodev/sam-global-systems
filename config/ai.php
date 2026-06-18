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
        'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
        'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
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
    | drowsy, mobile usage…). Running the AI pipeline on them is redundant and
    | paid, so they skip evaluation entirely — but they are still persisted and
    | feed correlation for high-value incidents (panic, jamming). Resolved by
    | `App\Domains\AI\Support\AIEvaluationGate`.
    |
    */

    'skip_evaluation_categories' => ['safety'],

];
