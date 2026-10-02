<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Models\Incident;
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
 * Red de seguridad de la escalera de SLA: cada minuto busca incidentes cuyo
 * paso pendiente (`next_escalation_at`) venció hace más de GRACE_SECONDS y
 * re-despacha CheckIncidentAcknowledgementJob con el estado persistido.
 *
 * Cubre lo que antes apagaba la escalera en silencio: un job diferido perdido
 * (Valkey reiniciado, `horizon:clear`), uno que agotó sus reintentos, o uno
 * que llegó antes de tiempo. Un re-despacho de más es inocuo: el watchdog
 * bloquea la fila y descarta el paso si ya se disparó o es de otra generación.
 */
class SweepOverdueEscalationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Margen para no competir con el job diferido que llega a su hora. */
    public const int GRACE_SECONDS = 90;

    public const int BATCH = 500;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('incidents');
    }

    public function handle(): void
    {
        $cutoff = now()->subSeconds(self::GRACE_SECONDS);

        // Barrido de plataforma: cruza tenants a propósito (sólo id, tenant y
        // estado de la escalera) y re-despacha cada paso dentro del contexto
        // de su propio tenant. Ver §2.1.
        $overdue = TenantContext::withoutTenant(fn () => Incident::query()
            ->select(['id', 'team_id', 'escalation_epoch', 'escalation_level', 'escalation_attempt'])
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', $cutoff)
            ->whereNull('escalation_exhausted_at')
            ->whereNull('acknowledged_at')
            ->whereNull('claimed_by_user_id')
            ->whereHas('status', fn ($query) => $query->where('is_terminal', false))
            ->orderBy('next_escalation_at')
            ->limit(self::BATCH)
            ->get());

        foreach ($overdue as $incident) {
            TenantContext::for($incident->team_id, fn () => CheckIncidentAcknowledgementJob::dispatch(
                $incident->id,
                $incident->escalation_level,
                $incident->escalation_attempt,
                $incident->escalation_epoch,
            ));
        }

        // Lote lleno: puede haber más pasos atascados que los de este minuto
        // (p. ej. una cola caída). Se rescatan en los siguientes barridos,
        // del más viejo al más nuevo; queda a la vista.
        if ($overdue->count() >= self::BATCH) {
            SystemLog::degraded('incidents.escalation_sweep.completed', reason: 'batch_saturated', calc: ['batch' => self::BATCH], result: ['redispatched_count' => $overdue->count()]);
        }

        SystemLog::ok('incidents.escalation_sweep.completed',
            calc: ['grace_seconds' => self::GRACE_SECONDS, 'cutoff_at' => $cutoff->toIso8601String(), 'batch' => self::BATCH],
            result: [
                'redispatched_count' => $overdue->count(),
                'incident_ids' => $overdue->modelKeys(),
            ],
            debug: $overdue->isEmpty(),
        );
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
