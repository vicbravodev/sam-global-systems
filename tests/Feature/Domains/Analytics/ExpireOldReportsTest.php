<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Actions\ExpireOldReports;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ExpireOldReportsTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_expires_completed_reports_older_than_retention_window(): void
    {
        Storage::fake('rustfs');

        $team = Team::factory()->create();

        $oldExecution = ReportExecution::factory()->completed()->create([
            'team_id' => $team->id,
            'finished_at' => now()->subDays(120),
            'file_path' => "reports/{$team->id}/old.json",
        ]);
        Storage::disk('rustfs')->put($oldExecution->file_path, '{}');

        $freshExecution = ReportExecution::factory()->completed()->create([
            'team_id' => $team->id,
            'finished_at' => now()->subDays(10),
            'file_path' => "reports/{$team->id}/fresh.json",
        ]);
        Storage::disk('rustfs')->put($freshExecution->file_path, '{}');

        $expired = app(ExpireOldReports::class)->execute($team->id);

        $this->assertSame(1, $expired);
        $this->assertSame(
            ReportExecutionStatus::Expired,
            $oldExecution->refresh()->status,
        );
        $this->assertNull($oldExecution->refresh()->file_path);
        Storage::disk('rustfs')->assertMissing("reports/{$team->id}/old.json");

        $this->assertSame(
            ReportExecutionStatus::Completed,
            $freshExecution->refresh()->status,
        );
        Storage::disk('rustfs')->assertExists("reports/{$team->id}/fresh.json");

        $ctx = $this->assertSystemLogged('analytics.reports.expired');
        $this->assertSame(['team_id' => $team->id], $ctx['input']);
        $this->assertSame(['expired_count' => 1, 'files_deleted' => 1], $ctx['result']);
        $this->assertArrayHasKey('retention_days', $ctx['calc']);
        $this->assertSame('info', $this->systemLogEntries('analytics.reports.expired')[0]['level']);

        // Nada más que expirar: la misma línea, a debug.
        app(ExpireOldReports::class)->execute($team->id);
        $this->assertSame('debug', $this->systemLogEntries('analytics.reports.expired')[1]['level']);
        $this->assertNoSensitiveDataLogged();
    }
}
