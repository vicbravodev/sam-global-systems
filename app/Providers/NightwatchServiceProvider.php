<?php

namespace App\Providers;

use App\Support\NightwatchIngestBudget;
use App\Support\NightwatchPrivacy;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\Facades\Nightwatch;
use Throwable;

/**
 * Monitoreo de producción con Laravel Nightwatch (agente en compose.prod.yaml).
 * Aquí sólo vive la política de SAM: qué se redacta antes de salir, qué se
 * sabe del usuario y cuánto se envía al día. Muestreo y filtros por env:
 * config/nightwatch.php.
 */
class NightwatchServiceProvider extends ServiceProvider
{
    /** Si el agente cae, cada request fallaría: una línea por minuto basta. */
    private const int UNRECOVERABLE_LOG_INTERVAL_SECONDS = 60;

    private static ?int $lastUnrecoverableLoggedAt = null;

    public function register(): void
    {
        Nightwatch::handleUnrecoverableExceptionsUsing(self::reportUnrecoverable(...));
    }

    public function boot(): void
    {
        Nightwatch::user(NightwatchPrivacy::userDetails(...));
        Nightwatch::redactRequests(NightwatchPrivacy::redactRequest(...));
        Nightwatch::redactExceptions(NightwatchPrivacy::redactException(...));
        Nightwatch::redactOutgoingRequests(NightwatchPrivacy::redactOutgoingRequest(...));
        Nightwatch::redactCommands(NightwatchPrivacy::redactCommand(...));
        Nightwatch::redactMail(NightwatchPrivacy::redactMail(...));
        Nightwatch::redactCacheEvents(NightwatchPrivacy::redactCacheEvent(...));

        Event::listen(IngestingEvents::class, NightwatchIngestBudget::class);
    }

    /**
     * Fallo interno de Nightwatch (agente inalcanzable, token inválido): va al
     * canal `json`, nunca al de Nightwatch, que es el que está roto.
     */
    public static function reportUnrecoverable(Throwable $e): void
    {
        $now = time();

        if (self::$lastUnrecoverableLoggedAt !== null && $now - self::$lastUnrecoverableLoggedAt < self::UNRECOVERABLE_LOG_INTERVAL_SECONDS) {
            return;
        }

        self::$lastUnrecoverableLoggedAt = $now;

        SystemLog::failed(
            'observability.nightwatch.unrecoverable',
            'nightwatch_exception',
            calc: ['log_interval_seconds' => self::UNRECOVERABLE_LOG_INTERVAL_SECONDS],
            error: $e,
            channel: 'json',
        );
    }

    public static function resetUnrecoverableThrottle(): void
    {
        self::$lastUnrecoverableLoggedAt = null;
    }
}
