<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Actions\ExpireUnconfirmedActions;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Models\ActionExecution;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Barrido de caducidad de confirmaciones (spec 12 §13 "Confirmation
 * Expiry"): cada minuto busca los tenants con acciones `requires_confirmation`
 * aún `pending` y expira, dentro del contexto de cada uno, las que superaron
 * el TTL de su tenant. Idempotente: una segunda pasada no encuentra nada.
 */
class ExpireUnconfirmedActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(ExpireUnconfirmedActions $expireUnconfirmedActions): void
    {
        // Barrido de plataforma: descubre los tenants a propósito (sólo sus
        // ids) y expira cada uno dentro de su propio contexto. Ver §2.1.
        $teamIds = TenantContext::withoutTenant(fn () => ActionExecution::query()
            ->where('status', ActionExecutionStatus::Pending->value)
            ->where('execution_mode', ExecutionMode::RequiresConfirmation->value)
            ->whereNotNull('team_id')
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(fn ($teamId): int => (int) $teamId)
            ->all());

        $totals = ['candidates' => 0, 'expired' => 0, 'race_lost' => 0];

        foreach ($teamIds as $teamId) {
            $counts = $expireUnconfirmedActions->execute($teamId);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }
        }

        SystemLog::ok('automation.confirmation_sweep.completed', result: [
            'teams_count' => count($teamIds),
            'candidates_count' => $totals['candidates'],
            'expired_count' => $totals['expired'],
            'race_lost_count' => $totals['race_lost'],
        ], debug: $totals['candidates'] === 0);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
