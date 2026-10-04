<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Webhooks salientes de automatización (URL que controla el tenant):
    // OutboundUrlGuard exige https salvo que esto lo permita (sólo pensado
    // para local/testing).
    'outbound_webhooks' => [
        'allow_http' => (bool) env('OUTBOUND_WEBHOOKS_ALLOW_HTTP', in_array(env('APP_ENV'), ['local', 'testing'], true)),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Twilio de plataforma (SMS / WhatsApp / voz): SAM opera la mensajería
    // centralmente y la factura como servicio; los tenants no configuran nada.
    // El `config_json` de un canal puede overridear estos valores llave a llave.
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'sms_from' => env('TWILIO_SMS_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'voice_from' => env('TWILIO_VOICE_FROM'),
        // Public URL Twilio posts message/call status to. Defaults to the
        // `webhooks.twilio.status` route; set it when APP_URL is not the
        // public host (proxy, tunnel). Non-public URLs are never sent —
        // the reconciler polls Twilio instead.
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
        // Public base URL (scheme + host) Twilio reaches SAM at, when it is
        // not what Laravel sees behind the proxy/TLS terminator. Webhook
        // signatures are validated against it (TwilioWebhookUrl); without it
        // a proxy turns every DTMF/inbound reply into a 403.
        'public_base_url' => env('TWILIO_PUBLIC_BASE_URL'),
        // Text-to-speech voice and pace for every call SAM places
        // (TwilioSpeech). Polly.Mia-Neural is Mexican Spanish with full SSML;
        // Polly.Mía-Generative sounds warmer but costs ~4x and is in beta.
        'tts_voice' => env('TWILIO_TTS_VOICE', 'Polly.Mia-Neural'),
        'tts_rate' => env('TWILIO_TTS_RATE', '90%'),
        // Dev-only simulated Twilio (fake SIDs, deterministic outcomes).
        // Ignored in production.
        'sandbox' => (bool) env('TWILIO_SANDBOX', false),
        // Margin over Twilio's real cost billed to tenants (cost-plus).
        'markup_percent' => (float) env('TWILIO_MARKUP_PERCENT', 30),
        // Fallback USD prices when Twilio never reports a price for a
        // terminal resource within 24 h (charge marked as estimated).
        'estimated_prices' => [
            'sms_segment' => (float) env('TWILIO_ESTIMATED_SMS_SEGMENT_USD', 0.0079),
            'whatsapp_message' => (float) env('TWILIO_ESTIMATED_WHATSAPP_MESSAGE_USD', 0.005),
            'voice_minute' => (float) env('TWILIO_ESTIMATED_VOICE_MINUTE_USD', 0.014),
        ],
    ],

    'samsara' => [
        'base_url' => env('SAMSARA_BASE_URL', 'https://api.samsara.com'),
        'timeout' => (int) env('SAMSARA_TIMEOUT', 15),
        // Max age (seconds) accepted for the X-Samsara-Timestamp signature; 0 disables the replay check.
        'webhook_tolerance_seconds' => (int) env('SAMSARA_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'auth_token' => env('CLOUDFLARE_AUTH_TOKEN'),
    ],

];
