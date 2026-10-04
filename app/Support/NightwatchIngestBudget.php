<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Laravel\Nightwatch\Events\IngestingEvents;
use Throwable;

/**
 * Tope diario de eventos enviados a Nightwatch (`nightwatch.daily_event_cap`,
 * null = sin tope). Un flood (telemática cada 5 s, ráfaga de webhooks) no debe
 * agotar la cuota del plan y dejar a ciegas el resto del mes.
 *
 * Devolver `false` cancela el envío del lote. Si la caché no responde, el
 * lote pasa: perder el tope un rato es preferible a perder la observabilidad
 * justo cuando algo falla.
 */
final class NightwatchIngestBudget
{
    private const string KEY = 'nightwatch:ingested:';

    private const string LOGGED_KEY = 'nightwatch:cap_logged:';

    private const int TTL_SECONDS = 172_800;

    /** Un worker vive horas: la caída de la caché se narra una vez por proceso. */
    private static bool $outageLogged = false;

    public function __construct(private ?Repository $cache = null) {}

    public function __invoke(IngestingEvents $event): ?bool
    {
        $cap = config('nightwatch.daily_event_cap');

        if (! is_numeric($cap) || (int) $cap <= 0) {
            return null;
        }

        $cap = (int) $cap;
        $day = now()->format('Ymd');

        try {
            $cache = $this->cache ??= Cache::store();
            $cache->add(self::KEY.$day, 0, self::TTL_SECONDS);
            $ingested = (int) $cache->increment(self::KEY.$day, $event->eventCount());

            if ($ingested <= $cap) {
                return null;
            }

            if ($cache->add(self::LOGGED_KEY.$day, true, self::TTL_SECONDS)) {
                // Canal `json`: nunca de vuelta a Nightwatch, que es justo lo que se cortó.
                SystemLog::skipped(
                    'observability.nightwatch.ingest_capped',
                    'daily_cap_reached',
                    input: ['day' => $day],
                    calc: ['daily_event_cap' => $cap, 'ingested_today' => $ingested],
                    channel: 'json',
                );
            }

            return false;
        } catch (Throwable $e) {
            if (! self::$outageLogged) {
                self::$outageLogged = true;

                SystemLog::degraded(
                    'observability.nightwatch.budget_unavailable',
                    'cache_unavailable',
                    input: ['day' => $day],
                    calc: ['daily_event_cap' => $cap],
                    error: $e,
                    channel: 'json',
                );
            }

            return null;
        }
    }

    public static function resetOutageFlag(): void
    {
        self::$outageLogged = false;
    }
}
