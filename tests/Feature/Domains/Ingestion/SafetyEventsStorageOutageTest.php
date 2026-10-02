<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Context\Jobs\ExtractEventMediaJob;
use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Jobs\ArchiveRawEventMediaJob;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\FakesObjectStorageOutage;
use Tests\Concerns\FakesSamsaraSafetyStream;
use Tests\TestCase;

/**
 * RustFS/S3 caído no frena la ingesta de safety events: los eventos se
 * guardan y procesan con lo que hay en DB, el cursor del poller avanza, y el
 * archivado de la media (URLs prefirmadas del payload) se reintenta aparte
 * hasta que el storage vuelve.
 */
class SafetyEventsStorageOutageTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use FakesObjectStorageOutage;
    use FakesSamsaraSafetyStream;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeObjectStorage();
        Queue::fake();
        Carbon::setTestNow('2026-09-27T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeIntegration(?Team $team = null): TenantIntegration
    {
        $team ??= User::factory()->create()->currentTeam;
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    /**
     * @return array<string, mixed>
     */
    private function eventWithMedia(string $id): array
    {
        return [
            'id' => $id,
            'time' => '2026-09-27T11:00:00Z',
            'updatedAtTime' => '2026-09-27T11:00:05Z',
            'eventState' => 'needsReview',
            'behaviorLabels' => [['label' => 'Crash']],
            'asset' => ['id' => 'vehicle-9'],
            'media' => [
                ['input' => 'dashcamRoadFacing', 'url' => "https://media.samsara.com/{$id}/road.mp4?X-Amz-Signature=presigned"],
                ['input' => 'dashcamDriverFacing', 'url' => "https://media.samsara.com/{$id}/driver.mp4?X-Amz-Signature=presigned"],
            ],
        ];
    }

    private function fakeMediaHost(): void
    {
        Http::fake([
            'media.samsara.com/*' => Http::response('binary-video-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);
    }

    private function poll(TenantIntegration $integration): void
    {
        (new PollSafetyEventsJob($integration))->handle(app(ProviderAdapter::class), app(IngestSafetyEvent::class));
    }

    private function archive(RawEvent $rawEvent): ArchiveRawEventMediaJob
    {
        $job = new ArchiveRawEventMediaJob($rawEvent->id, $rawEvent->team_id);
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_poller_ingests_every_event_and_advances_the_cursor_while_storage_is_down(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([
            ['data' => [$this->eventWithMedia('evt-1'), $this->eventWithMedia('evt-2')], 'endCursor' => 'cursor-1'],
        ]);
        $this->fakeMediaHost();
        $this->objectStorageGoesDown();

        $this->poll($integration);

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
        Queue::assertPushed(ProcessRawEventJob::class, 2);
        $this->assertSame('cursor-1', $integration->fresh()->sync_state_json['safety_events']['cursor']);
        $this->assertNull($integration->fresh()->last_error_message);
        $this->assertSame(0, RawEventAttachment::query()->count());

        Queue::assertPushed(ArchiveRawEventMediaJob::class, 2);
        Queue::assertPushed(ArchiveRawEventMediaJob::class, fn (ArchiveRawEventMediaJob $job) => $job->teamId === $integration->team_id && $job->queue === 'context');

        $this->assertSystemLogged('ingestion.media.archive_deferred', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'storage_unavailable'
            && $c['input']['raw_event_id'] !== null);
        $this->assertSystemLogged('ingestion.poll.cycle_completed');
        $this->assertNoSensitiveDataLogged();
        $this->assertNoPresignedUrlLogged();
    }

    public function test_after_the_first_storage_failure_the_rest_of_the_batch_does_not_wait_on_storage(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([
            ['data' => [$this->eventWithMedia('evt-1'), $this->eventWithMedia('evt-2'), $this->eventWithMedia('evt-3')], 'endCursor' => 'cursor-1'],
        ]);
        $this->fakeMediaHost();
        $this->objectStorageGoesDown();

        $this->poll($integration);

        // Una sola descarga intentada (la primera del lote): el resto se
        // difiere sin volver a tocar el storage caído.
        Http::assertSentCount(2);
        $this->assertCount(1, $this->systemLogEntries('ingestion.media.storage_unavailable'));
        $this->assertCount(3, $this->systemLogEntries('ingestion.media.archive_deferred'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_deferred_archive_completes_when_storage_comes_back_and_materializes_media(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeMediaHost();
        $this->objectStorageGoesDown();

        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $this->eventWithMedia('evt-1'));
        $normalized = NormalizedEvent::factory()->create(['team_id' => $integration->team_id, 'raw_event_id' => $rawEvent->id]);

        // Sigue caído: el job se re-encola solo, sin fallar ni crear filas.
        $job = $this->archive($rawEvent);
        $this->assertSame(0, RawEventAttachment::query()->count());
        $this->assertSystemLogged('ingestion.media.archive_deferred', fn (array $c) => $c['calc']['attempt'] === 1);

        $this->objectStorageComesBack();
        $this->archive($rawEvent);

        $attachments = RawEventAttachment::query()->where('raw_event_id', $rawEvent->id)->get();
        $this->assertCount(2, $attachments);
        Storage::disk('rustfs')->assertExists("teams/{$integration->team_id}/raw-events/{$rawEvent->id}/media-0-road-facing.mp4");
        Queue::assertPushed(ExtractEventMediaJob::class, fn (ExtractEventMediaJob $job) => $job->normalizedEventId === $normalized->id);
        $this->assertSystemLogged('ingestion.media.archived', fn (array $c) => $c['result']['downloaded'] === 2);

        // Idempotente: otra pasada no duplica adjuntos ni re-descarga.
        $sent = count(Http::recorded());
        $this->archive($rawEvent);
        $this->assertCount(2, RawEventAttachment::query()->where('raw_event_id', $rawEvent->id)->get());
        $this->assertCount($sent, Http::recorded());

        $this->assertNoSensitiveDataLogged();
        $this->assertNoPresignedUrlLogged();
        $this->assertTrue($job->retryUntil()->isFuture());
    }

    public function test_archive_job_for_another_tenant_aborts_without_touching_either_tenant(): void
    {
        $integration = $this->makeIntegration();
        $other = User::factory()->create()->currentTeam;
        $this->fakeMediaHost();
        $this->objectStorageGoesDown();
        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $this->eventWithMedia('evt-1'));
        $this->objectStorageComesBack();

        $this->assertNoTenantLeak($other, function () use ($rawEvent, $other): void {
            app()->call([new ArchiveRawEventMediaJob($rawEvent->id, $other->id), 'handle']);
        });

        $this->assertSame(0, RawEventAttachment::query()->count());
        $this->assertSystemLogged('ingestion.media.archive_skipped', fn (array $c) => $c['reason'] === 'team_mismatch');
    }

    public function test_archive_retry_stays_inside_the_event_tenant(): void
    {
        $integration = $this->makeIntegration();
        $otherIntegration = $this->makeIntegration();
        $this->fakeMediaHost();
        $this->objectStorageGoesDown();
        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $this->eventWithMedia('evt-1'));
        $foreign = app(IngestSafetyEvent::class)->execute($otherIntegration, $this->eventWithMedia('evt-1'));
        NormalizedEvent::factory()->create(['team_id' => $otherIntegration->team_id, 'raw_event_id' => $foreign->id]);
        $this->objectStorageComesBack();

        $this->assertNoTenantLeak($integration->team_id, function () use ($rawEvent): void {
            $this->archive($rawEvent);
        });

        $this->assertSame(2, RawEventAttachment::query()->where('raw_event_id', $rawEvent->id)->count());
        $this->assertSame(0, RawEventAttachment::query()->where('raw_event_id', $foreign->id)->count());
        Queue::assertNotPushed(ExtractEventMediaJob::class);
    }

    public function test_expired_media_url_is_given_up_without_failing_the_job(): void
    {
        $integration = $this->makeIntegration();
        $this->objectStorageGoesDown();
        Http::fake(['media.samsara.com/*' => Http::response('expired', 403)]);
        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $this->eventWithMedia('evt-1'));
        $this->objectStorageComesBack();

        $this->archive($rawEvent);

        $this->assertSame(0, RawEventAttachment::query()->count());
        $this->assertSystemLogged('ingestion.media.archived', fn (array $c) => $c['result']['failed'] === 2 && $c['result']['downloaded'] === 0);
        $this->assertNoPresignedUrlLogged();
    }

    private function assertNoPresignedUrlLogged(): void
    {
        $logged = json_encode($this->systemLogEntries());

        $this->assertIsString($logged);
        $this->assertStringNotContainsString('X-Amz-Signature', $logged);
        $this->assertStringNotContainsString('deadbeefsecret', $logged);
        $this->assertStringNotContainsString('presigned', $logged);
    }
}
