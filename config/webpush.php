<?php

/*
 * Avisos al dispositivo (Web Push, VAPID). Las llaves son de la plataforma,
 * no de cada tenant: se generan una vez con `php artisan sam:vapid-keys`.
 * Sin llaves el driver falla con `not_configured` y la app no ofrece activar
 * avisos en el dispositivo.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Servicios de push de los navegadores. El servidor sólo hace POST a
    // estos hosts: un endpoint arbitrario sería una puerta a SSRF.
    'allowed_hosts' => [
        'fcm.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        'web.push.apple.com',
        '*.push.apple.com',
    ],
];
