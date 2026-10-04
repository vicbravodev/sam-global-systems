<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class DispatchClefShadowEvaluationTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ShadowEvaluateWithClefJob::class]);
        config([
            'ai.clef.enabled' => true,
            'ai.clef.shadow_until' => now()->addWeek()->toDateString(),
            'ai.clef.sample_rate' => 1.0,
            'services.cloudflare.account_id' => 'acc',
            'services.cloudflare.auth_token' => 'tok',
        ]);
    }

    public function test_dispatches_for_ai_text_and_hybrid_evaluations(): void
    {
        $team = Team::factory()->create();

        foreach ([EvaluationMode::AiText, EvaluationMode::Hybrid] as $mode) {
            AIEvaluationCompleted::dispatch($this->makeEvaluation($team, evaluation: ['evaluation_mode' => $mode]));
        }

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 2);
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, fn (ShadowEvaluateWithClefJob $job) => $job->teamId === $team->id && $job->source === 'live');
        $this->assertSystemLogged('ai.clef_shadow.dispatched');
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function closedGates(): array
    {
        return [
            'apagado' => [['ai.clef.enabled' => false], 'disabled'],
            'ventana vencida' => [['ai.clef.shadow_until' => '2026-01-01'], 'shadow_expired'],
            'ventana vacía' => [['ai.clef.shadow_until' => null], 'shadow_expired'],
            'sin credenciales' => [['services.cloudflare.auth_token' => null], 'missing_credentials'],
            'fuera de muestra' => [['ai.clef.sample_rate' => 0.0], 'not_sampled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('closedGates')]
    public function test_each_gate_skips_with_its_reason(array $config, string $reason): void
    {
        config($config);

        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create()));

        Queue::assertNothingPushed();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === $reason);
    }

    public function test_rules_only_evaluations_are_not_shadowed(): void
    {
        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create(), evaluation: ['evaluation_mode' => EvaluationMode::RulesOnly]));

        Queue::assertNothingPushed();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'rules_only_mode');
    }

    public function test_window_includes_its_last_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(23, 30));
        config(['ai.clef.shadow_until' => '2026-10-20']);

        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create()));

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
    }
}
