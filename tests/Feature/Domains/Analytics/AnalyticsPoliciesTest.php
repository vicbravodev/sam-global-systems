<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Analytics\Policies\AnalyticsSnapshotPolicy;
use App\Domains\Analytics\Policies\KpiRecordPolicy;
use App\Domains\Analytics\Policies\ReportDefinitionPolicy;
use App\Domains\Analytics\Policies\ReportExecutionPolicy;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsPoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    private function actingAsRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $user->currentTeam->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);

        $this->actingAs($user);

        return $user;
    }

    public function test_kpi_record_policy_allows_analyst_to_view(): void
    {
        $user = $this->actingAsRole('analyst');
        $kpi = KpiRecord::factory()->create(['team_id' => $user->currentTeam->id]);

        $policy = app(KpiRecordPolicy::class);

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $kpi));
        $this->assertTrue($policy->viewAiPerformance($user));
    }

    public function test_kpi_record_policy_denies_view_across_tenants(): void
    {
        $user = $this->actingAsRole('analyst');
        $otherTeamUser = User::factory()->create();
        $kpiInOtherTeam = KpiRecord::factory()->create(['team_id' => $otherTeamUser->currentTeam->id]);

        $policy = app(KpiRecordPolicy::class);

        $this->assertFalse($policy->view($user, $kpiInOtherTeam));
    }

    public function test_kpi_record_policy_denies_ai_performance_for_role_without_permission(): void
    {
        $user = $this->actingAsRole('billing_manager');

        $policy = app(KpiRecordPolicy::class);

        $this->assertFalse($policy->viewAiPerformance($user));
    }

    public function test_analytics_snapshot_policy_allows_analyst(): void
    {
        $user = $this->actingAsRole('analyst');
        $snapshot = AnalyticsSnapshot::factory()->create(['team_id' => $user->currentTeam->id]);

        $policy = app(AnalyticsSnapshotPolicy::class);

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $snapshot));
    }

    public function test_analytics_snapshot_policy_denies_view_across_tenants(): void
    {
        $user = $this->actingAsRole('analyst');
        $otherTeamUser = User::factory()->create();
        $snapshotInOtherTeam = AnalyticsSnapshot::factory()->create(['team_id' => $otherTeamUser->currentTeam->id]);

        $policy = app(AnalyticsSnapshotPolicy::class);

        $this->assertFalse($policy->view($user, $snapshotInOtherTeam));
    }

    public function test_report_execution_policy_allows_analyst_to_view_and_download(): void
    {
        $user = $this->actingAsRole('analyst');
        $definition = ReportDefinition::factory()->create(['team_id' => $user->currentTeam->id]);
        $execution = ReportExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'report_definition_id' => $definition->id,
        ]);

        $policy = app(ReportExecutionPolicy::class);

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $execution));
        $this->assertTrue($policy->download($user, $execution));
    }

    public function test_report_execution_policy_denies_download_for_viewer(): void
    {
        $user = $this->actingAsRole('viewer');
        $definition = ReportDefinition::factory()->create(['team_id' => $user->currentTeam->id]);
        $execution = ReportExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'report_definition_id' => $definition->id,
        ]);

        $policy = app(ReportExecutionPolicy::class);

        $this->assertTrue($policy->view($user, $execution));
        $this->assertFalse($policy->download($user, $execution));
    }

    public function test_report_execution_policy_denies_view_across_tenants(): void
    {
        $user = $this->actingAsRole('analyst');
        $otherTeamUser = User::factory()->create();
        $defInOther = ReportDefinition::factory()->create(['team_id' => $otherTeamUser->currentTeam->id]);
        $executionInOther = ReportExecution::factory()->create([
            'team_id' => $otherTeamUser->currentTeam->id,
            'report_definition_id' => $defInOther->id,
        ]);

        $policy = app(ReportExecutionPolicy::class);

        $this->assertFalse($policy->view($user, $executionInOther));
        $this->assertFalse($policy->download($user, $executionInOther));
    }

    public function test_report_definition_policy_allows_tenant_admin_to_generate(): void
    {
        $user = $this->actingAsRole('tenant_admin');
        $definition = ReportDefinition::factory()->create(['team_id' => $user->currentTeam->id]);

        $policy = app(ReportDefinitionPolicy::class);

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->generate($user, $definition));
    }

    public function test_report_definition_policy_denies_generate_across_tenants(): void
    {
        $user = $this->actingAsRole('tenant_admin');
        $otherTeamUser = User::factory()->create();
        $definitionInOther = ReportDefinition::factory()->create(['team_id' => $otherTeamUser->currentTeam->id]);

        $policy = app(ReportDefinitionPolicy::class);

        $this->assertFalse($policy->generate($user, $definitionInOther));
    }

    public function test_report_definition_policy_allows_global_template_for_authorized_user(): void
    {
        $user = $this->actingAsRole('tenant_admin');
        $globalDefinition = ReportDefinition::factory()->create(['team_id' => null]);

        $policy = app(ReportDefinitionPolicy::class);

        $this->assertTrue($policy->generate($user, $globalDefinition));
    }
}
