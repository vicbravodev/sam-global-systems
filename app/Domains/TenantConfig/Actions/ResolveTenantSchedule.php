<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\TenantConfig\Data\ResolvedSchedule;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class ResolveTenantSchedule implements TenantScheduleResolver
{
    private const WEEKDAY_KEYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    public function resolve(int $teamId, ?DateTimeInterface $at = null): ResolvedSchedule
    {
        $profile = TenantScheduleProfile::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        $at = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        if ($profile === null) {
            return new ResolvedSchedule(
                teamId: $teamId,
                profileCode: 'system_default',
                timezone: 'UTC',
                evaluatedAt: $at,
                withinOperatingHours: true,
                afterHoursBehavior: null,
                isPersisted: false,
            );
        }

        $localized = $at->setTimezone($profile->timezone);
        $within = $this->isWithinOperatingHours($profile, $localized);

        return new ResolvedSchedule(
            teamId: $teamId,
            profileCode: $profile->profile_code,
            timezone: $profile->timezone,
            evaluatedAt: $localized,
            withinOperatingHours: $within,
            afterHoursBehavior: $within ? null : $profile->after_hours_behavior_json,
            isPersisted: true,
        );
    }

    private function isWithinOperatingHours(TenantScheduleProfile $profile, CarbonImmutable $localized): bool
    {
        $hours = $profile->operating_hours_json ?? [];
        $weekdayKey = self::WEEKDAY_KEYS[$localized->dayOfWeekIso] ?? null;

        if ($weekdayKey === null || ! array_key_exists($weekdayKey, $hours)) {
            return false;
        }

        $window = $hours[$weekdayKey];

        if (! is_array($window) || ! isset($window['start'], $window['end'])) {
            return false;
        }

        $startMinutes = $this->minutesOf($window['start']);
        $endMinutes = $this->minutesOf($window['end']);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        $currentMinutes = ($localized->hour * 60) + $localized->minute;

        return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
    }

    private function minutesOf(string $time): ?int
    {
        $parts = explode(':', $time);

        if (count($parts) !== 2) {
            return null;
        }

        [$hour, $minute] = $parts;

        if (! ctype_digit($hour) || ! ctype_digit($minute)) {
            return null;
        }

        return ((int) $hour * 60) + (int) $minute;
    }
}
