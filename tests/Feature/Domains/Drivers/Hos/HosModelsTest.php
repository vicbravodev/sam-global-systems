<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HosModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_state_rebuilds_its_reading(): void
    {
        $driver = Driver::factory()->create();
        $state = HosDriverState::factory()->create([
            'team_id' => $driver->team_id,
            'driver_id' => $driver->id,
            'duty_status' => HosDutyStatus::Driving,
            'break_remaining_s' => 1593,
            'violation_s' => 0,
        ]);

        $reading = $state->fresh()->toReading('58072405', '281');

        $this->assertSame('driving', $reading->dutyStatus);
        $this->assertSame(1593, $reading->breakRemainingSeconds);
        $this->assertSame('58072405', $reading->externalDriverId);
    }

    public function test_only_one_open_episode_per_driver_and_situation(): void
    {
        $driver = Driver::factory()->create();
        $attributes = ['team_id' => $driver->team_id, 'driver_id' => $driver->id, 'situation' => HosSituation::BreakDue];

        HosEpisode::factory()->create($attributes + ['resolved_at' => now(), 'resolution' => HosEpisodeResolution::Corrected]);
        HosEpisode::factory()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        HosEpisode::factory()->create($attributes);
    }

    public function test_models_are_tenant_scoped(): void
    {
        $driver = Driver::factory()->create();
        HosEpisode::factory()->create(['team_id' => $driver->team_id, 'driver_id' => $driver->id]);
        $other = Team::factory()->create();

        TenantContext::for($other->id, fn () => $this->assertSame(0, HosEpisode::query()->count()));
        TenantContext::for($driver->team_id, fn () => $this->assertSame(1, HosEpisode::query()->open()->count()));
    }
}
