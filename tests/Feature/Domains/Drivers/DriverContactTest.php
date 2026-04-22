<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Actions\GetEscalationContactsForDriver;
use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverContact;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_it_returns_escalation_contacts_in_priority_order(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverContact::factory()->create([
            'driver_id' => $driver->id,
            'contact_type' => ContactType::MobilePhone,
            'value' => '555-0001',
            'is_primary' => false,
            'is_emergency' => false,
        ]);

        DriverContact::factory()->primary()->create([
            'driver_id' => $driver->id,
            'value' => '555-0002',
        ]);

        DriverContact::factory()->supervisor()->create([
            'driver_id' => $driver->id,
            'value' => '555-0003',
        ]);

        DriverContact::factory()->emergency()->create([
            'driver_id' => $driver->id,
            'value' => '555-0004',
        ]);

        $action = app(GetEscalationContactsForDriver::class);

        $contacts = $action->execute($driver);

        $this->assertCount(
            4,
            $contacts,
            'All 4 contacts should be returned for escalation',
        );

        $this->assertEquals(
            '555-0004',
            $contacts->first()->value,
            'Emergency contact should be first in escalation order',
        );

        $this->assertEquals(
            '555-0003',
            $contacts->get(1)->value,
            'Supervisor contact should be second in escalation order',
        );

        $this->assertEquals(
            '555-0002',
            $contacts->get(2)->value,
            'Primary contact should be third in escalation order',
        );
    }

    public function test_it_prioritizes_emergency_contacts_in_escalation(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverContact::factory()->create([
            'driver_id' => $driver->id,
            'contact_type' => ContactType::MobilePhone,
            'value' => '555-regular',
            'is_primary' => false,
            'is_emergency' => false,
        ]);

        DriverContact::factory()->emergency()->create([
            'driver_id' => $driver->id,
            'value' => '555-emergency',
        ]);

        $action = app(GetEscalationContactsForDriver::class);

        $contacts = $action->execute($driver);

        $this->assertTrue(
            $contacts->first()->is_emergency,
            'The first contact in escalation should be the emergency contact',
        );
    }

    public function test_it_updates_driver_contacts_via_api(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'API',
            'last_name' => 'Driver',
            'full_name' => 'API Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverContact::factory()->create(['driver_id' => $driver->id]);

        $this->actingAs($user);

        $response = $this->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                [
                    'contact_type' => 'mobile_phone',
                    'label' => 'Work Phone',
                    'value' => '+1-555-0199',
                    'is_primary' => true,
                    'is_emergency' => false,
                ],
                [
                    'contact_type' => 'emergency_contact',
                    'label' => 'Spouse',
                    'value' => '+1-555-0200',
                    'is_primary' => false,
                    'is_emergency' => true,
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertEquals(
            2,
            DriverContact::where('driver_id', $driver->id)->count(),
            'Old contacts should be replaced with the 2 new contacts from the request',
        );

        $this->assertDatabaseHas('driver_contacts', [
            'driver_id' => $driver->id,
            'value' => '+1-555-0199',
            'is_primary' => true,
        ]);
    }
}
