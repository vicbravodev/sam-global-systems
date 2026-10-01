<?php

namespace App\Domains\Automation\Actions;

use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Support\SystemLog;

/**
 * Confirmación humana de una ejecución `pending` (spec 12 §10.3).
 *
 * La transición `pending` → `queued` es un UPDATE condicional: si el barrido
 * de caducidad ({@see ExpireUnconfirmedActions}) o una cancelación ganó la
 * carrera, no se encola nada. Una ejecución cuyo plazo ya venció se rechaza
 * aunque el barrido aún no haya pasado (y se expira en ese momento), así el
 * TTL es exacto y no depende de la cadencia del scheduler.
 */
class ConfirmActionExecution
{
    public const string CONFIRMED = 'confirmed';

    public const string NOT_PENDING = 'not_pending';

    public const string EXPIRED = 'expired';

    public function __construct(
        private readonly ExpireUnconfirmedActions $expireUnconfirmedActions,
    ) {}

    /**
     * @return self::CONFIRMED|self::NOT_PENDING|self::EXPIRED
     */
    public function execute(ActionExecution $execution): string
    {
        $input = [
            'team_id' => $execution->team_id,
            'action_execution_id' => $execution->id,
            'action_type' => $execution->action_type->value,
            'execution_mode' => $execution->execution_mode?->value,
        ];

        if ($execution->status !== ActionExecutionStatus::Pending) {
            return $this->reject($execution, $input);
        }

        $ttlSeconds = $this->expireUnconfirmedActions->ttlSeconds($execution->team_id);
        $now = now();
        $cutoff = $now->copy()->subSeconds($ttlSeconds);

        if ($this->expireUnconfirmedActions->isPastTtl($execution, $ttlSeconds, $now)) {
            $this->expireUnconfirmedActions->expire($execution, $ttlSeconds, $cutoff, $now);

            return $this->reject($execution->refresh(), $input);
        }

        $query = ActionExecution::query()
            ->where('team_id', $execution->team_id)
            ->whereKey($execution->id)
            ->where('status', ActionExecutionStatus::Pending->value);

        // Mismo plazo que el barrido: la confirmación no puede colarse en el
        // instante en que vence.
        if ($ttlSeconds > 0 && $execution->execution_mode === ExecutionMode::RequiresConfirmation) {
            $query->where('created_at', '>', $cutoff);
        }

        $affected = $query->update(['status' => ActionExecutionStatus::Queued->value]);

        if ($affected === 0) {
            return $this->reject($execution->refresh(), $input, raceLost: true);
        }

        ExecuteActionJob::dispatch($execution->id);

        SystemLog::ok('automation.action.confirmed', input: $input, calc: [
            'ttl_seconds' => $ttlSeconds,
        ], result: [
            'status' => ActionExecutionStatus::Queued->value,
            'job_requested' => true,
        ]);

        $execution->refresh();

        return self::CONFIRMED;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return self::NOT_PENDING|self::EXPIRED
     */
    private function reject(ActionExecution $execution, array $input, bool $raceLost = false): string
    {
        $expired = $execution->status === ActionExecutionStatus::Cancelled
            && $execution->error_message === ExpireUnconfirmedActions::EXPIRED_MESSAGE;

        SystemLog::skipped(
            'automation.action.confirm_rejected',
            reason: $expired ? 'confirmation_expired' : 'not_pending',
            input: $input,
            calc: ['race_lost' => $raceLost],
            result: ['current_status' => $execution->status->value],
        );

        return $expired ? self::EXPIRED : self::NOT_PENDING;
    }
}
