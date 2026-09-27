<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los operadores del día a día (monitorista, supervisor) usan el botón de
 * feedback/reevaluación de la IA: necesitan `ai.analysis.execute`.
 */
class AIReevaluatePermissionByRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    #[DataProvider('operatorRoles')]
    public function test_operator_roles_can_request_a_reevaluation(string $roleCode): void
    {
        Bus::fake();

        [$user, $team, $evaluation] = $this->memberWithRole($roleCode);

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/ai/evaluations/{$evaluation->id}/reevaluate", ['reason' => 'feedback'])
            ->assertStatus(202);

        Bus::assertDispatched(ReevaluateEventJob::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operatorRoles(): array
    {
        return [
            'monitorista' => ['monitorista'],
            'supervisor' => ['supervisor'],
        ];
    }

    public function test_viewer_still_cannot_request_a_reevaluation(): void
    {
        Bus::fake();

        [$user, $team, $evaluation] = $this->memberWithRole('viewer');

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/ai/evaluations/{$evaluation->id}/reevaluate", ['reason' => 'feedback'])
            ->assertForbidden();

        Bus::assertNotDispatched(ReevaluateEventJob::class);
    }

    /**
     * @return array{0: User, 1: Team, 2: AIEventEvaluation}
     */
    private function memberWithRole(string $roleCode): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);

        return [$user, $team, $evaluation];
    }
}
