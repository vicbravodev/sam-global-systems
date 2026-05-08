<?php

namespace Tests\Feature\Domains\Audit\Queries;

use App\Contracts\Audit\AuditLogQuery;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Queries\DbAuditLogQuery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbAuditLogQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_resolves_to_db_implementation(): void
    {
        $this->assertInstanceOf(DbAuditLogQuery::class, app(AuditLogQuery::class));
    }

    public function test_returns_logs_in_window_ordered_by_occurred_at(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $second = AuditLog::factory()->create([
            'team_id' => $team->id,
            'category' => AuditCategory::Domain,
            'action' => 'incident.created',
            'occurred_at' => $start->copy()->addHours(3),
        ]);

        $first = AuditLog::factory()->create([
            'team_id' => $team->id,
            'category' => AuditCategory::Security,
            'action' => 'auth.login',
            'occurred_at' => $start->copy()->addHour(),
        ]);

        // Out-of-window — must be excluded.
        AuditLog::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => $start->copy()->subDays(3),
        ]);

        $rows = app(DbAuditLogQuery::class)->forTenant($team->id, $start, $end);

        $this->assertCount(2, $rows);
        $this->assertSame($first->id, $rows[0]['id']);
        $this->assertSame($second->id, $rows[1]['id']);
        $this->assertSame('auth.login', $rows[0]['action']);
        $this->assertSame(AuditCategory::Security->value, $rows[0]['category']);
        $this->assertSame(AuditCategory::Domain->value, $rows[1]['category']);
    }

    public function test_returns_empty_collection_when_no_logs_in_window(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $rows = app(DbAuditLogQuery::class)->forTenant($team->id, $start, $end);

        $this->assertTrue($rows->isEmpty());
    }

    public function test_results_are_isolated_by_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        AuditLog::factory()->count(2)->create([
            'team_id' => $teamA->id,
            'occurred_at' => $start->copy()->addHour(),
        ]);

        AuditLog::factory()->count(3)->create([
            'team_id' => $teamB->id,
            'occurred_at' => $start->copy()->addHour(),
        ]);

        $query = app(DbAuditLogQuery::class);

        $this->assertCount(2, $query->forTenant($teamA->id, $start, $end));
        $this->assertCount(3, $query->forTenant($teamB->id, $start, $end));
    }

    public function test_query_does_not_leak_to_currentteam_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        AuditLog::factory()->count(2)->create([
            'team_id' => $userA->currentTeam->id,
            'occurred_at' => $start->copy()->addHour(),
        ]);

        AuditLog::factory()->count(3)->create([
            'team_id' => $userB->currentTeam->id,
            'occurred_at' => $start->copy()->addHour(),
        ]);

        $this->actingAs($userA);

        $rows = app(DbAuditLogQuery::class)->forTenant(
            $userB->currentTeam->id,
            $start,
            $end,
        );

        $this->assertCount(3, $rows);
    }
}
