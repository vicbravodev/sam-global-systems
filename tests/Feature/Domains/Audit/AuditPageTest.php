<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F14: tenant-facing audit page (audit trail + domain event log).
 */
class AuditPageTest extends TestCase
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
}
