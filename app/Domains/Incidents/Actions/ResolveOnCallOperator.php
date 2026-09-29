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
        return $this->explain($teamId, $at)['user_id'];
    }

    /**
     * Same resolution as execute(), plus the terms that produced it. Read-only.
     * Never carries the ids of candidates that are not members of the team:
     * only how many were skipped.
     *
     * @return array{user_id: ?int, source: 'shift'|'fallback'|null, reason: ?string, calc: array<string, mixed>}
     */
    public function explain(int $teamId, ?DateTimeInterface $at = null): array
    {
        $profile = TenantScheduleProfile::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        $calc = [
            'profile_present' => $profile !== null,
            'local_time' => null,
            'local_day' => null,
            'shifts_count' => 0,
            'matched_shift_index' => null,
            'shifts_matched_count' => 0,
            'malformed_shifts_count' => 0,
            'non_member_skipped_count' => 0,
            'fallback_configured' => false,
            'fallback_is_member' => null,
        ];

        if ($profile === null) {
            return ['user_id' => null, 'source' => null, 'reason' => 'no_active_profile', 'calc' => $calc];
        }

        $rules = $profile->shift_rules_json;

        if (! is_array($rules)) {
            return ['user_id' => null, 'source' => null, 'reason' => 'no_shift_rules', 'calc' => $calc];
        }

        $localized = Carbon::instance($at ?? now())->setTimezone($profile->timezone ?? 'UTC');
        $shifts = (array) ($rules['on_call'] ?? []);

        $calc['local_time'] = $localized->format('H:i');
        $calc['local_day'] = strtolower($localized->englishDayOfWeek);
        $calc['shifts_count'] = count($shifts);

        // Pure pass (no queries): every shift that matches the schedule,
        // whether or not its user is still a member. It must never throw
        // (it runs inside the incident-creation transaction): a malformed
        // shift is counted apart and never as matched.
        foreach ($shifts as $shift) {
            if (is_array($shift) && $this->isMalformed($shift)) {
                $calc['malformed_shifts_count']++;

                continue;
            }

            if ($this->isCandidate($shift, $localized)) {
                $calc['shifts_matched_count']++;
            }
        }

        foreach (array_values($shifts) as $index => $shift) {
            if (! $this->isCandidate($shift, $localized)) {
                continue;
            }

            $userId = $shift['user_id'];

            if ($this->isMember($teamId, (int) $userId)) {
                $calc['matched_shift_index'] = $index;

                return ['user_id' => (int) $userId, 'source' => 'shift', 'reason' => null, 'calc' => $calc];
            }

            $calc['non_member_skipped_count']++;
        }

        $fallback = $rules['fallback_on_call_user_id'] ?? null;
        $calc['fallback_configured'] = is_numeric($fallback);

        if (is_numeric($fallback)) {
            $calc['fallback_is_member'] = $this->isMember($teamId, (int) $fallback);

            if ($calc['fallback_is_member']) {
                return ['user_id' => (int) $fallback, 'source' => 'fallback', 'reason' => null, 'calc' => $calc];
            }
        }

        return ['user_id' => null, 'source' => null, 'reason' => 'no_eligible_member', 'calc' => $calc];
    }

    /**
     * A shift with a numeric user that matches the schedule at $at.
     */
    private function isCandidate(mixed $shift, Carbon $at): bool
    {
        return is_array($shift)
            && is_numeric($shift['user_id'] ?? null)
            && $this->shiftMatches($shift, $at);
    }

    /**
     * A shift whose `days` holds non-string entries or whose `start`/`end`
     * are present but not strings. Only the counting pass uses it: the
     * winner loop keeps evaluating shifts exactly as before.
     *
     * @param  array<mixed>  $shift
     */
    private function isMalformed(array $shift): bool
    {
        $days = $shift['days'] ?? null;

        if (is_array($days)) {
            foreach ($days as $day) {
                if (! is_string($day)) {
                    return true;
                }
            }
        }

        foreach (['start', 'end'] as $key) {
            if (isset($shift[$key]) && ! is_string($shift[$key])) {
                return true;
            }
        }

        return false;
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
