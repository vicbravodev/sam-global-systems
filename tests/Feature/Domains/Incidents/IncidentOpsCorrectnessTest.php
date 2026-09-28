<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * UI audit (ops correctness): inbox ownership (P0-2), placeholder AI verdicts
 * (P0-3), client-formatted timestamps (P1-1) and the incident-detail fixes
 * (expired footage, communications, live related history).
 */
class IncidentOpsCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    /**
     * @return array<string, mixed>
     */
    private function inboxRow(Incident $incident): array
    {
        $rows = $this->actingAs($this->user)
            ->get(route('incidents.index', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->viewData('page')['props']['incidents'];

        return collect($rows)->firstWhere('incidentId', $incident->id);
    }

    public function test_queue_only_assignment_is_not_shown_as_assigned(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => AssigneeType::Queue,
            'assigned_to_id' => 7,
        ]);

        $row = $this->inboxRow($incident);

        $this->assertSame('new', $row['status']);
        $this->assertSame('Nuevo', $row['statusLabel']);
        $this->assertNull($row['assignee']);
    }

    public function test_user_assignment_is_shown_as_assigned(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => AssigneeType::User,
            'assigned_to_id' => $this->user->id,
        ]);

        $row = $this->inboxRow($incident);

        $this->assertSame('assigned', $row['status']);
        $this->assertSame($this->user->id, $row['assignee']['id']);
    }

    public function test_taking_an_incident_shows_the_operator_as_owner(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->postJson(route('incidents.claim', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertSuccessful();

        $row = $this->inboxRow($incident);

        $this->assertSame('assigned', $row['status']);
        $this->assertSame($this->user->id, $row['assignee']['id']);
        $this->assertSame($this->user->name, $row['assignee']['name']);
    }

    public function test_placeholder_null_agent_evaluation_is_not_shown_as_a_verdict(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);
        AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'model_used' => 'null-agent:1.0',
            'confidence_score' => 0.85,
            'risk_score' => 0.8,
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('incident.aiPlaceholder', true)
                ->where('incident.aiConfidence', null)
                ->where('incident.aiRiskScore', null)
                ->where('incident.aiDecision', 'info')
                ->where('incident.model', '—')
                ->where('incident.aiReason', 'Sin evaluación IA.'));
    }

    public function test_real_model_evaluation_keeps_its_scores(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);
        AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'model_used' => 'openai:gpt-5-mini',
            'confidence_score' => 0.91,
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incident.aiPlaceholder', false)
                ->where('incident.aiConfidence', 0.91));
    }

    public function test_timestamps_are_sent_as_iso_not_server_formatted(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);
        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
        ]);
        $incident->eventLinks()->create([
            'normalized_event_id' => $event->id,
            'relation_type' => 'root_trigger',
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('incident.timeline.0', fn (Assert $entry) => $entry
                    ->missing('ts')
                    ->where('tsIso', fn ($iso) => is_string($iso) && str_contains($iso, 'T'))
                    ->etc())
                ->has('incident.relatedLinks.0', fn (Assert $link) => $link
                    ->missing('ts')
                    ->where('tsIso', fn ($iso) => is_string($iso) && str_contains($iso, 'T'))
                    ->etc()));
    }

    public function test_media_request_is_not_offered_once_device_footage_expired(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'occurred_at' => now()->subDays(54),
        ]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mediaRetrieval.available', false)
                ->where('mediaRetrieval.reason', fn ($reason) => str_contains((string) $reason, 'ya no está disponible')));

        $this->actingAs($this->user)
            ->postJson(route('incidents.media.request', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertStatus(422);
    }

    public function test_media_request_is_offered_for_a_recent_event(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'occurred_at' => now()->subHours(2),
        ]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page->where('mediaRetrieval.available', true));
    }

    public function test_detail_links_verification_calls_and_notification_deliveries(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        IncidentCallVerification::factory()->confirmedReal()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'phone' => '+5215512345678',
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
            'subject' => 'Pánico en unidad 12',
        ]);
        NotificationDelivery::factory()->delivered()->create(['notification_id' => $notification->id]);
        NotificationDelivery::factory()->failed()->create(['notification_id' => $notification->id]);

        // Another tenant's notification pointing at the same incident id must
        // never show up.
        $other = Team::factory()->create();
        Notification::factory()->create([
            'team_id' => $other->id,
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('communications.verificationCalls', 1, fn (Assert $call) => $call
                    ->where('outcome', 'confirmed_real')
                    ->where('phone', fn ($phone) => str_ends_with((string) $phone, '5678') && ! str_contains((string) $phone, '55123'))
                    ->etc())
                ->has('communications.notifications', 1, fn (Assert $row) => $row
                    ->where('id', $notification->id)
                    ->where('deliveries', 2)
                    ->where('delivered', 1)
                    ->where('failed', 1)
                    ->etc()));
    }

    public function test_related_history_is_computed_at_view_time(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'asset_id' => $asset->id,
        ]);

        // Opened AFTER the incident: no stored link can know about it.
        $later = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'asset_id' => $asset->id,
            'opened_at' => now()->addMinute(),
        ]);

        // Same asset id in another tenant must not leak into the history.
        $other = Team::factory()->create();
        Incident::factory()->open()->create(['team_id' => $other->id, 'asset_id' => $asset->id]);

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('priorIncidents', 1, fn (Assert $row) => $row
                    ->where('incidentId', $later->id)
                    ->where('reference', $later->reference())
                    ->where('relationType', 'same_asset_open_incident')
                    ->etc()));
    }
}
