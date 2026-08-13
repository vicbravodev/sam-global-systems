<?php

namespace Tests\Feature\Support;

use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Support\TenantContext;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un helper de tests que no puede fallar no protege de nada: aquí se comprueba
 * que `assertNoTenantLeak` detecta de verdad las dos formas de fuga.
 */
class AssertsTenantIsolationTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_it_passes_when_the_code_stays_inside_its_tenant(): void
    {
        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        Incident::factory()->create(['team_id' => $teamA->id]);

        $incident = $this->assertNoTenantLeak(
            $teamB,
            fn () => Incident::factory()->create(['team_id' => $teamB->id]),
        );

        $this->assertSame($teamB->id, $incident->team_id);
    }

    public function test_it_catches_a_write_into_another_tenant(): void
    {
        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        $foreign = Incident::factory()->create(['team_id' => $teamA->id, 'title' => 'de A']);

        try {
            $this->assertNoTenantLeak($teamB, function () use ($foreign) {
                // Escritura sobre datos de otro tenant: exactamente lo que un
                // resolver mal filtrado acaba haciendo.
                Incident::withoutGlobalScopes()
                    ->whereKey($foreign->id)
                    ->update(['title' => 'pisado desde B']);
            });
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Fuga cross-tenant', $failure->getMessage());
            $this->assertStringContainsString('incidents', $failure->getMessage());

            return;
        }

        $this->fail('assertNoTenantLeak no detectó una escritura en otro tenant.');
    }

    public function test_it_catches_a_read_of_another_tenants_model(): void
    {
        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        $foreign = Incident::factory()->create(['team_id' => $teamA->id]);

        try {
            $this->assertNoTenantLeak(
                $teamB,
                fn () => Incident::withoutGlobalScopes()->findOrFail($foreign->id),
            );
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Fuga cross-tenant', $failure->getMessage());

            return;
        }

        $this->fail('assertNoTenantLeak no detectó la lectura de un modelo de otro tenant.');
    }

    public function test_it_runs_the_callback_inside_the_tenant_context(): void
    {
        $team = Team::factory()->create();

        $seen = $this->assertNoTenantLeak($team, fn () => TenantContext::id());

        $this->assertSame($team->id, $seen);
    }
}
