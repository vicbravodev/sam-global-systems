<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportExecutionDownloadPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_requester_in_same_team_can_download_report(): void
    {
        Storage::fake('rustfs');

        $owner = $this->newAnalyst();
        $execution = $this->createCompletedExecution($owner, requestedByUserId: $owner->id);

        $this->actingAs($owner);

        $response = $this->get($this->downloadUrl($owner->currentTeam, $execution));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_other_team_member_can_download_when_role_grants_export(): void
    {
        Storage::fake('rustfs');

        $owner = $this->newAnalyst();
        $execution = $this->createCompletedExecution($owner, requestedByUserId: $owner->id);

        $teammate = User::factory()->create();
        $this->joinTeam($teammate, $owner->currentTeam, 'analyst');

        // Activate the team that hosts the execution as the teammate's current
        // team so the BelongsToTenant scope finds the record on the very first
        // request — a navigation step that production users perform implicitly.
        $teammate->switchTeam($owner->currentTeam);

        $this->actingAs($teammate);

        $response = $this->get($this->downloadUrl($owner->currentTeam, $execution));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_user_from_different_team_cannot_download_report(): void
    {
        Storage::fake('rustfs');

        $owner = $this->newAnalyst();
        $execution = $this->createCompletedExecution($owner, requestedByUserId: $owner->id);

        $stranger = $this->newAnalyst();
        $this->actingAs($stranger);

        $response = $this->get($this->downloadUrl($owner->currentTeam, $execution));

        // EnsureTeamMembership returns 403 for users that don't belong to the
        // tenant owning the URL; if route-binding resolves first the
        // BelongsToTenant scope returns 404. Both outcomes block the download.
        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'Stranger should NOT be able to download a report from another team',
        );
        $this->assertNotSame('%PDF-1.4', substr((string) $response->getContent(), 0, 7));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        Storage::fake('rustfs');

        $owner = $this->newAnalyst();
        $execution = $this->createCompletedExecution($owner, requestedByUserId: $owner->id);

        $response = $this->getJson($this->downloadUrl($owner->currentTeam, $execution));

        $response->assertUnauthorized();
    }

    private function newAnalyst(): User
    {
        $user = User::factory()->create();
        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $user->currentTeam->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, 'analyst');

        return $user;
    }

    private function joinTeam(User $user, Team $team, string $roleCode): void
    {
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);
    }

    private function createCompletedExecution(User $owner, ?int $requestedByUserId): ReportExecution
    {
        $team = $owner->currentTeam;
        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);

        /** @var ReportExecution $execution */
        $execution = ReportExecution::withoutGlobalScopes()
            ->create([
                'report_definition_id' => $definition->id,
                'team_id' => $team->id,
                'requested_by_type' => ReportRequestedByType::User,
                'requested_by_id' => $requestedByUserId,
                'status' => ReportExecutionStatus::Completed,
                'output_format' => ReportOutputFormat::Pdf,
                'file_path' => "reports/{$team->id}/sample.pdf",
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
            ]);

        Storage::disk('rustfs')->put(
            $execution->file_path,
            "%PDF-1.4\n%minimal\n%%EOF\n",
        );

        return $execution;
    }

    private function downloadUrl(Team $team, ReportExecution $execution): string
    {
        return "/api/{$team->slug}/analytics/reports/executions/{$execution->id}/download";
    }
}
