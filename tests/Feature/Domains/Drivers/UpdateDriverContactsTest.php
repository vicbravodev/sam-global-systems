<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateDriverContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_mobile_phone_contact_must_be_e164(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $driver = $this->makeDriver($user);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '415-555-1234'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('contacts.0.value');
    }

    public function test_valid_e164_mobile_phone_is_accepted(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $driver = $this->makeDriver($user);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '+5215555550123'],
            ],
        ]);

        $response->assertSuccessful();
    }

    public function test_non_phone_contact_types_are_not_e164_constrained(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $driver = $this->makeDriver($user);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                ['contact_type' => 'email', 'value' => 'driver@example.com'],
            ],
        ]);

        $response->assertSuccessful();
    }

    private function makeDriver(User $user): Driver
    {
        return Driver::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
