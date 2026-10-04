<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Models\EventMediaContext;
use App\Infrastructure\Storage\RustFsObjectStorage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

/**
 * Fuga sobre el camino real: el job de un tenant nunca lee ni manda a
 * Cloudflare imágenes de otro, aunque cuelguen (por error de datos) del
 * mismo evento.
 */
class ShadowEvaluateWithClefJobTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, BuildsClefFixtures, RefreshDatabase;

    public function test_job_never_reads_or_sends_another_tenants_media(): void
    {
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok', 'ai.clef.models' => ['clef'], 'ai.clef.send_images' => true]);
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);
        Storage::fake('rustfs');
        $this->app->instance(ObjectStorage::class, new RustFsObjectStorage);
        Storage::disk('rustfs')->put('victim.jpg', "\xFF\xD8\xFF\xE0".str_repeat('a', 100));

        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();
        $evaluation = $this->makeEvaluation($attacker);

        EventMediaContext::factory()->create([
            'team_id' => $victim->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'retrieval_status' => MediaRetrievalStatus::Ready,
            'storage_path' => 'victim.jpg',
        ]);

        $this->assertNoTenantLeak($attacker, fn () => ShadowEvaluateWithClefJob::dispatchSync($attacker->id, $evaluation->id));

        Http::assertSent(fn (Request $r): bool => ! array_key_exists('images', $r->data()));
        $this->assertSame([$attacker->id], AIShadowEvaluation::withoutGlobalScopes()->pluck('team_id')->all());
    }
}
