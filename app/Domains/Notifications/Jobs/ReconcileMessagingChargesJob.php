<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Support\JobFailureReporter;
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

        foreach ($due as $charge) {
            if (microtime(true) >= $deadline) {
                break;
            }

            TenantContext::for($charge->team_id, function () use ($charge, $messenger, $caller, $applyStatus, $finalize) {
                try {
                    $this->reconcile($charge, $messenger, $caller, $applyStatus, $finalize);
                } catch (\Throwable $e) {
                    SystemLog::degraded('billing.messaging_charge.reconcile_failed', reason: 'provider_error', input: ['charge_id' => $charge->id, 'provider_sid' => $charge->provider_sid], error: $e);

                    $this->scheduleNextCheck($charge);
                }
            });
        }
    }

    private function reconcile(
        MessagingCharge $charge,
        TwilioMessenger $messenger,
        TwilioVoiceCaller $caller,
        ApplyTwilioStatusUpdate $applyStatus,
        FinalizeMessagingCharge $finalize,
    ): void {
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

                return;
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

        if (ApplyTwilioStatusUpdate::isTerminal($charge->resource_type, $status)) {
            if ($price !== null) {
                $finalize->withProviderPrice($charge, $price, $priceUnit);

                return;
            }

            if (in_array($status, self::FREE_STATUSES[$charge->resource_type->value], true)) {
                $finalize->withoutCost($charge);

                return;
            }

            if ($age->lte(now()->subHours(self::ESTIMATE_AFTER_HOURS))) {
                $finalize->withEstimate($charge);

                return;
            }
        } elseif ($age->lte(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            // Some carriers never confirm past `sent`; Twilio still bills it.
            $price !== null
                ? $finalize->withProviderPrice($charge, $price, $priceUnit)
                : $finalize->withEstimate($charge);

            return;
        }

        $this->scheduleNextCheck($charge);
    }

    private function scheduleNextCheck(MessagingCharge $charge): void
    {
        $attempts = $charge->check_attempts + 1;
        $delay = min(self::MAX_BACKOFF_MINUTES, 2 ** min($attempts, 6));

        $charge->forceFill([
            'check_attempts' => $attempts,
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($delay),
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
