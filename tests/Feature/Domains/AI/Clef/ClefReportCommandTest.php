<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefReportCommandTest extends TestCase
{
    use BuildsClefFixtures, RefreshDatabase;

    public function test_prints_a_row_per_model_and_flags_small_samples(): void
    {
        $team = Team::factory()->create();
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($team)->id]);

        $this->artisan('ai:clef-report', ['--team' => $team->id, '--days' => 7])
            ->expectsOutputToContain('gpt')
            ->expectsOutputToContain('clef-flash')
            ->expectsOutputToContain('Muestra insuficiente')
            ->assertSuccessful();
    }
}
