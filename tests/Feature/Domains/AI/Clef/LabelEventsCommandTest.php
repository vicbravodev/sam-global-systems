<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class LabelEventsCommandTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, BuildsClefFixtures, RefreshDatabase;

    private const array CHOICES = ['r' => 'real', 'f' => 'falso positivo', 's' => 'saltar', 'q' => 'salir'];

    private function member(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

        return $user;
    }

    public function test_records_blind_verdicts_through_record_operator_verdict(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        $real = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent]);
        $noise = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email, '--limit' => 2])
            ->doesntExpectOutputToContain('real_event')
            ->doesntExpectOutputToContain('noise')
            ->expectsChoice('Veredicto', 'r', self::CHOICES)
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->assertSuccessful();

        $verdicts = AIEventEvaluation::withoutGlobalScopes()->whereIn('id', [$real->id, $noise->id])->get()->pluck('operator_verdict')->filter();
        $this->assertCount(2, $verdicts);
        $this->assertEqualsCanonicalizing([OperatorVerdict::Confirmed, OperatorVerdict::FalsePositive], $verdicts->values()->all());
        $this->assertSame(['etiquetado:baseline'], AIEventEvaluation::withoutGlobalScopes()->pluck('operator_verdict_note')->unique()->values()->all());
        $this->assertSystemLogged('ai.label.recorded');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_skip_and_quit_leave_events_pending_for_next_run(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        $this->makeEvaluation($team);
        $this->makeEvaluation($team);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email])
            ->expectsChoice('Veredicto', 's', self::CHOICES)
            ->expectsChoice('Veredicto', 'q', self::CHOICES)
            ->assertSuccessful();

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->whereNotNull('operator_verdict')->count());
    }

    public function test_stratifies_so_a_minority_classification_is_sampled(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        foreach (range(1, 5) as $i) {
            $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent]);
        }
        $noise = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email, '--limit' => 2])
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->assertSuccessful();

        $this->assertNotNull(AIEventEvaluation::withoutGlobalScopes()->find($noise->id)?->operator_verdict);
    }

    public function test_rejects_user_outside_the_team(): void
    {
        $team = Team::factory()->create();
        $outsider = $this->member(Team::factory()->create());

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $outsider->email])->assertFailed();
    }

    public function test_never_touches_other_tenants(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $user = $this->member($team);
        $this->makeEvaluation($other);

        $this->assertNoTenantLeak($team, fn () => $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email])
            ->expectsOutputToContain('No hay eventos pendientes')
            ->assertSuccessful());
    }
}
