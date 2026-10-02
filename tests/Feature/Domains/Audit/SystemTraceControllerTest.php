<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Audit\Models\SystemTrace;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * `GET api/{team}/audit/traces/{traceId}`: el trace_id es un uuid que puede
 * repetirse entre tenants (o filtrarse en un log); el endpoint sólo debe
 * devolver los spans del team de la ruta.
 */
class SystemTraceControllerTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
    }

    public function test_show_returns_the_trace_spans_ordered_by_start(): void
    {
        $traceId = 'trace-feliz';
        $late = SystemTrace::factory()->create(['team_id' => $this->team->id, 'trace_id' => $traceId, 'started_at' => now()]);
        $early = SystemTrace::factory()->create(['team_id' => $this->team->id, 'trace_id' => $traceId, 'started_at' => now()->subMinute()]);
        SystemTrace::factory()->create(['team_id' => $this->team->id, 'trace_id' => 'otra-traza']);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/{$this->team->slug}/audit/traces/{$traceId}");

        $response->assertOk()->assertJsonPath('data.trace_id', $traceId);
        $this->assertSame([$early->id, $late->id], array_column($response->json('data.spans'), 'id'));
    }

    public function test_show_never_returns_spans_of_another_team_with_the_same_trace_id(): void
    {
        $traceId = 'trace-compartida';
        $foreign = SystemTrace::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'trace_id' => $traceId,
        ]);
        $own = SystemTrace::factory()->create(['team_id' => $this->team->id, 'trace_id' => $traceId]);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->owner)->getJson("/api/{$this->team->slug}/audit/traces/{$traceId}"),
        );

        $response->assertOk();
        $ids = array_column($response->json('data.spans'), 'id');
        $this->assertSame([$own->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame([$this->team->id], array_values(array_unique(array_column($response->json('data.spans'), 'team_id'))));
    }

    public function test_show_of_a_foreign_only_trace_returns_no_spans(): void
    {
        $intruder = User::factory()->create();
        $span = SystemTrace::factory()->create(['team_id' => $this->team->id, 'trace_id' => 'trace-de-a']);

        $response = $this->assertNoTenantLeak(
            $intruder->currentTeam,
            fn () => $this->actingAs($intruder)->getJson("/api/{$intruder->currentTeam->slug}/audit/traces/trace-de-a"),
        );

        $response->assertOk()->assertJsonPath('data.spans', []);
        $this->assertNotContains($span->id, array_column($response->json('data.spans'), 'id'));

        $this->actingAs($intruder)
            ->getJson("/api/{$this->team->slug}/audit/traces/trace-de-a")
            ->assertForbidden();
    }

    public function test_role_without_audit_view_is_forbidden(): void
    {
        $billing = User::factory()->create();
        $team = $billing->currentTeam;
        app(AssignRoleToMember::class)->execute(
            Membership::query()->where('user_id', $billing->id)->where('team_id', $team->id)->firstOrFail(),
            'billing_manager',
        );

        $this->actingAs($billing->refresh())
            ->getJson("/api/{$team->slug}/audit/traces/cualquiera")
            ->assertForbidden();
    }
}
