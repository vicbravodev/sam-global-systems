<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\ChangeHistory;
use App\Domains\Audit\Models\DomainEventLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_audit_logs_are_scoped_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        AuditLog::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'actor_type' => 'system',
            'action' => 'test.event',
            'category' => 'domain',
            'entity_type' => 'App\\Models\\Team',
            'signature' => 'sig-team-1',
            'summary' => 'team event',
            'occurred_at' => now(),
        ]);

        $otherTeam = Team::factory()->create();

        AuditLog::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'actor_type' => 'system',
            'action' => 'test.event',
            'category' => 'domain',
            'entity_type' => 'App\\Models\\Team',
            'signature' => 'sig-team-2',
            'summary' => 'foreign event',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/audit/logs");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('team event', $response->json('data.0.summary'));
    }

    public function test_cannot_view_another_teams_audit_log(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $otherTeam = Team::factory()->create();
        $foreign = AuditLog::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'actor_type' => 'system',
            'action' => 'test.event',
            'category' => 'domain',
            'entity_type' => 'App\\Models\\Team',
            'signature' => 'sig-foreign',
            'summary' => 'foreign event',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/audit/logs/{$foreign->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_domain_event_logs_are_scoped_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        DomainEventLog::factory()->create(['team_id' => $team->id]);
        DomainEventLog::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/audit/events");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_change_histories_are_scoped_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        ChangeHistory::factory()->create(['team_id' => $team->id]);
        ChangeHistory::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/audit/changes");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
