<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Entregas que se quedaron "pendientes" o "enviando" sin SID: el worker murió
 * (o se agotó su timeout) entre crear la fila y registrar el resultado, o un
 * resultado incierto cuyo job de resolución se perdió. Antes se quedaban así
 * para siempre: ni reintento, ni fallback, ni feedback — un aviso crítico
 * perdido en silencio.
 *
 * Cada 5 minutos, las que llevan más de STUCK_MINUTES quietas (y menos de
 * MAX_AGE_MINUTES: no se resucita lo viejo) pasan por ResolveUncertainDeliveryJob,
 * que las adopta si Twilio sí las creó o las marca fallidas para que el
 * reintento/fallback normal decida.
 */
class SweepStuckDeliveriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int STUCK_MINUTES = 10;

    /** Más viejo ya no es accionable (y el TTL de reintento es 30 min). */
    public const int MAX_AGE_MINUTES = 60;

    public const int BATCH = 200;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $stuckBefore = now()->subMinutes(self::STUCK_MINUTES);
        $notOlderThan = now()->subMinutes(self::MAX_AGE_MINUTES);

        // Barrido de plataforma: cruza tenants a propósito (sólo id, tenant e
        // intento) y despacha cada rescate dentro de su tenant. Ver §2.1.
        $stuck = TenantContext::withoutTenant(fn () => NotificationDelivery::query()
            ->select(['id', 'team_id'])
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Sending])
            ->whereNull('provider_message_id')
            ->where('updated_at', '<=', $stuckBefore)
            ->where('updated_at', '>=', $notOlderThan)
            ->orderBy('updated_at')
            ->limit(self::BATCH)
            ->get());

        foreach ($stuck as $delivery) {
            TenantContext::for($delivery->team_id, fn () => ResolveUncertainDeliveryJob::dispatch($delivery->id));
        }

        SystemLog::ok('notifications.stuck_sweep.completed',
            calc: [
                'stuck_minutes' => self::STUCK_MINUTES,
                'max_age_minutes' => self::MAX_AGE_MINUTES,
                'stuck_before_at' => $stuckBefore->toIso8601String(),
                'batch' => self::BATCH,
            ],
            result: ['rescued_count' => $stuck->count(), 'delivery_ids' => $stuck->modelKeys()],
            debug: $stuck->isEmpty(),
        );
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
