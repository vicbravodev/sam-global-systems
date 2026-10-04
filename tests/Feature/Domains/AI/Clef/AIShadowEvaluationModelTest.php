<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class AIShadowEvaluationModelTest extends TestCase
{
    use BuildsClefFixtures, RefreshDatabase;

    public function test_factory_inherits_the_tenant_of_its_evaluation(): void
    {
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        $shadow = AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $evaluation->id]);

        $this->assertSame($team->id, $shadow->team_id);
        $this->assertSame($evaluation->normalized_event_id, $shadow->normalized_event_id);
        $this->assertSame(1, TenantContext::for($team->id, fn () => $evaluation->shadowEvaluations()->count()));
    }

    public function test_scope_hides_other_tenants_rows(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($teamA)->id]);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($teamB)->id]);

        $visible = TenantContext::for($teamA->id, fn () => AIShadowEvaluation::query()->pluck('team_id')->all());

        $this->assertSame([$teamA->id], $visible);
    }
}
