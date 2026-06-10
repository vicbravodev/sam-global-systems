<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F10: the events browser (table + unmapped view) and the detail page
 * linking payload, AI evaluation, decision and incident.
 */
class EventsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_index_renders_event_rows(): void
    {
        NormalizedEvent::factory()->count(3)->create(['team_id' => $this->team->id]);

        $response = $this->actingAs($this->user)->get(
            route('events.index', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('events/index')
                ->has('events', 3)
                ->has('events.0', fn (Assert $row) => $row
                    ->hasAll(['id', 'occurredAt', 'status', 'eventType', 'severity', 'asset', 'driver', 'provider'])
                    ->etc())
                ->has('pagination')
                ->has('filters')
                ->has('filterOptions')
                ->has('unmappedCount'),
        );
    }

    public function test_index_filters_unmapped_events(): void
    {
        NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'status' => NormalizedEventStatus::Normalized,
        ]);
        $unmapped = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'status' => NormalizedEventStatus::Unmapped,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('events.index', ['current_team' => $this->team->slug, 'status' => 'unmapped']),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('events/index')
                ->has('events', 1)
                ->where('events.0.id', $unmapped->id)
                ->where('unmappedCount', 1),
        );
    }

    public function test_index_does_not_leak_other_tenant_events(): void
    {
        NormalizedEvent::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('events.index', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(fn (Assert $page) => $page->has('events', 0));
    }

    public function test_show_renders_detail_with_pipeline_links(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'payload_normalized_json' => ['speed' => 92],
        ]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        Decision::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
        ]);

        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('events.show', ['current_team' => $this->team->slug, 'normalizedEvent' => $event->id]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('events/show')
                ->where('event.id', $event->id)
                ->where('event.payload.speed', 92)
                ->where('evaluation.id', $evaluation->id)
                ->has('decision', fn (Assert $decision) => $decision
                    ->where('code', 'LOG_ONLY')
                    ->etc())
                ->where('incident.id', $incident->id)
                ->has('media'),
        );
    }

    public function test_show_is_not_found_for_other_tenant_event(): void
    {
        $foreign = NormalizedEvent::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('events.show', ['current_team' => $this->team->slug, 'normalizedEvent' => $foreign->id]),
        );

        $response->assertNotFound();
    }

    public function test_member_without_context_view_cannot_browse_events(): void
    {
        // A member whose tenant role has zero permissions.
        $stranger = User::factory()->create();
        $team = $stranger->currentTeam;

        $role = Role::factory()->create([
            'code' => 'no_perms_events',
            'scope' => RoleScope::Tenant,
        ]);

        $team->members()->updateExistingPivot($stranger->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        $response = $this->actingAs($stranger)->get(
            route('events.index', ['current_team' => $team->slug]),
        );

        $response->assertForbidden();
    }
}
