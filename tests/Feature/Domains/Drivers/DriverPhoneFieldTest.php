<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverPhoneFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_persists_phone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $driver = Driver::factory()->create([
            'team_id' => $user->currentTeam->id,
            'phone' => '+5215555550199',
        ]);

        $this->assertSame('+5215555550199', $driver->fresh()->phone);
    }
}
