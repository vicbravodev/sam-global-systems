<?php

namespace Tests\Feature\Domains\Context;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Contracts\ObjectStorage;
use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchDeferredEventMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        Event::fake([EventMediaAvailable::class, EventMediaFailed::class]);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
        $this->asset = Asset::factory()->create(['team_id' => $this->teamId]);
    }

    private function makeSamsaraIntegration(): TenantIntegration
    {
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $this->teamId,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        AssetExternalReference::factory()->create([
            'asset_id' => $this->asset->id,
            'provider_id' => $provider->id,
            'external_id' => 'veh-1',
        ]);

        return $integration;
    }

    private function makeRequest(?NormalizedEvent $event = null, array $attributes = []): EventMediaRequest
    {
        $event ??= NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => now()->subMinutes(2),
        ]);

        return EventMediaRequest::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'status' => MediaRequestStatus::Pending,
        ], $attributes));
    }

    private function runJob(EventMediaRequest $request): void
    {
        (new FetchDeferredEventMediaJob($request->id))->handle(
            app(MediaRetrievalAdapter::class),
            app(ObjectStorage::class),
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
            app(TenantConfigResolver::class),
        );
    }

    private function setMediaSetting(string $key, int $value): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => $key,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => $value],
            'value_type' => SettingValueType::Number,
        ]);
    }

    /**
     * Fake entry for the uploaded-media sweep (`GET /cameras/media`) the job
     * now runs on every cycle. The `?` keeps it from shadowing the retrieval
     * endpoint fakes.
     *
     * @return array<string, PromiseInterface>
     */
    private function fakeNoUploadedMedia(): array
    {
        return ['api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => []]])];
    }

    public function test_full_cycle_pending_to_completed_downloads_and_materializes_media(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                // POST: place the retrieval.
                ->push(['data' => ['retrievalId' => 'ret-1']])
                // First poll: still processing at the provider.
                ->push(['data' => ['media' => [['input' => 'dashcamRoadFacing', 'status' => 'pending']]]])
                // Second poll: clip ready.
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamRoadFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/ret-1/road.mp4'],
                ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $request = $this->makeRequest();

        // Sync queue: the job's own delayed re-dispatches run inline, driving
        // the request through sent → processing → completed in one call.
        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame('ret-1', $fresh->response_metadata_json['retrieval_id']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return true;
            }

            return $request['vehicleId'] === 'veh-1'
                && $request['inputs'] === ['dashcamRoadFacing', 'dashcamDriverFacing']
                && isset($request['startTime'], $request['endTime']);
        });

        $event = NormalizedEvent::withoutGlobalScopes()->findOrFail($request->normalized_event_id);

        $attachment = RawEventAttachment::where('raw_event_id', $event->raw_event_id)->sole();
        $this->assertSame("teams/{$this->teamId}/raw-events/{$event->raw_event_id}/deferred-road-facing.mp4", $attachment->storage_path);

        $media = EventMediaContext::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->sole();
        $this->assertSame("teams/{$this->teamId}/events/{$event->id}/media/deferred-road-facing.mp4", $media->storage_path);
        $this->assertNotNull($media->file_object_id);
        Storage::disk('rustfs')->assertExists($media->storage_path);

        Event::assertDispatched(EventMediaAvailable::class);
    }

    public function test_clip_window_honors_the_tenant_setting(): void
    {
        $this->makeSamsaraIntegration();
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_CLIP_WINDOW, 20);

        $occurredAt = Carbon::parse('2026-06-11 12:00:00', 'UTC');
        Carbon::setTestNow($occurredAt->copy()->addMinutes(5));

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                ->push(['data' => ['retrievalId' => 'ret-1']])
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamRoadFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/ret-1/road.mp4'],
                ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->runJob($this->makeRequest($event));

        Http::assertSent(function ($request) use ($occurredAt) {
            if ($request->method() !== 'POST') {
                return true;
            }

            return $request['mediaType'] === 'videoHighRes'
                && $request['startTime'] === $occurredAt->copy()->subSeconds(20)->toIso8601String()
                && $request['endTime'] === $occurredAt->copy()->addSeconds(20)->toIso8601String();
        });
    }

    public function test_clip_window_is_clamped_to_the_provider_video_cap(): void
    {
        $this->makeSamsaraIntegration();
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_CLIP_WINDOW, 120);

        $occurredAt = Carbon::parse('2026-06-11 12:00:00', 'UTC');
        Carbon::setTestNow($occurredAt->copy()->addMinutes(5));

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                ->push(['data' => ['retrievalId' => 'ret-1']])
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamRoadFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/ret-1/road.mp4'],
                ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->runJob($this->makeRequest($event));

        // Samsara caps high-res retrievals at 1 minute total: a misconfigured
        // window must clamp to 30s per side instead of getting rejected.
        Http::assertSent(function ($request) use ($occurredAt) {
            if ($request->method() !== 'POST') {
                return true;
            }

            return $request['startTime'] === $occurredAt->copy()->subSeconds(FetchDeferredEventMediaJob::MAX_CLIP_WINDOW_SECONDS)->toIso8601String()
                && $request['endTime'] === $occurredAt->copy()->addSeconds(FetchDeferredEventMediaJob::MAX_CLIP_WINDOW_SECONDS)->toIso8601String();
        });
    }

    public function test_still_request_places_one_image_retrieval_per_offset_and_completes(): void
    {
        $this->makeSamsaraIntegration();
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_STILL_COUNT, 2);
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_STILL_WINDOW, 10);

        $occurredAt = Carbon::parse('2026-06-11 12:00:00', 'UTC');
        Carbon::setTestNow($occurredAt->copy()->addMinutes(5));

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                // POST × 2: one image retrieval per still offset.
                ->push(['data' => ['retrievalId' => 'still-1']])
                ->push(['data' => ['retrievalId' => 'still-2']])
                // GET × 2: both stills ready on the first poll.
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamDriverFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/still-1/cab.jpg'],
                ]]]])
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamDriverFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/still-2/cab.jpg'],
                ]]]]),
            'media.samsara.com/*' => Http::response('still-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $request = $this->makeRequest($event, ['request_type' => MediaRequestType::FetchSnapshot]);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertCount(2, $fresh->response_metadata_json['still_retrievals']);
        $this->assertSame(2, $fresh->response_metadata_json['stills_downloaded']);
        $this->assertSame(
            [-600, 600],
            array_column($fresh->response_metadata_json['still_retrievals'], 'offset_seconds'),
        );

        // Each placement is a single instant (startTime == endTime) image request.
        Http::assertSent(function ($request) use ($occurredAt) {
            if ($request->method() !== 'POST') {
                return true;
            }

            return $request['mediaType'] === 'image'
                && $request['startTime'] === $request['endTime']
                && in_array($request['startTime'], [
                    $occurredAt->copy()->subSeconds(600)->toIso8601String(),
                    $occurredAt->copy()->addSeconds(600)->toIso8601String(),
                ], true);
        });

        $attachments = RawEventAttachment::where('raw_event_id', $event->raw_event_id)->get();
        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->every(fn ($a) => $a->attachment_type === AttachmentType::Snapshot));
        $this->assertEqualsCanonicalizing(
            [
                "teams/{$this->teamId}/raw-events/{$event->raw_event_id}/deferred-still-0-driver-facing.jpg",
                "teams/{$this->teamId}/raw-events/{$event->raw_event_id}/deferred-still-1-driver-facing.jpg",
            ],
            $attachments->pluck('storage_path')->all(),
        );

        $this->assertSame(2, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        Event::assertDispatched(EventMediaAvailable::class);
    }

    public function test_still_request_fails_when_provider_rejects_every_still(): void
    {
        $this->makeSamsaraIntegration();
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_STILL_COUNT, 3);

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::response([], 500),
        ]);

        $request = $this->makeRequest(attributes: ['request_type' => MediaRequestType::FetchSnapshot]);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Event::assertDispatched(EventMediaFailed::class);
    }

    public function test_still_request_fails_when_every_still_fails_at_the_provider(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::response([
                'data' => ['media' => [['input' => 'dashcamDriverFacing', 'status' => 'failed']]],
            ]),
        ]);

        $request = $this->makeRequest(attributes: [
            'request_type' => MediaRequestType::FetchSnapshot,
            'status' => MediaRequestStatus::Sent,
            'response_metadata_json' => ['still_retrievals' => [
                ['retrieval_id' => 'still-1', 'index' => 0, 'offset_seconds' => -600],
                ['retrieval_id' => 'still-2', 'index' => 1, 'offset_seconds' => 600],
            ]],
        ]);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Event::assertDispatched(EventMediaFailed::class);
    }

    public function test_expired_request_is_closed_without_calling_the_provider(): void
    {
        $this->makeSamsaraIntegration();
        Http::fake();

        $request = $this->makeRequest(attributes: [
            'status' => MediaRequestStatus::Sent,
            'expires_at' => now()->subMinute(),
        ]);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Expired, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        Http::assertNothingSent();
        Event::assertDispatched(EventMediaFailed::class, fn (EventMediaFailed $e) => $e->request->id === $request->id);
    }

    public function test_fails_when_no_integration_can_serve_the_asset(): void
    {
        Http::fake();

        $request = $this->makeRequest();

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Http::assertNothingSent();
        Event::assertDispatched(EventMediaFailed::class);
    }

    public function test_fails_when_provider_rejects_the_retrieval(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::response([], 500),
        ]);

        $request = $this->makeRequest();

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Event::assertDispatched(EventMediaFailed::class);
    }

    public function test_fails_when_every_clip_fails_at_the_provider(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
            ...$this->fakeNoUploadedMedia(),
            'api.samsara.com/cameras/media/retrieval*' => Http::response([
                'data' => ['media' => [['input' => 'dashcamRoadFacing', 'status' => 'failed']]],
            ]),
        ]);

        $request = $this->makeRequest(attributes: [
            'status' => MediaRequestStatus::Sent,
            'response_metadata_json' => ['retrieval_id' => 'ret-1'],
        ]);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Event::assertDispatched(EventMediaFailed::class);
    }

    public function test_handle_no_ops_when_request_already_completed(): void
    {
        Http::fake();

        $request = $this->makeRequest(attributes: [
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Completed, $request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_handle_no_ops_when_request_missing(): void
    {
        Http::fake();

        (new FetchDeferredEventMediaJob(99999))->handle(
            app(MediaRetrievalAdapter::class),
            app(ObjectStorage::class),
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
            app(TenantConfigResolver::class),
        );

        Http::assertNothingSent();
    }

    public function test_uploaded_panic_media_is_swept_and_attached_alongside_the_retrieval(): void
    {
        $this->makeSamsaraIntegration();

        $occurredAt = Carbon::parse('2026-06-11 12:00:00', 'UTC');
        Carbon::setTestNow($occurredAt->copy()->addMinutes(5));

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'videoHighRes',
                'triggerReason' => 'panicButton',
                'startTime' => '2026-06-11T11:59:50Z',
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.mp4'],
            ]]]]),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                ->push(['data' => ['retrievalId' => 'ret-1']])
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamRoadFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/ret-1/road.mp4'],
                ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertSame(1, $fresh->response_metadata_json['uploaded_media_downloaded']);

        // The sweep filters to event-evidence triggers within the still window.
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'cameras/media?')) {
                return true;
            }

            return $req['vehicleIds'] === 'veh-1'
                && str_contains($req->url(), 'triggerReasons=panicButton&triggerReasons=safetyEvent');
        });

        $uploaded = RawEventAttachment::where('raw_event_id', $event->raw_event_id)
            ->where('storage_path', 'like', '%uploaded-panicButton-20260611-115950-road-facing.mp4')
            ->sole();
        $this->assertSame(AttachmentType::Clip, $uploaded->attachment_type);
        $this->assertSame('uploaded_media', $uploaded->metadata_json['source']);
        $this->assertSame('panicButton', $uploaded->metadata_json['trigger_reason']);

        // Both the auto-uploaded clip and the retrieval clip materialize.
        $this->assertSame(2, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        Event::assertDispatched(EventMediaAvailable::class);
    }

    public function test_generic_octet_stream_mime_is_normalized_from_the_filename(): void
    {
        $this->makeSamsaraIntegration();

        $occurredAt = Carbon::parse('2026-06-11 12:00:00', 'UTC');
        Carbon::setTestNow($occurredAt->copy()->addMinutes(5));

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamDriverFacing',
                'mediaType' => 'image',
                'triggerReason' => 'panicButton',
                'startTime' => '2026-06-11T11:59:50Z',
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.jpg'],
            ]]]]),
            'api.samsara.com/cameras/media/retrieval*' => Http::sequence()
                ->push(['data' => ['retrievalId' => 'ret-1']])
                ->push(['data' => ['media' => [[
                    'input' => 'dashcamRoadFacing',
                    'status' => 'available',
                    'urlInfo' => ['url' => 'https://media.samsara.com/ret-1/road.mp4'],
                ]]]]),
            // Samsara serves binaries as octet-stream; persisting that verbatim
            // makes the multimodal agent refuse every file.
            'media.samsara.com/*' => Http::response('media-bytes', 200, ['Content-Type' => 'binary/octet-stream']),
        ]);

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $snapshot = RawEventAttachment::where('raw_event_id', $event->raw_event_id)
            ->where('storage_path', 'like', '%driver-facing.jpg')
            ->sole();
        $this->assertSame('image/jpeg', $snapshot->mime_type);

        $clip = RawEventAttachment::where('raw_event_id', $event->raw_event_id)
            ->where('storage_path', 'like', '%.mp4')
            ->sole();
        $this->assertSame('video/mp4', $clip->mime_type);

        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'video/mp4'],
            EventMediaContext::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->pluck('mime_type')
                ->all(),
        );
    }

    public function test_rejected_retrieval_completes_when_uploaded_media_backs_the_event(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'image',
                'triggerReason' => 'panicButton',
                'startTime' => '2026-06-11T12:00:00Z',
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.jpg'],
            ]]]]),
            // The provider rejects the retrieval placement outright.
            'api.samsara.com/cameras/media/retrieval*' => Http::response(['message' => 'quota exceeded'], 400),
            'media.samsara.com/*' => Http::response('still-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $request = $this->makeRequest();

        $this->runJob($request);

        $fresh = $request->fresh();

        // The alert is backed by the auto-uploaded evidence, so the request
        // closes as completed — no media failure is surfaced.
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame('uploaded_media', $fresh->response_metadata_json['completed_via']);
        $this->assertSame('Provider rejected the media retrieval request.', $fresh->response_metadata_json['retrieval_close_reason']);

        Event::assertDispatched(EventMediaAvailable::class);
        Event::assertNotDispatched(EventMediaFailed::class);
    }

    public function test_stale_event_skips_retrievals_and_fails_after_a_single_sweep(): void
    {
        $this->makeSamsaraIntegration();

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => now()->subDays(5),
        ]);

        Http::fake($this->fakeNoUploadedMedia());

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Event::assertDispatched(EventMediaFailed::class);

        // The SD footage is gone past the retention window: no retrieval call.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_stale_event_completes_when_the_sweep_finds_uploaded_media(): void
    {
        $this->makeSamsaraIntegration();

        $occurredAt = now()->subDays(5);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'videoHighRes',
                'triggerReason' => 'panicButton',
                'startTime' => $occurredAt->copy()->subSeconds(10)->toIso8601String(),
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.mp4'],
            ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertSame('uploaded_media', $fresh->response_metadata_json['completed_via']);

        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_retrieval_max_age_honors_the_tenant_setting(): void
    {
        $this->makeSamsaraIntegration();
        $this->setMediaSetting(FetchDeferredEventMediaJob::SETTING_RETRIEVAL_MAX_AGE, 1);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => now()->subHours(2),
        ]);

        Http::fake($this->fakeNoUploadedMedia());

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Failed, $request->fresh()->status);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_camera_less_asset_polls_sweep_only_without_placing_retrievals(): void
    {
        $this->makeSamsaraIntegration();
        $this->asset->update(['metadata_json' => ['has_camera' => false]]);

        Http::fake($this->fakeNoUploadedMedia());

        // Re-dispatches must not run inline: the sweep-only cycle re-queues
        // itself until uploads land or the request expires.
        Queue::fake();

        $request = $this->makeRequest();

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Processing, $request->fresh()->status);
        Queue::assertPushed(
            FetchDeferredEventMediaJob::class,
            fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id,
        );
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_camera_less_asset_completes_once_uploaded_media_lands(): void
    {
        $this->makeSamsaraIntegration();
        $this->asset->update(['metadata_json' => ['has_camera' => false]]);

        $occurredAt = now()->subMinutes(2);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'videoHighRes',
                'triggerReason' => 'panicButton',
                'startTime' => $occurredAt->copy()->subSeconds(10)->toIso8601String(),
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.mp4'],
            ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $request = $this->makeRequest($event);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertSame('uploaded_media', $fresh->response_metadata_json['completed_via']);
        $this->assertSame(1, $fresh->response_metadata_json['uploaded_media_downloaded']);

        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        Event::assertDispatched(EventMediaAvailable::class);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_sweep_only_request_never_places_a_retrieval_and_polls_uploads(): void
    {
        // Camera-equipped asset: it is the sweep_only flag (not a missing
        // camera) that suppresses the paid retrieval here.
        $this->makeSamsaraIntegration();

        Http::fake($this->fakeNoUploadedMedia());

        // The sweep-only cycle re-queues itself rather than running inline.
        Queue::fake();

        $request = $this->makeRequest(attributes: ['sweep_only' => true]);

        $this->runJob($request);

        $this->assertSame(MediaRequestStatus::Processing, $request->fresh()->status);
        Queue::assertPushed(
            FetchDeferredEventMediaJob::class,
            fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id,
        );
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_sweep_only_request_completes_when_uploaded_media_lands(): void
    {
        $this->makeSamsaraIntegration();

        $occurredAt = now()->subMinutes(2);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => $occurredAt,
        ]);

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['data' => ['media' => [[
                'input' => 'dashcamForwardFacing',
                'mediaType' => 'videoHighRes',
                'triggerReason' => 'panicButton',
                'startTime' => $occurredAt->copy()->subSeconds(10)->toIso8601String(),
                'urlInfo' => ['url' => 'https://media.samsara.com/uploads/panic.mp4'],
            ]]]]),
            'media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $request = $this->makeRequest($event, ['sweep_only' => true]);

        $this->runJob($request);

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Completed, $fresh->status);
        $this->assertSame('uploaded_media', $fresh->response_metadata_json['completed_via']);
        $this->assertSame(1, $fresh->response_metadata_json['uploaded_media_downloaded']);

        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        Event::assertDispatched(EventMediaAvailable::class);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'cameras/media/retrieval'));
    }

    public function test_failed_marks_request_failed_and_dispatches_event(): void
    {
        $request = $this->makeRequest(attributes: ['status' => MediaRequestStatus::Sent]);

        (new FetchDeferredEventMediaJob($request->id))->failed(new \RuntimeException('provider down'));

        $fresh = $request->fresh();
        $this->assertSame(MediaRequestStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);

        Event::assertDispatched(
            EventMediaFailed::class,
            fn (EventMediaFailed $e) => $e->request->id === $fresh->id && str_contains($e->reason, 'provider down'),
        );
    }
}
