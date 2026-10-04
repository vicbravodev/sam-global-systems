<?php

/*
 * Avisos al dispositivo (Web Push, VAPID). Las llaves son de la plataforma,
 * no de cada tenant: se generan una vez con `php artisan sam:vapid-keys`.
 * Sin llaves el canal no se ofrece y el driver falla con `not_configured`.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
