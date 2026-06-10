<?php

namespace Tests\Feature\Domains\Context;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Contracts\ObjectStorage;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
        );
    }

    public function test_full_cycle_pending_to_completed_downloads_and_materializes_media(): void
    {
        $this->makeSamsaraIntegration();

        Http::fake([
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
        );

        Http::assertNothingSent();
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
