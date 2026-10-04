<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

/**
 * `--sync` corre los jobs en el proceso, sin cola que reintente: va en su
 * propia clase porque no puede convivir con Queue::fake().
 */
class ClefBackfillSyncCommandTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    public function test_sync_mode_keeps_going_after_a_transient_error(): void
    {
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok', 'ai.clef.models' => ['clef', 'clef-flash'], 'ai.clef.send_images' => false]);
        Http::fake([
            '*/@cf/cloudflare/clef-flash' => Http::response([], 503),
            '*/@cf/cloudflare/clef' => Http::response($this->clefResponse('noise')),
        ]);
        $team = Team::factory()->create();
        $this->makeEvaluation($team);
        $this->makeEvaluation($team);

        $this->artisan('ai:clef-backfill', ['--force' => true, '--sync' => true])
            ->expectsOutputToContain('2 con error transitorio')
            ->assertSuccessful();

        $this->assertSame(2, AIShadowEvaluation::withoutGlobalScopes()->where('model', 'clef')->count());
        $this->assertSame(0, AIShadowEvaluation::withoutGlobalScopes()->where('model', 'clef-flash')->count());
        $this->assertSystemLogged('ai.clef_backfill.job_failed');
        $this->assertNoSensitiveDataLogged();
    }
}
