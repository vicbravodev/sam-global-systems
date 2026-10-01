<?php

namespace App\Domains\Automation\Actions;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Caducidad de las acciones que esperan confirmación humana (spec 12 §10.3 y
 * §13 "Confirmation Expiry"): una ejecución `requires_confirmation` que sigue
 * `pending` cuando su antigüedad alcanza el TTL del tenant
 * (`TenantAutomationPolicies::confirmationTtlSeconds`, 30 min por defecto)
 * pasa a `cancelled` con el motivo fijo {@see self::EXPIRED_MESSAGE}.
 *
 * La transición es un UPDATE condicional sobre el estado `pending`: si una
 * confirmación (o una cancelación) llegó antes, el UPDATE no toca nada y la
 * carrera la gana el humano. Por eso el barrido es idempotente y nunca pisa
 * una ejecución ya confirmada, ejecutada, fallida o cancelada.
 */
class ExpireUnconfirmedActions
{
    /** Motivo fijo en `error_message`: distingue la caducidad de una cancelación manual. */
    public const string EXPIRED_MESSAGE = 'Confirmation expired: nobody confirmed the action within its time limit.';

    private const int CHUNK = 100;

    public function __construct(
        private readonly TenantAutomationPoliciesResolver $policiesResolver,
    ) {}

    /**
     * Expira las ejecuciones pendientes de confirmación del tenant cuyo TTL
     * venció.
     *
     * @return array{candidates: int, expired: int, race_lost: int}
     */
    public function execute(int $teamId): array
    {
        return TenantContext::for($teamId, function () use ($teamId): array {
            $counts = ['candidates' => 0, 'expired' => 0, 'race_lost' => 0];
            $ttlSeconds = $this->ttlSeconds($teamId);

            if ($ttlSeconds <= 0) {
                SystemLog::skipped('automation.confirmation_sweep.tenant_swept', reason: 'ttl_disabled', input: ['team_id' => $teamId], calc: ['ttl_seconds' => $ttlSeconds]);

                return $counts;
            }

            $now = now();
            $cutoff = $now->copy()->subSeconds($ttlSeconds);

            ActionExecution::query()
                ->where('team_id', $teamId)
                ->where('status', ActionExecutionStatus::Pending->value)
                ->where('execution_mode', ExecutionMode::RequiresConfirmation->value)
                ->where('created_at', '<=', $cutoff)
                ->chunkById(self::CHUNK, function (Collection $executions) use (&$counts, $ttlSeconds, $cutoff, $now): void {
                    foreach ($executions as $execution) {
                        $counts['candidates']++;
                        $counts[$this->expire($execution, $ttlSeconds, $cutoff, $now) ? 'expired' : 'race_lost']++;
                    }
                });

            // Rutina (debug) salvo que algo haya expirado o se haya perdido una carrera.
            SystemLog::ok('automation.confirmation_sweep.tenant_swept', input: ['team_id' => $teamId], calc: [
                'ttl_seconds' => $ttlSeconds,
                'cutoff' => $cutoff->toIso8601String(),
            ], result: [
                'candidates_count' => $counts['candidates'],
                'expired_count' => $counts['expired'],
                'race_lost_count' => $counts['race_lost'],
            ], debug: $counts['candidates'] === 0);

            return $counts;
        });
    }

    /**
     * TTL vigente del tenant de la ejecución.
     */
    public function ttlSeconds(int $teamId): int
    {
        return $this->policiesResolver->resolve($teamId)->confirmationTtlSeconds;
    }

    /**
     * ¿Venció ya el plazo de confirmación? Vence al cumplir exactamente el
     * TTL (`created_at <= now - ttl`). Un TTL ≤ 0 desactiva la caducidad.
     */
    public function isPastTtl(ActionExecution $execution, int $ttlSeconds, ?CarbonInterface $now = null): bool
    {
        if ($ttlSeconds <= 0
            || $execution->execution_mode !== ExecutionMode::RequiresConfirmation
            || $execution->created_at === null) {
            return false;
        }

        return $execution->created_at->lte(($now ?? now())->copy()->subSeconds($ttlSeconds));
    }

    /**
     * Transición atómica `pending` → `cancelled` de una ejecución vencida.
     * Devuelve false si otra escritura cambió su estado antes (carrera
     * perdida): entonces no se toca nada.
     */
    public function expire(ActionExecution $execution, int $ttlSeconds, CarbonInterface $cutoff, CarbonInterface $now): bool
    {
        $input = [
            'team_id' => $execution->team_id,
            'action_execution_id' => $execution->id,
            'action_type' => $execution->action_type->value,
            'source_type' => $execution->source_type?->value,
        ];

        $ageSeconds = $execution->created_at === null
            ? null
            : (int) $execution->created_at->diffInSeconds($now, true);

        $affected = ActionExecution::query()
            ->where('team_id', $execution->team_id)
            ->whereKey($execution->id)
            ->where('status', ActionExecutionStatus::Pending->value)
            ->where('execution_mode', ExecutionMode::RequiresConfirmation->value)
            ->where('created_at', '<=', $cutoff)
            ->update([
                'status' => ActionExecutionStatus::Cancelled->value,
                'error_message' => self::EXPIRED_MESSAGE,
            ]);

        if ($affected === 0) {
            $current = ActionExecution::query()
                ->where('team_id', $execution->team_id)
                ->whereKey($execution->id)
                ->value('status');

            SystemLog::skipped('automation.action.expiry_skipped', reason: 'state_changed', input: $input, calc: [
                'ttl_seconds' => $ttlSeconds,
                'age_seconds' => $ageSeconds,
            ], result: [
                'current_status' => $current instanceof ActionExecutionStatus ? $current->value : (is_string($current) ? $current : null),
            ]);

            return false;
        }

        ActionExecutionLog::create([
            'action_execution_id' => $execution->id,
            'log_type' => ActionLogType::Warning,
            'message' => self::EXPIRED_MESSAGE,
            'payload_json' => [
                'reason' => 'confirmation_expired',
                'ttl_seconds' => $ttlSeconds,
                'age_seconds' => $ageSeconds,
                'expired_at' => $now->toIso8601String(),
            ],
        ]);

        // age_seconds >= ttl_seconds ⇒ expira.
        SystemLog::ok('automation.action.expired', input: $input, calc: [
            'ttl_seconds' => $ttlSeconds,
            'age_seconds' => $ageSeconds,
            'cutoff' => $cutoff->toIso8601String(),
        ], result: [
            'status' => ActionExecutionStatus::Cancelled->value,
        ]);

        return true;
    }
}
