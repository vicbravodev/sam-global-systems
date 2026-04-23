<?php

namespace App\Domains\Context\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Normalization\Models\NormalizedEvent;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class ResolveDriverOperationalContext
{
    /**
     * Build the driver operational context snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function execute(?int $driverId, DateTimeInterface $eventOccurredAt): ?array
    {
        if ($driverId === null) {
            return null;
        }

        $driver = Driver::withoutGlobalScopes()
            ->with(['currentAssignment.asset', 'riskProfile'])
            ->find($driverId);

        if ($driver === null) {
            return null;
        }

        $windowEnd = Carbon::instance($eventOccurredAt);
        $windowStart = $windowEnd->copy()->subMinutes(60);

        $recentRiskEventsCount = NormalizedEvent::withoutGlobalScopes()
            ->where('driver_id', $driverId)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->whereHas('eventSeverity', fn ($q) => $q->whereIn('code', ['high', 'critical']))
            ->count();

        $currentAssignment = $driver->currentAssignment;

        return [
            'driver_id' => $driver->id,
            'full_name' => $driver->full_name,
            'employee_code' => $driver->employee_code,
            'status' => $driver->status?->value,
            'current_assignment' => $currentAssignment ? [
                'asset_id' => $currentAssignment->asset_id,
                'asset_name' => $currentAssignment->asset?->name,
                'started_at' => $currentAssignment->started_at?->toIso8601String(),
                'assignment_type' => $currentAssignment->assignment_type?->value,
            ] : null,
            'risk_profile' => $driver->riskProfile ? [
                'risk_score' => $driver->riskProfile->risk_score ?? null,
                'last_calculated_at' => $driver->riskProfile->last_calculated_at?->toIso8601String(),
            ] : null,
            'recent_risk_events_count' => $recentRiskEventsCount,
            'has_recent_risk_events' => $recentRiskEventsCount > 0,
            'has_unresolved_alerts' => false,
        ];
    }
}
