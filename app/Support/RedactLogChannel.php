<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * `tap` de config/logging.php: engancha la redacción a un canal. Se registra
 * antes que el processor del Context de Laravel, y Monolog ejecuta los
 * processors del último al primero, así que la redacción ve también `extra`.
 */
final class RedactLogChannel
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveLogData);
        }
    }
}
