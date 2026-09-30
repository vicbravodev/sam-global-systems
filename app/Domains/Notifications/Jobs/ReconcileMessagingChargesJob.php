<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Support\JobFailureReporter;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Twilio\Exceptions\TwilioException;

/**
 * Red de seguridad del feedback de Twilio y fuente del precio real.
 *
 * Cada 5 min toma los recursos Twilio no finalizados cuya revisión toca
 * (`next_check_at`, backoff exponencial por `check_attempts`), consulta
 * `messages($sid)` / `calls($sid)` y:
 *
 *   1. aplica el estado con {@see ApplyTwilioStatusUpdate} (cubre status
 *      callbacks perdidos o imposibles, p.ej. en local);
 *   2. si el recurso es terminal y Twilio ya reporta precio → lo cierra con
 *      el costo real y lo mide ({@see FinalizeMessagingCharge});
 *   3. terminal sin cargo (fallo antes de salir, llamada no contestada) →
 *      cierra con 0; terminal sin precio tras 24 h → costo estimado.
 *
 * Trabajo de plataforma que cruza tenants: selecciona sin tenant y procesa
 * cada fila dentro del suyo (§2.1).
 */
class ReconcileMessagingChargesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_SIZE = 200;

    public const ESTIMATE_AFTER_HOURS = 24;

    public const GIVE_UP_AFTER_HOURS = 72;

    public const MAX_BACKOFF_MINUTES = 60;

    /**
     * Ramas de {@see reconcile()}; el resumen del recorrido las cuenta.
     *
     * @var list<string>
     */
    public const BRANCHES = [
        'not_found_at_provider', 'priced', 'free_status', 'estimated_after_hours',
        'gave_up_priced', 'gave_up_estimated', 'rescheduled',
    ];

    /**
     * Twilio no cobra estos estados terminales.
     *
     * @var array<string, list<string>>
     */
    public const FREE_STATUSES = [
        'message' => ['failed', 'canceled'],
        'call' => ['busy', 'no-answer', 'failed', 'canceled'],
    ];

    public int $tries = 1;

    /** Must stay below the `redis` retry_after (240 s) or the job is re-delivered mid-run. */
    public int $timeout = 220;

    /** Stop taking new charges after this; the rest stay due for the next run. */
    public const TIME_BUDGET_SECONDS = 180;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(
        TwilioMessenger $messenger,
        TwilioVoiceCaller $caller,
        ApplyTwilioStatusUpdate $applyStatus,
        FinalizeMessagingCharge $finalize,
    ): void {
        $due = TenantContext::withoutTenant(fn () => MessagingCharge::query()
            ->whereNull('finalized_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->limit(self::BATCH_SIZE)
            ->get());

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $branchCounts = array_fill_keys(array_map(fn (string $branch) => "{$branch}_count", self::BRANCHES), 0);
        $processed = 0;
        $failed = 0;
        $budgetExhausted = false;

        foreach ($due as $charge) {
            if (microtime(true) >= $deadline) {
                $budgetExhausted = true;

                break;
            }

            $processed++;

            $branch = TenantContext::for($charge->team_id, function () use ($charge, $messenger, $caller, $applyStatus, $finalize): ?string {
                try {
                    return $this->reconcile($charge, $messenger, $caller, $applyStatus, $finalize);
                } catch (\Throwable $e) {
                    $nextCheck = $this->scheduleNextCheck($charge);

                    SystemLog::degraded('billing.messaging_charge.reconcile_failed', reason: 'provider_error', input: [
                        'team_id' => $charge->team_id,
                        'charge_id' => $charge->id,
                        'provider_sid' => $charge->provider_sid,
                        'error_class' => class_basename($e),
                        'provider_error_code' => $e->getCode(),
                    ], calc: $nextCheck, error: $e);

                    return null;
                }
            });

            if ($branch === null) {
                $failed++;
            } else {
                $branchCounts["{$branch}_count"]++;
            }
        }

        // Fuera de todo tenant: sólo conteos, nunca ids.
        SystemLog::ok('billing.messaging_reconcile.completed', calc: [
            'batch_size' => self::BATCH_SIZE,
            'time_budget_seconds' => self::TIME_BUDGET_SECONDS,
        ], result: [
            'charges_due_count' => $due->count(),
            'charges_processed_count' => $processed,
            'charges_failed_count' => $failed,
            'budget_exhausted' => $budgetExhausted,
            'branch_counts' => $branchCounts,
        ], debug: $due->isEmpty());
    }

    private function reconcile(
        MessagingCharge $charge,
        TwilioMessenger $messenger,
        TwilioVoiceCaller $caller,
        ApplyTwilioStatusUpdate $applyStatus,
        FinalizeMessagingCharge $finalize,
    ): string {
        $input = [
            'team_id' => $charge->team_id,
            'charge_id' => $charge->id,
            'resource_type' => $charge->resource_type->value,
        ];

        try {
            $resource = $charge->resource_type === MessagingResourceType::Call
                ? $caller->fetchCall($charge->provider_sid)
                : $messenger->fetchMessage($charge->provider_sid);
        } catch (TwilioException $e) {
            if ($e->getCode() === 20404) {
                // Twilio no conoce el SID (borrado o nunca creado): no hay nada
                // que cobrar ni que seguir consultando.
                $charge->forceFill(['status' => $charge->status ?? 'not_found'])->save();
                $finalize->withoutCost($charge);

                SystemLog::ok('billing.messaging_charge.reconciled', input: $input, calc: [
                    'branch' => 'not_found_at_provider',
                    'provider_error_code' => 20404,
                ]);

                return 'not_found_at_provider';
            }

            throw $e;
        }

        $status = strtolower((string) ($resource->status ?? ''));
        $errorCode = isset($resource->errorCode) && $resource->errorCode !== null ? (string) $resource->errorCode : null;
        $duration = isset($resource->duration) && is_numeric($resource->duration) ? (int) $resource->duration : null;
        $segments = isset($resource->numSegments) && is_numeric($resource->numSegments) ? (int) $resource->numSegments : null;

        $applyStatus->execute(
            $charge,
            $status,
            $errorCode,
            durationSeconds: $charge->resource_type === MessagingResourceType::Call ? $duration : null,
            segments: $charge->resource_type === MessagingResourceType::Message ? $segments : null,
            source: 'poll',
        );

        $charge->refresh();
        $charge->last_checked_at = now();

        $price = isset($resource->price) && is_numeric($resource->price) ? (string) $resource->price : null;
        $priceUnit = isset($resource->priceUnit) && is_string($resource->priceUnit) ? $resource->priceUnit : null;
        $age = $charge->created_at ?? now();
        $terminal = ApplyTwilioStatusUpdate::isTerminal($charge->resource_type, $status);
        $nextCheck = [];

        if ($terminal) {
            if ($price !== null) {
                $finalize->withProviderPrice($charge, $price, $priceUnit);
                $branch = 'priced';
            } elseif (in_array($status, self::FREE_STATUSES[$charge->resource_type->value], true)) {
                $finalize->withoutCost($charge);
                $branch = 'free_status';
            } elseif ($age->lte(now()->subHours(self::ESTIMATE_AFTER_HOURS))) {
                $finalize->withEstimate($charge);
                $branch = 'estimated_after_hours';
            } else {
                $nextCheck = $this->scheduleNextCheck($charge);
                $branch = 'rescheduled';
            }
        } elseif ($age->lte(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            // Some carriers never confirm past `sent`; Twilio still bills it.
            if ($price !== null) {
                $finalize->withProviderPrice($charge, $price, $priceUnit);
                $branch = 'gave_up_priced';
            } else {
                $finalize->withEstimate($charge);
                $branch = 'gave_up_estimated';
            }
        } else {
            $nextCheck = $this->scheduleNextCheck($charge);
            $branch = 'rescheduled';
        }

        SystemLog::ok('billing.messaging_charge.reconciled', input: $input, calc: [
            'branch' => $branch,
            'provider_status' => LoggableCode::guard($status),
            'terminal' => $terminal,
            'price_present' => $price !== null,
            'age_hours' => (int) $age->diffInHours(now()),
            'estimate_after_hours' => self::ESTIMATE_AFTER_HOURS,
            'give_up_after_hours' => self::GIVE_UP_AFTER_HOURS,
        ], result: $nextCheck, debug: $branch === 'rescheduled');

        return $branch;
    }

    /**
     * @return array{check_attempts: int, delay_minutes: int, next_check_at: string, formula: string}
     */
    private function scheduleNextCheck(MessagingCharge $charge): array
    {
        $attempts = $charge->check_attempts + 1;
        $delay = min(self::MAX_BACKOFF_MINUTES, 2 ** min($attempts, 6));
        $nextCheckAt = now()->addMinutes($delay);

        $charge->forceFill([
            'check_attempts' => $attempts,
            'last_checked_at' => now(),
            'next_check_at' => $nextCheckAt,
        ])->save();

        return [
            'check_attempts' => $attempts,
            'delay_minutes' => $delay,
            'next_check_at' => $nextCheckAt->toIso8601String(),
            'formula' => 'delay_minutes = min(60, 2 ** min(check_attempts, 6))',
        ];
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
