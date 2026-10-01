<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\SystemLog;
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
     * over quota and the event is not critical. `$purpose` (text|vision) only
     * labels the log line.
     */
    public function blocks(NormalizedEvent $event, ?TenantAIProfileData $profile = null, string $purpose = 'text'): bool
    {
        if ($this->isCritical($event)) {
            SystemLog::ok(
                'ai.quota.checked',
                input: ['normalized_event_id' => $event->id, 'purpose' => $purpose],
                calc: ['is_critical' => true, 'bypassed' => true],
                result: ['blocked' => false],
            );

            return false;
        }

        $profile ??= $this->resolveTenantProfile->execute($event->team_id);

        $usage = $this->usage($event->team_id, $profile);
        $blocked = $usage['exceeded_by'] !== null;

        SystemLog::ok(
            'ai.quota.checked',
            input: ['normalized_event_id' => $event->id, 'purpose' => $purpose],
            calc: ['is_critical' => false, 'bypassed' => false, ...$usage],
            result: ['blocked' => $blocked, 'exceeded_by' => $usage['exceeded_by']],
            debug: ! $blocked,
        );

        return $blocked;
    }

    public function exceeded(int $teamId, TenantAIProfileData $profile): bool
    {
        return $this->usage($teamId, $profile)['exceeded_by'] !== null;
    }

    /**
     * Consumption against both limits. Monthly tokens are checked first and
     * short-circuit the daily-calls query (calls_* stay null when not consulted).
     * Without meters the limit does not apply (as before).
     *
     * @return array{billing_period_key: string, tokens_meters_present: bool, tokens_used_this_period: ?int, tokens_limit: int, calls_meter_present: ?bool, calls_today: ?int, calls_limit: int, exceeded_by: ?string}
     */
    private function usage(int $teamId, TenantAIProfileData $profile): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $profile): array {
            $periodKey = now()->format('Y-m');
            $usage = [
                'billing_period_key' => $periodKey,
                'tokens_meters_present' => false,
                'tokens_used_this_period' => null,
                'tokens_limit' => $profile->monthlyTokenLimit,
                'calls_meter_present' => null,
                'calls_today' => null,
                'calls_limit' => $profile->dailyCallLimit,
                'exceeded_by' => null,
            ];

            $meterIds = UsageMeter::query()->whereIn('code', ['ai_tokens_in', 'ai_tokens_out'])->pluck('id');

            if ($meterIds->isNotEmpty()) {
                $usage['tokens_meters_present'] = true;
                $usage['tokens_used_this_period'] = (int) UsageEvent::query()
                    ->where('team_id', $teamId)
                    ->whereIn('usage_meter_id', $meterIds)
                    ->where('billing_period_key', $periodKey)
                    ->sum('quantity');

                if ($usage['tokens_used_this_period'] >= $profile->monthlyTokenLimit) {
                    $usage['exceeded_by'] = 'monthly_tokens';

                    return $usage;
                }
            }

            $meterId = UsageMeter::query()->where('code', 'ai_calls')->value('id');
            $usage['calls_meter_present'] = $meterId !== null;

            if ($meterId !== null) {
                $usage['calls_today'] = (int) UsageEvent::query()
                    ->where('team_id', $teamId)
                    ->where('usage_meter_id', $meterId)
                    ->where('occurred_at', '>=', now()->startOfDay())
                    ->sum('quantity');

                if ($usage['calls_today'] >= $profile->dailyCallLimit) {
                    $usage['exceeded_by'] = 'daily_calls';
                }
            }

            return $usage;
        });
    }
}
