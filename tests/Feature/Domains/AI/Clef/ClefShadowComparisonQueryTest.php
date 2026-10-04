<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\AI\Queries\ClefShadowComparisonQuery;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefShadowComparisonQueryTest extends TestCase
{
    use AssertsTenantIsolation, BuildsClefFixtures, RefreshDatabase;

    private function shadow(AIEventEvaluation $evaluation, string $classification, float $pReal, string $model = 'clef'): void
    {
        $probabilities = ['real_event' => $pReal];

        if ($classification !== 'real_event') {
            $probabilities[$classification] = 1 - $pReal;
        }

        AIShadowEvaluation::factory()->create([
            'ai_event_evaluation_id' => $evaluation->id,
            'model' => $model,
            'classification' => $classification,
            'classification_probabilities_json' => $probabilities,
            'latency_ms' => 200,
            'cost_estimate' => 0.001,
        ]);
    }

    public function test_computes_safety_savings_accuracy_and_calibration(): void
    {
        $team = Team::factory()->create();

        // Real confirmado: GPT acierta, Clef también (p=0.9).
        $a = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::Confirmed]);
        $this->shadow($a, 'real_event', 0.9);
        // Real confirmado: GPT dice unclear (cuenta para recall), Clef dice noise (se le escapa).
        $b = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Unclear, 'operator_verdict' => OperatorVerdict::Confirmed]);
        $this->shadow($b, 'noise', 0.2);
        // Falso positivo confirmado: GPT dice real, Clef descarta.
        $c = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::FalsePositive]);
        $this->shadow($c, 'false_positive', 0.1);
        // Sin veredicto: sólo cuenta para concordancia.
        $d = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);
        $this->shadow($d, 'noise', 0.05);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(4, $all['clef']['n']);
        $this->assertSame(0.5, $all['clef']['agree_with_gpt']);
        $this->assertSame(1.0, $all['gpt']['recall_real']);
        $this->assertSame(0.5, $all['clef']['recall_real']);
        $this->assertSame(0.0, $all['gpt']['discard_correct']);
        $this->assertSame(1.0, $all['clef']['discard_correct']);
        $this->assertSame(round(((0.9 - 1) ** 2 + (0.2 - 1) ** 2 + (0.1 - 0) ** 2) / 3, 4), $all['clef']['brier']);
        $this->assertNull($all['gpt']['brier']);
        $this->assertSame(3, $all['clef']['verdict_n']);
    }

    public function test_reevaluated_event_counts_once_using_latest_version(): void
    {
        $team = Team::factory()->create();
        $v1 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 1, 'classification' => EventClassification::Unclear]);
        $v2 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 2, 'classification' => EventClassification::RealEvent]);
        $v2->forceFill(['normalized_event_id' => $v1->normalized_event_id])->save();
        $this->shadow($v1, 'unclear', 0.4);
        $this->shadow($v2, 'real_event', 0.9);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(1, $all['clef']['n']);
        $this->assertSame(1.0, $all['clef']['agree_with_gpt']);
    }

    public function test_verdict_on_earlier_version_scores_that_version_not_the_one_that_saw_it(): void
    {
        $team = Team::factory()->create();
        // v1: GPT dijo real; el operador la marcó falso positivo.
        $v1 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 1, 'classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::FalsePositive]);
        // v2 (reevaluación con el veredicto a la vista): GPT ahora dice falso positivo.
        $v2 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 2, 'classification' => EventClassification::FalsePositive]);
        $v2->forceFill(['normalized_event_id' => $v1->normalized_event_id])->save();
        $this->shadow($v1, 'noise', 0.1);
        $this->shadow($v2, 'false_positive', 0.1);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(1, $all['gpt']['verdict_n']);
        $this->assertSame(0.0, $all['gpt']['discard_correct']);
        $this->assertSame(1, $all['clef']['verdict_n']);
        $this->assertSame(1.0, $all['clef']['discard_correct']);
    }

    public function test_gpt_is_also_measured_on_exactly_the_events_each_model_evaluated(): void
    {
        $team = Team::factory()->create();
        $paired = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::Confirmed]);
        $this->shadow($paired, 'real_event', 0.9);
        // Sin fila de Clef (fuera de la ventana o de la muestra): GPT se equivocó.
        $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise, 'operator_verdict' => OperatorVerdict::Confirmed]);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(0.5, $all['gpt']['recall_real']);
        $this->assertSame(1, $all['gpt@clef']['n']);
        $this->assertSame(1.0, $all['gpt@clef']['recall_real']);
        $this->assertSame(1.0, $all['clef']['recall_real']);
        $this->assertSame(0, $all['gpt@clef-flash']['n']);
    }

    public function test_failed_rows_are_counted_apart(): void
    {
        $team = Team::factory()->create();
        AIShadowEvaluation::factory()->failed()->create(['ai_event_evaluation_id' => $this->makeEvaluation($team)->id]);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(0, $all['clef']['n']);
        $this->assertSame(1, $all['clef']['failed']);
    }

    public function test_team_report_never_reads_other_tenants(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $this->shadow($this->makeEvaluation($team), 'noise', 0.1);
        $this->shadow($this->makeEvaluation($other), 'noise', 0.1);

        $all = $this->assertNoTenantLeak($team, fn () => app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay()))['all'];

        $this->assertSame(1, $all['clef']['n']);
        $this->assertSame(1, $all['gpt']['n']);
    }

    public function test_platform_report_without_team_aggregates_every_tenant(): void
    {
        $this->shadow($this->makeEvaluation(Team::factory()->create()), 'noise', 0.1);
        $this->shadow($this->makeEvaluation(Team::factory()->create()), 'noise', 0.1);

        $all = app(ClefShadowComparisonQuery::class)->execute(null, now()->subDay())['all'];

        $this->assertSame(2, $all['gpt']['n']);
        $this->assertSame(2, $all['clef']['n']);
    }

    public function test_by_event_type_adds_one_bucket_per_type(): void
    {
        $team = Team::factory()->create();
        $this->shadow($this->makeEvaluation($team), 'noise', 0.1);

        $report = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay(), byEventType: true);

        $this->assertCount(2, $report);
        $this->assertArrayHasKey('all', $report);
    }
}
