<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ShadowEvaluateWithClefJobTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.cloudflare.account_id' => 'acc',
            'services.cloudflare.auth_token' => 'tok',
            'ai.clef.models' => ['clef', 'clef-flash'],
            'ai.clef.send_images' => false,
        ]);
    }

    public function test_persists_one_row_per_model_without_touching_the_official_evaluation(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $before = $evaluation->fresh()?->toArray();

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $rows = AIShadowEvaluation::withoutGlobalScopes()->orderBy('model')->get();
        $this->assertSame(['clef', 'clef-flash'], $rows->pluck('model')->all());
        $this->assertSame(['noise', 'noise'], $rows->pluck('classification')->all());
        $this->assertSame([$team->id], $rows->pluck('team_id')->unique()->values()->all());
        $this->assertSame($before, $evaluation->fresh()?->toArray());
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('ai.clef_shadow.completed', fn (array $c) => $c['input']['model'] === 'clef' && $c['calc']['matches_gpt'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retry_after_partial_failure_does_not_duplicate_or_repay(): void
    {
        Http::fake([
            '*/@cf/cloudflare/clef-flash' => Http::sequence()->push([], 503)->push($this->clefResponse('noise')),
            '*/@cf/cloudflare/clef' => Http::response($this->clefResponse('real_event')),
        ]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        try {
            ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);
            $this->fail('El 503 debe relanzarse para que la cola reintente');
        } catch (ClefRequestFailedException $e) {
            $this->assertSame('http_503', $e->reason);
        }
        $this->assertSame(['clef'], AIShadowEvaluation::withoutGlobalScopes()->pluck('model')->all());

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $this->assertSame(2, AIShadowEvaluation::withoutGlobalScopes()->count());
        Http::assertSentCount(3);
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'already_evaluated');
    }

    public function test_retryable_failed_row_is_retried_and_becomes_success(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $evaluation->id, 'model' => 'clef']);
        AIShadowEvaluation::factory()->failed('http_429', retryable: true)->create(['ai_event_evaluation_id' => $evaluation->id, 'model' => 'clef-flash']);

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        Http::assertSentCount(1);
        $this->assertSame(2, AIShadowEvaluation::withoutGlobalScopes()->count());
        $this->assertSame(
            AIShadowEvaluation::STATUS_SUCCESS,
            AIShadowEvaluation::withoutGlobalScopes()->where('model', 'clef-flash')->value('status'),
        );
    }

    public function test_permanent_failed_row_is_not_retried(): void
    {
        Http::fake();
        config(['ai.clef.models' => ['clef']]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->failed('http_400')->create(['ai_event_evaluation_id' => $evaluation->id, 'model' => 'clef']);

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        Http::assertNothingSent();
    }

    public function test_non_retryable_failure_persists_failed_row(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([], 400)]);
        config(['ai.clef.models' => ['clef']]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $row = AIShadowEvaluation::withoutGlobalScopes()->sole();
        $this->assertSame(AIShadowEvaluation::STATUS_FAILED, $row->status);
        $this->assertSame('http_400', $row->error_code);
        $this->assertFalse($row->retryable);
        $this->assertSystemLogged('ai.clef_shadow.failed');
    }

    public function test_aborts_when_team_does_not_match(): void
    {
        Http::fake();
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        ShadowEvaluateWithClefJob::dispatchSync($other->id, $evaluation->id);

        Http::assertNothingSent();
        $this->assertSame(0, AIShadowEvaluation::withoutGlobalScopes()->count());
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'team_mismatch');
    }

    public function test_skips_when_snapshot_is_missing(): void
    {
        Http::fake();
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $evaluation->inferenceLogs()->delete();

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        Http::assertNothingSent();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'no_snapshot');
    }
}
