<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Actions\ExpireOldReports;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Jobs\ExpireOldReportsJob;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpireOldReportsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_expires_old_reports_only_for_teams_with_active_subscription(): void
    {
        Storage::fake('rustfs');

        $teamWithSub = Team::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'team_id' => $teamWithSub->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $teamWithoutSub = Team::factory()->create();

        $oldForSubscribedTeam = ReportExecution::factory()->completed()->create([
            'team_id' => $teamWithSub->id,
            'finished_at' => now()->subDays(120),
            'file_path' => "reports/{$teamWithSub->id}/old.json",
        ]);
        Storage::disk('rustfs')->put($oldForSubscribedTeam->file_path, '{}');

        $oldForUnsubscribedTeam = ReportExecution::factory()->completed()->create([
            'team_id' => $teamWithoutSub->id,
            'finished_at' => now()->subDays(120),
            'file_path' => "reports/{$teamWithoutSub->id}/old.json",
        ]);
        Storage::disk('rustfs')->put($oldForUnsubscribedTeam->file_path, '{}');

        (new ExpireOldReportsJob)->handle(app(ExpireOldReports::class));

        $this->assertSame(
            ReportExecutionStatus::Expired,
            $oldForSubscribedTeam->refresh()->status,
        );
        $this->assertNull($oldForSubscribedTeam->refresh()->file_path);

        $this->assertSame(
            ReportExecutionStatus::Completed,
            $oldForUnsubscribedTeam->refresh()->status,
        );
    }

    public function test_job_uses_analytics_queue(): void
    {
        $job = new ExpireOldReportsJob;

        $this->assertSame('analytics', $job->queue);
    }
}
