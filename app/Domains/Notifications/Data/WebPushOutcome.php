<?php

namespace App\Domains\Notifications\Data;

/**
 * Resultado por suscripción. `expired` = el servicio de push respondió 404/410:
 * el navegador revocó la suscripción y hay que borrarla.
 */
final readonly class WebPushOutcome
{
    public function __construct(
        public int $subscriptionId,
        public bool $success,
        public bool $expired,
        public ?int $statusCode,
        public ?string $reason,
    ) {}
}
