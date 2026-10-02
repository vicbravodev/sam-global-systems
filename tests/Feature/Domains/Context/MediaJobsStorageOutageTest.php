<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Jobs\ExtractEventMediaJob;
use App\Domains\Context\Jobs\ExtractVideoFramesJob;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Support\VideoFrameExtractor;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\FakesObjectStorageOutage;
use Tests\TestCase;

/**
 * Los jobs de media que leen/escriben en RustFS/S3 no pierden la media ni
 * gastan sus intentos con el storage caído: se re-encolan con backoff y
 * completan cuando vuelve.
 */
class MediaJobsStorageOutageTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use FakesObjectStorageOutage;
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeObjectStorage();
        Event::fake([EventMediaAvailable::class, EventMediaFailed::class]);
        $this->team = User::factory()->create()->currentTeam;
    }

    private function eventWithRawClip(Team $team): NormalizedEvent
    {
        $rawEvent = RawEvent::factory()->create(['team_id' => $team->id]);
        $path = "teams/{$team->id}/raw-events/{$rawEvent->id}/media-0-road-facing.mp4";

        RawEventAttachment::factory()->create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Clip,
            'mime_type' => 'video/mp4',
            'storage_path' => $path,
        ]);
        Storage::disk('rustfs')->put($path, 'clip-bytes');

        return NormalizedEvent::factory()->create(['team_id' => $team->id, 'raw_event_id' => $rawEvent->id]);
    }

    public function test_extract_event_media_is_released_while_storage_is_down_and_completes_when_it_returns(): void
    {
        $event = $this->eventWithRawClip($this->team);
        $foreign = $this->eventWithRawClip(User::factory()->create()->currentTeam);
        $this->objectStorageGoesDown();

        $job = (new ExtractEventMediaJob($event->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(ExtractEventMediaJob::objectStorageRetryDelay(1));
        $job->assertNotFailed();
        $this->assertSame(0, EventMediaContext::withoutGlobalScopes()->count());
        $this->assertSystemLogged('media.event_media.extract_deferred', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'storage_unavailable'
            && $c['input']['normalized_event_id'] === $event->id
            && $c['calc']['retry_in_seconds'] === 60);

        $this->objectStorageComesBack();

        $this->assertNoTenantLeak($this->team, function () use ($event): void {
            app()->call([new ExtractEventMediaJob($event->id), 'handle']);
        });

        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        $this->assertSame(0, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $foreign->id)->count());
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('X-Amz-Signature', (string) json_encode($this->systemLogEntries()));
    }

    public function test_extract_event_media_still_throws_errors_that_are_not_storage(): void
    {
        $event = $this->eventWithRawClip($this->team);
        $this->mock(AttachImmediateEventMedia::class)->shouldReceive('execute')->andThrow(new RuntimeException('bug'));

        $job = new ExtractEventMediaJob($event->id);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->retryUntil()->getTimestamp() > now()->getTimestamp());

        $this->expectException(RuntimeException::class);

        app()->call([$job, 'handle']);
    }

    public function test_video_frames_are_released_while_storage_is_down(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $clip = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Clip,
            'media_role' => MediaRole::PrimaryEvidence,
            'storage_path' => "teams/{$this->team->id}/events/{$event->id}/media/clip.mp4",
            'mime_type' => 'video/mp4',
        ]);
        $this->mock(VideoFrameExtractor::class)->shouldReceive('isAvailable')->andReturnTrue();
        $this->objectStorageGoesDown();

        $job = (new ExtractVideoFramesJob($clip->id, $this->team->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(60);
        $this->assertSystemLogged('media.frames.extract_deferred', fn (array $c) => $c['reason'] === 'storage_unavailable'
            && $c['input']['media_context_id'] === $clip->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_panic_media_request_stays_in_flight_while_storage_is_down(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);
        $provider = IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $this->team->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);
        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $provider->id, 'external_id' => 'veh-1']);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $asset->id,
            'occurred_at' => now()->subMinute(),
        ]);
        $request = EventMediaRequest::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'status' => MediaRequestStatus::Pending,
            'sweep_only' => true,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'videoHighRes',
                'triggerReason' => 'panicButton',
                'startTime' => now()->subMinute()->toIso8601String(),
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.mp4?X-Amz-Signature=presigned'],
            ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);
        $this->objectStorageGoesDown();

        $job = (new FetchDeferredEventMediaJob($request->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(60);
        $job->assertNotFailed();
        $this->assertTrue($request->fresh()->status->isInFlight());
        Event::assertNotDispatched(EventMediaFailed::class);
        $this->assertSystemLogged('media.deferred.storage_unavailable', fn (array $c) => $c['reason'] === 'storage_unavailable'
            && $c['input']['event_media_request_id'] === $request->id);

        $this->objectStorageComesBack();
        app()->call([new FetchDeferredEventMediaJob($request->id), 'handle']);

        $this->assertSame(MediaRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('X-Amz-Signature', (string) json_encode($this->systemLogEntries()));
    }
}
