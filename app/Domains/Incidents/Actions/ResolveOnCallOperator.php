<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Membership;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Resolve the on-call operator for a team from its active
 * TenantScheduleProfile (Roadmap B6-P5).
 *
 * Convention inside `shift_rules_json`:
 *
 *   {
 *     "on_call": [
 *       {"user_id": 5, "days": ["monday","tuesday"], "start": "08:00", "end": "20:00"},
 *       {"user_id": 9}
 *     ],
 *     "fallback_on_call_user_id": 3
 *   }
 *
 * Shifts are evaluated in the profile's timezone at the given instant; the
 * first matching shift wins (omitted days/start/end match always). A shift
 * spanning midnight (start > end) matches when the time falls on either
 * side. Users that are no longer members of the team are skipped, so a
 * stale schedule can never assign an outsider.
 */
class ResolveOnCallOperator
{
    public function execute(int $teamId, ?DateTimeInterface $at = null): ?int
    {
        $profile = TenantScheduleProfile::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        $rules = $profile?->shift_rules_json;

        if (! is_array($rules)) {
            return null;
        }

        $localized = Carbon::instance($at ?? now())->setTimezone($profile->timezone ?? 'UTC');

        foreach ((array) ($rules['on_call'] ?? []) as $shift) {
            if (! is_array($shift)) {
                continue;
            }

            $userId = $shift['user_id'] ?? null;

            if (! is_numeric($userId) || ! $this->shiftMatches($shift, $localized)) {
                continue;
            }

            if ($this->isMember($teamId, (int) $userId)) {
                return (int) $userId;
            }
        }

        $fallback = $rules['fallback_on_call_user_id'] ?? null;

        if (is_numeric($fallback) && $this->isMember($teamId, (int) $fallback)) {
            return (int) $fallback;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $shift
     */
    private function shiftMatches(array $shift, Carbon $at): bool
    {
        $days = $shift['days'] ?? null;

        if (is_array($days) && $days !== [] && ! in_array(strtolower($at->englishDayOfWeek), array_map('strtolower', $days), true)) {
            return false;
        }

        $start = $shift['start'] ?? null;
        $end = $shift['end'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return true;
        }

        $time = $at->format('H:i');

        // Overnight shifts (e.g. 20:00–08:00) wrap past midnight.
        if ($start > $end) {
            return $time >= $start || $time < $end;
        }

        return $time >= $start && $time < $end;
    }

    private function isMember(int $teamId, int $userId): bool
    {
        return Membership::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();
    }
}
