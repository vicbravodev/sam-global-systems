<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpi_records_are_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        KpiRecord::factory()->create(['team_id' => $userA->currentTeam->id]);
        KpiRecord::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);

        $this->assertSame(1, KpiRecord::query()->count());
        $this->assertSame(2, KpiRecord::withoutGlobalScopes()->count());
    }

    public function test_analytics_snapshots_are_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        AnalyticsSnapshot::factory()->create(['team_id' => $userA->currentTeam->id]);
        AnalyticsSnapshot::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);

        $this->assertSame(1, AnalyticsSnapshot::query()->count());
    }

    public function test_report_executions_are_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $defA = ReportDefinition::factory()->create(['team_id' => $userA->currentTeam->id]);
        $defB = ReportDefinition::factory()->create(['team_id' => $userB->currentTeam->id]);

        ReportExecution::factory()->create([
            'report_definition_id' => $defA->id,
            'team_id' => $userA->currentTeam->id,
        ]);
        ReportExecution::factory()->create([
            'report_definition_id' => $defB->id,
            'team_id' => $userB->currentTeam->id,
        ]);

        $this->actingAs($userA);

        $this->assertSame(1, ReportExecution::query()->count());
        $this->assertSame(2, ReportExecution::withoutGlobalScopes()->count());
    }
}
