<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecordUsageEvent
{
    public function execute(
        int $teamId,
        string $meterCode,
        int $quantity,
        string $eventKey,
        ?array $metadata = null,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        $this->record($teamId, $meterCode, $quantity, $eventKey, $metadata, $occurredAt);
    }

    /**
     * Igual que `execute()`, pero dice si ESTE llamado insertó la fila
     * (`insertOrIgnore > 0`) o si la `event_key` ya existía.
     */
    public function record(
        int $teamId,
        string $meterCode,
        int $quantity,
        string $eventKey,
        ?array $metadata = null,
        ?DateTimeInterface $occurredAt = null,
    ): bool {
        return TenantContext::for($teamId, function () use ($teamId, $meterCode, $quantity, $eventKey, $metadata, $occurredAt): bool {
            // Nunca `$metadata` en el log: la `event_key` ya identifica el uso.
            $logInput = ['team_id' => $teamId, 'meter_code' => $meterCode, 'event_key' => $eventKey];

            try {
                $meter = $this->resolveMeter($meterCode);
            } catch (ModelNotFoundException $e) {
                SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: [...$logInput, 'stage' => 'record_usage']);

                throw $e;
            }

            $occurredAt = $occurredAt ?? now();

            $billingPeriodKey = $this->buildBillingPeriodKey($meter, $occurredAt);
            $occurredAtIso = CarbonImmutable::instance($occurredAt)->toIso8601String();

            $inserted = UsageEvent::query()->insertOrIgnore([
                'team_id' => $teamId,
                'usage_meter_id' => $meter->id,
                'event_key' => $eventKey,
                'quantity' => $quantity,
                'metadata_json' => $metadata ? json_encode($metadata) : null,
                'occurred_at' => $occurredAt,
                'billing_period_key' => $billingPeriodKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                UsageRecorded::dispatch($teamId, $meterCode, $quantity, $eventKey);

                $resetPeriod = $meter->reset_period?->value;

                DB::afterCommit(fn () => SystemLog::ok(
                    'billing.usage.recorded',
                    input: $logInput,
                    calc: [
                        'quantity' => $quantity,
                        'reset_period' => $resetPeriod,
                        'occurred_at' => $occurredAtIso,
                        'billing_period_key' => $billingPeriodKey,
                    ],
                    result: ['recorded' => true],
                ));
            } else {
                // Que este insert no escribió nada es cierto aunque la transacción revierta.
                SystemLog::skipped(
                    'billing.usage.duplicate_ignored',
                    reason: 'event_key_exists',
                    input: $logInput,
                    calc: ['quantity' => $quantity, 'billing_period_key' => $billingPeriodKey],
                );
            }

            return $inserted > 0;
        });
    }

    private function resolveMeter(string $code): UsageMeter
    {
        return Cache::remember(
            "usage_meter:{$code}",
            3600,
            fn () => UsageMeter::where('code', $code)->firstOrFail(),
        );
    }

    private function buildBillingPeriodKey(UsageMeter $meter, DateTimeInterface $occurredAt): string
    {
        return match ($meter->reset_period) {
            ResetPeriod::Monthly => $occurredAt->format('Y-m'),
            ResetPeriod::Daily => $occurredAt->format('Y-m-d'),
            default => $occurredAt->format('Y-m'),
        };
    }
}
