<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NotifyOnIncidentCreatedTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        Bus::fake();
    }

    private function makeIncident(int $teamId, string $typeCode): Incident
    {
        $type = IncidentType::query()->firstOrCreate(
            ['code' => $typeCode],
            ['name' => ucfirst(str_replace('_', ' ', $typeCode)), 'is_active' => true],
        );

        return Incident::factory()->create([
            'team_id' => $teamId,
            'incident_type_id' => $type->id,
        ]);
    }

    public function test_uses_type_specific_notification_type_when_template_exists(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $team = User::factory()->create()->currentTeam;
        $incident = $this->makeIncident($team->id, 'panic_emergency');

        IncidentCreated::dispatch($incident);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_created:{$incident->id}")
            ->sole();

        $this->assertSame('incident.panic_emergency.created', $notification->notification_type);
    }

    public function test_falls_back_to_generic_type_without_template(): void
    {
        // No NotificationTemplateSeeder: there is no panic-specific template.
        $team = User::factory()->create()->currentTeam;
        $incident = $this->makeIncident($team->id, 'panic_emergency');

        IncidentCreated::dispatch($incident);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_created:{$incident->id}")
            ->sole();

        $this->assertSame('incident.created', $notification->notification_type);
    }

    public function test_payload_carries_rich_context_for_the_template(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $team = User::factory()->create()->currentTeam;
        $incident = $this->makeIncident($team->id, 'panic_emergency');

        IncidentCreated::dispatch($incident);

        $payload = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_created:{$incident->id}")
            ->sole()
            ->payload_json;

        $this->assertSame($incident->title, $payload['incident_title']);
        $this->assertSame('panic_emergency', $payload['incident_type']);
        $this->assertArrayHasKey('asset_name', $payload);
        $this->assertArrayHasKey('driver_name', $payload);
        $this->assertArrayHasKey('location', $payload);
        $this->assertArrayHasKey('has_media', $payload);
        $this->assertStringContainsString("/incidents/{$incident->id}", (string) $payload['incident_url']);
    }
}
