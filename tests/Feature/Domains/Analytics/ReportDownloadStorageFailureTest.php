<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\Membership;
use App\Models\User;
use App\Support\ObjectStorageFailure;
use Database\Seeders\AccessSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckExistence;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * UI audit P0-4: an unreachable object storage used to surface as a raw 500
 * on the report download. It must be reported to ops and answered with a
 * readable Spanish message.
 */
class ReportDownloadStorageFailureTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private User $user;

    private ReportExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $membership = Membership::where('user_id', $this->user->id)
            ->where('team_id', $this->user->currentTeam->id)
            ->firstOrFail();
        app(AssignRoleToMember::class)->execute($membership, 'analyst');

        $team = $this->user->currentTeam;
        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);

        $this->execution = ReportExecution::factory()->create([
            'report_definition_id' => $definition->id,
            'team_id' => $team->id,
            'requested_by_type' => ReportRequestedByType::User,
            'requested_by_id' => $this->user->id,
            'status' => ReportExecutionStatus::Completed,
            'output_format' => ReportOutputFormat::Pdf,
            'file_path' => "reports/{$team->id}/sample.pdf",
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('exists')
            ->andThrow(UnableToCheckExistence::forLocation((string) $this->execution->file_path));
        Storage::set('rustfs', $failing);
    }

    public function test_web_download_redirects_with_a_spanish_toast_when_storage_is_down(): void
    {
        $team = $this->user->currentTeam;

        $this->actingAs($this->user)
            ->get("/{$team->slug}/analytics/executions/{$this->execution->id}/download")
            ->assertRedirect(route('analytics.show', ['current_team' => $team]))
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'No se pudo descargar el reporte. '.ObjectStorageFailure::USER_MESSAGE);

        $this->assertSystemLogged(
            'storage.object.operation_failed',
            fn (array $c) => $c['input']['operation'] === 'report_download'
                && $c['input']['report_execution_id'] === $this->execution->id
                && $c['input']['team_id'] === $team->id
                && $c['error']['class'] === UnableToCheckExistence::class,
        );
        $entries = $this->systemLogEntries('storage.object.operation_failed');
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
    }

    public function test_api_download_returns_503_with_message_when_storage_is_down(): void
    {
        $team = $this->user->currentTeam;

        $this->actingAs($this->user)
            ->getJson("/api/{$team->slug}/analytics/reports/executions/{$this->execution->id}/download")
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'almacenamiento'));
    }
}
