<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Actions\UpdateDriverStatus;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Enums\StatusSeverity;
use App\Domains\Drivers\Events\DriverStatusChanged;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DriverStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_driver_status_and_logs_history(): void
    {
        Event::fake([DriverStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'full_name' => 'John Doe',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $action = app(UpdateDriverStatus::class);

        $updated = $action->execute(
            $driver,
            DriverStatus::Suspended,
            StatusSeverity::High,
        );

        $this->assertEquals(
            DriverStatus::Suspended,
            $updated->status,
            'Driver status should be updated to the new status',
        );

        $this->assertDatabaseHas('driver_statuses', [
            'driver_id' => $driver->id,
            'status_code' => 'suspended',
            'severity' => 'high',
        ]);

        $this->assertEquals(
            1,
            DriverStatusLog::where('driver_id', $driver->id)->count(),
            'One status log entry should be created for the status change',
        );
    }

    public function test_it_dispatches_driver_status_changed_event(): void
    {
        Event::fake([DriverStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'full_name' => 'Jane Smith',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $action = app(UpdateDriverStatus::class);

        $action->execute($driver, DriverStatus::UnderReview, StatusSeverity::Medium);

        Event::assertDispatched(DriverStatusChanged::class, function ($event) use ($team, $driver) {
            return $event->teamId === $team->id
                && $event->driverId === $driver->id
                && $event->previousStatus === 'active'
                && $event->newStatus === 'under_review';
        });
    }
}
