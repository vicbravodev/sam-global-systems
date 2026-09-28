<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Roadmap F14: tenant-facing audit page (audit trail + domain event log).
 */
class AuditPageTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_page_renders_logs_and_domain_events(): void
    {
        AuditLog::factory()->count(2)->create(['team_id' => $this->team->id]);
        DomainEventLog::factory()->create(['team_id' => $this->team->id]);

        $response = $this->actingAs($this->user)->get(
            route('audit.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('audit/index')
                ->has('logs', 2)
                ->has('logs.0', fn (Assert $row) => $row
                    ->hasAll(['id', 'action', 'category', 'actorType', 'entityType', 'summary', 'occurredAt'])
                    ->etc())
                ->has('pagination')
                ->has('filters')
                ->has('filterOptions.categories')
                ->has('events', 1),
        );
    }

    public function test_logs_expose_a_human_readable_entity_label_for_fqcn(): void
    {
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'entity_type' => 'App\\Domains\\Normalization\\Models\\NormalizedEvent',
        ]);

        $response = $this->actingAs($this->user)->get(
            route('audit.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->where('logs.0.entityLabel', 'Evento normalizado'),
        );
    }

    public function test_logs_expose_a_human_readable_entity_label_for_short_type(): void
    {
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'entity_type' => 'incident',
        ]);

        $response = $this->actingAs($this->user)->get(
            route('audit.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->where('logs.0.entityLabel', 'Incidente'),
        );
    }

    public function test_logs_can_be_filtered_by_category(): void
    {
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'category' => AuditCategory::Security,
        ]);
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'category' => AuditCategory::Domain,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('audit.show', ['current_team' => $this->team->slug, 'category' => 'security']),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('logs', 1)
                ->where('logs.0.category', 'security'),
        );
    }

    public function test_page_hides_other_tenant_audit_trail(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        AuditLog::factory()->create(['team_id' => $otherTeam->id]);
        DomainEventLog::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->actingAs($this->user)->get(
            route('audit.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page->has('logs', 0)->has('events', 0),
        );
    }

    public function test_system_noise_is_hidden_by_default_and_shown_with_toggle(): void
    {
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'action' => 'tenancy.usage_recorded',
            'category' => AuditCategory::Billing,
        ]);
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'action' => 'incident.resolved',
            'category' => AuditCategory::Domain,
        ]);

        $this->actingAs($this->user)
            ->get(route('audit.show', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('audit/index')
                ->has('logs', 1)
                ->where('logs.0.action', 'incident.resolved')
                ->where('logs.0.actionLabel', 'Incidente resuelto')
                ->where('logs.0.categoryLabel', 'Operación')
                ->where('filters.system', false));

        $this->actingAs($this->user)
            ->get(route('audit.show', ['current_team' => $this->team->slug, 'system' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs', 2)
                ->where('filters.system', true));
    }

    public function test_team_entity_and_user_actor_are_shown_by_name(): void
    {
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'entity_type' => 'App\\Models\\Team',
            'entity_id' => $this->team->id,
            'actor_type' => AuditActorType::User,
            'actor_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('audit.show', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.0.entityLabel', $this->team->name)
                ->where('logs.0.entityId', null)
                ->where('logs.0.actorLabel', $this->user->name));
    }

    public function test_actor_names_never_resolve_users_of_another_tenant(): void
    {
        $outsider = User::factory()->create(['name' => 'Usuario Ajeno']);
        AuditLog::factory()->create([
            'team_id' => $this->team->id,
            'actor_type' => AuditActorType::User,
            'actor_id' => $outsider->id,
        ]);
        AuditLog::factory()->create(['team_id' => $outsider->currentTeam->id]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)
            ->get(route('audit.show', ['current_team' => $this->team->slug])));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('logs', 1)
            ->where('logs.0.actorLabel', 'Usuario #'.$outsider->id));
        $this->assertStringNotContainsString('Usuario Ajeno', $response->getContent());
    }
}
