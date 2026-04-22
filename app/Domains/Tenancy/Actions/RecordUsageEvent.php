<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

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
        $meter = $this->resolveMeter($meterCode);
        $occurredAt = $occurredAt ?? now();

        $billingPeriodKey = $this->buildBillingPeriodKey($meter, $occurredAt);

        $inserted = UsageEvent::withoutGlobalScopes()->insertOrIgnore([
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
        }
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
