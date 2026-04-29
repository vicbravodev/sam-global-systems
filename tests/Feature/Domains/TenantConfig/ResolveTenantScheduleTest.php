<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Actions\ResolveTenantSchedule;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTenantScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_schedule_when_no_profile(): void
    {
        $team = User::factory()->create()->currentTeam;

        $resolved = app(ResolveTenantSchedule::class)->resolve($team->id);

        $this->assertFalse($resolved->isPersisted);
        $this->assertTrue($resolved->withinOperatingHours);
        $this->assertNull($resolved->afterHoursBehavior);
    }

    public function test_within_operating_hours_returns_no_after_hours_behavior(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'biz',
            'timezone' => 'UTC',
            'operating_hours_json' => [
                'monday' => ['start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['start' => '08:00', 'end' => '18:00'],
                'thursday' => ['start' => '08:00', 'end' => '18:00'],
                'friday' => ['start' => '08:00', 'end' => '18:00'],
                'saturday' => null,
                'sunday' => null,
            ],
            'after_hours_behavior_json' => ['suppress_low_priority' => true],
            'is_active' => true,
        ]);

        $monday11am = CarbonImmutable::parse('2026-04-27 11:00:00', 'UTC');

        $resolved = app(ResolveTenantSchedule::class)->resolve($team->id, $monday11am);

        $this->assertTrue($resolved->withinOperatingHours);
        $this->assertNull($resolved->afterHoursBehavior);
    }

    public function test_after_hours_behavior_is_returned_when_outside_hours(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'biz',
            'timezone' => 'UTC',
            'operating_hours_json' => [
                'monday' => ['start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['start' => '08:00', 'end' => '18:00'],
                'thursday' => ['start' => '08:00', 'end' => '18:00'],
                'friday' => ['start' => '08:00', 'end' => '18:00'],
                'saturday' => null,
                'sunday' => null,
            ],
            'after_hours_behavior_json' => [
                'suppress_low_priority' => true,
                'escalation_policy' => 'on_call_only',
            ],
            'is_active' => true,
        ]);

        $sunday = CarbonImmutable::parse('2026-04-26 12:00:00', 'UTC');

        $resolved = app(ResolveTenantSchedule::class)->resolve($team->id, $sunday);

        $this->assertFalse($resolved->withinOperatingHours);
        $this->assertSame('on_call_only', $resolved->afterHoursBehavior['escalation_policy']);
    }

    public function test_evaluates_in_profile_timezone(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'mx',
            'timezone' => 'America/Mexico_City',
            'operating_hours_json' => [
                'monday' => ['start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['start' => '08:00', 'end' => '18:00'],
                'thursday' => ['start' => '08:00', 'end' => '18:00'],
                'friday' => ['start' => '08:00', 'end' => '18:00'],
                'saturday' => null,
                'sunday' => null,
            ],
            'after_hours_behavior_json' => [],
            'is_active' => true,
        ]);

        // Monday 14:00 UTC == 08:00 in America/Mexico_City (CST, UTC-6).
        $monday14utc = CarbonImmutable::parse('2026-04-27 14:00:00', 'UTC');

        $resolved = app(ResolveTenantSchedule::class)->resolve($team->id, $monday14utc);

        $this->assertTrue($resolved->withinOperatingHours);
    }
}
