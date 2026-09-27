<?php

namespace Tests\Feature\Support;

use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * El scope de tenant tiene que funcionar donde NO hay usuario autenticado:
 * colas, listeners, comandos y scheduler. Ver CLAUDE.md §2.1.
 */
class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_global_scope_filters_without_any_authenticated_user(): void
    {
        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        Incident::factory()->create(['team_id' => $teamA->id, 'title' => 'A-1']);
        Incident::factory()->create(['team_id' => $teamB->id, 'title' => 'B-1']);

        $this->assertNull(auth()->user());

        $titles = TenantContext::for($teamB, fn () => Incident::query()->pluck('title')->all());

        $this->assertSame(['B-1'], $titles);
    }

    public function test_creating_a_model_inside_the_context_stamps_the_team(): void
    {
        $team = Team::factory()->create();

        $incident = TenantContext::for($team, fn () => Incident::factory()->create(['team_id' => null]));

        $this->assertSame($team->id, $incident->team_id);
    }

    public function test_without_tenant_sees_every_team_even_when_a_user_is_logged_in(): void
    {
        $userA = User::factory()->create();
        $teamB = Team::factory()->create();

        Incident::factory()->create(['team_id' => $userA->currentTeam->id]);
        Incident::factory()->create(['team_id' => $teamB->id]);

        $this->actingAs($userA);

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, TenantContext::withoutTenant(fn () => Incident::query()->count()));
    }

    public function test_the_context_wins_over_the_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $teamB = Team::factory()->create();

        Incident::factory()->create(['team_id' => $userA->currentTeam->id, 'title' => 'A-1']);
        Incident::factory()->create(['team_id' => $teamB->id, 'title' => 'B-1']);

        $this->actingAs($userA);

        $titles = TenantContext::for($teamB, fn () => Incident::query()->pluck('title')->all());

        $this->assertSame(['B-1'], $titles);
        $this->assertSame(['A-1'], Incident::query()->pluck('title')->all());
    }

    public function test_nested_contexts_restore_the_previous_tenant(): void
    {
        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        TenantContext::for($teamA, function () use ($teamA, $teamB) {
            $this->assertSame($teamA->id, TenantContext::id());

            TenantContext::for($teamB, fn () => $this->assertSame($teamB->id, TenantContext::id()));

            $this->assertSame($teamA->id, TenantContext::id());

            TenantContext::withoutTenant(function () {
                $this->assertNull(TenantContext::id());
                $this->assertTrue(TenantContext::isSuppressed());
            });

            $this->assertSame($teamA->id, TenantContext::id());
            $this->assertFalse(TenantContext::isSuppressed());
        });

        $this->assertNull(TenantContext::id());
        $this->assertFalse(TenantContext::isSuppressed());
    }

    public function test_the_context_is_restored_even_if_the_callback_throws(): void
    {
        $team = Team::factory()->create();

        try {
            TenantContext::for($team, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertNull(TenantContext::id());
    }

    /**
     * Este es el mecanismo del que depende todo lo demás: Laravel deshidrata el
     * Context en el payload del job al despachar y lo rehidrata en el worker,
     * así que un job despachado dentro de un tenant sigue dentro de ese tenant.
     */
    public function test_the_tenant_travels_in_the_queue_payload(): void
    {
        $team = Team::factory()->create();

        $payload = TenantContext::for($team, fn () => Context::dehydrate());

        $this->assertNotNull($payload);

        Context::flush();
        $this->assertNull(TenantContext::id());

        Context::hydrate($payload);

        $this->assertSame($team->id, TenantContext::id());
    }
}
