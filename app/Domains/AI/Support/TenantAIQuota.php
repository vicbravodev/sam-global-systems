<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\TenantContext;

/**
 * Tenant AI quota guard shared by text and vision evaluation.
 *
 * The quota only protects against floods of non-critical events (e.g. a
 * fleet-wide device_offline storm): a critical-severity event ALWAYS reaches
 * the model, whatever the tenant has consumed. Two limits apply, both from
 * `config('ai.quota')` via `ResolveTenantAIProfile`: monthly tokens (in + out)
 * and daily AI calls (`ai_calls` usage events since the start of today).
 */
class TenantAIQuota
{
    public const string CRITICAL_SEVERITY = 'critical';

    public function __construct(
        private readonly ResolveTenantAIProfile $resolveTenantProfile,
    ) {}

    public function isCritical(NormalizedEvent $event): bool
    {
        $event->loadMissing('eventSeverity');

        return $event->eventSeverity?->code === self::CRITICAL_SEVERITY;
    }

    /**
     * True when the model must NOT be called for this event: the tenant is
     * over quota and the event is not critical.
     */
    public function blocks(NormalizedEvent $event, ?TenantAIProfileData $profile = null): bool
    {
        if ($this->isCritical($event)) {
            return false;
        }

        $profile ??= $this->resolveTenantProfile->execute((int) $event->team_id);

        return $this->exceeded((int) $event->team_id, $profile);
    }

    public function exceeded(int $teamId, TenantAIProfileData $profile): bool
    {
        return TenantContext::for($teamId, fn (): bool => $this->monthlyTokensExceeded($teamId, $profile->monthlyTokenLimit)
            || $this->dailyCallsExceeded($teamId, $profile->dailyCallLimit));
    }

    private function monthlyTokensExceeded(int $teamId, int $monthlyLimit): bool
    {
        $meterIds = UsageMeter::query()->whereIn('code', ['ai_tokens_in', 'ai_tokens_out'])->pluck('id');

        if ($meterIds->isEmpty()) {
            return false;
        }

        $consumed = (int) UsageEvent::query()
            ->where('team_id', $teamId)
            ->whereIn('usage_meter_id', $meterIds)
            ->where('billing_period_key', now()->format('Y-m'))
            ->sum('quantity');

        return $consumed >= $monthlyLimit;
    }

    private function dailyCallsExceeded(int $teamId, int $dailyLimit): bool
    {
        $meterId = UsageMeter::query()->where('code', 'ai_calls')->value('id');

        if ($meterId === null) {
            return false;
        }

        $calls = (int) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('usage_meter_id', $meterId)
            ->where('occurred_at', '>=', now()->startOfDay())
            ->sum('quantity');

        return $calls >= $dailyLimit;
    }
}
