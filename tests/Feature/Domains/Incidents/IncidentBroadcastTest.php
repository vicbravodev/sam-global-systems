<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Support\IncidentCreatedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IncidentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_incident_created_broadcasts_to_tenant_channel(): void
    {
        Event::fake([IncidentCreatedBroadcast::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        app(CreateIncidentFromEvent::class)->execute($event);

        Event::assertDispatched(
            IncidentCreatedBroadcast::class,
            function (IncidentCreatedBroadcast $broadcast) use ($team) {
                $channels = $broadcast->broadcastOn();

                return $broadcast->teamId === $team->id
                    && $channels[0] instanceof PrivateChannel
                    && $channels[0]->name === "private-accounts.{$team->id}";
            }
        );
    }
}
