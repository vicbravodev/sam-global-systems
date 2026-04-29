<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class IncidentChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);

        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'sam-key',
            'secret' => 'sam-secret',
            'app_id' => 'sam-local',
            'options' => [
                'host' => 'soketi',
                'port' => 6001,
                'scheme' => 'http',
                'encrypted' => false,
                'useTLS' => false,
            ],
        ]);

        require base_path('routes/channels.php');
        Broadcast::driver();
    }

    public function test_team_member_can_subscribe_to_incident_presence_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-incidents.{$incident->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_non_member_cannot_subscribe_to_foreign_incident_channel(): void
    {
        $user = User::factory()->create();
        $foreignOwner = User::factory()->create();
        $foreignIncident = Incident::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-incidents.{$foreignIncident->id}",
        ]);

        $response->assertStatus(403);
    }
}
