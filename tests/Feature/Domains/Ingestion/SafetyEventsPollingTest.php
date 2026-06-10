<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Incidents\Actions\ApplyExternalResolution;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\IngestionMeterSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SafetyEventsPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntegration(?Team $team = null, array $attributes = [], ?IntegrationProvider $provider = null): TenantIntegration
    {
        $team ??= User::factory()->create()->currentTeam;
        $provider ??= IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create(array_merge([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ], $attributes));

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function safetyEventPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt-1',
            'time' => '2026-06-10T08:00:00Z',
            'updatedAtTime' => '2026-06-10T08:00:05Z',
            'eventState' => 'needsReview',
            'behaviorLabels' => [['label' => 'Crash']],
            'asset' => ['id' => 'vehicle-9'],
            'location' => ['latitude' => 19.4326, 'longitude' => -99.1332],
            'maxAccelerationGForce' => 2.4,
        ], $overrides);
    }

    public function test_first_poll_backfills_and_persists_cursor(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();

        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response([
                'data' => [$this->safetyEventPayload()],
                'pagination' => ['endCursor' => 'cursor-abc', 'hasNextPage' => false],
            ], 200),
        ]);

        (new PollSafetyEventsJob($integration))->handle(
            app(ProviderAdapter::class),
            app(IngestSafetyEvent::class),
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'safety-events/stream')
            && str_contains($request->url(), 'startTime='));

        $rawEvent = RawEvent::withoutGlobalScopes()->where('external_event_id', 'evt-1')->sole();
        $this->assertSame($integration->team_id, $rawEvent->team_id);
        $this->assertSame('safety:evt-1:needsReview', $rawEvent->deduplication_key);
        $this->assertSame('Crash', $rawEvent->event_type_raw);
        $this->assertSame('2026-06-10T08:00:00+00:00', $rawEvent->occurred_at->toIso8601String());
        $this->assertSame(EventSourceType::PollingFeed, $rawEvent->eventSource->source_type);

        Queue::assertPushed(ProcessRawEventJob::class, 1);

        $state = $integration->fresh()->sync_state_json;
        $this->assertSame('cursor-abc', $state['safety_events']['cursor']);
        $this->assertNotNull($state['safety_events']['last_polled_at']);
    }

    public function test_poll_resumes_from_persisted_cursor(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration(attributes: [
            'sync_state_json' => ['safety_events' => ['cursor' => 'cursor-prev']],
        ]);

        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response([
                'data' => [],
                'pagination' => ['endCursor' => 'cursor-next', 'hasNextPage' => false],
            ], 200),
        ]);

        (new PollSafetyEventsJob($integration))->handle(
            app(ProviderAdapter::class),
            app(IngestSafetyEvent::class),
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=cursor-prev'));

        $this->assertSame('cursor-next', $integration->fresh()->sync_state_json['safety_events']['cursor']);
    }

    public function test_same_state_redelivery_is_marked_duplicate(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $ingest = app(IngestSafetyEvent::class);
        $detect = app(DetectDuplicateEvent::class);

        $first = $ingest->execute($integration, $this->safetyEventPayload());
        $second = $ingest->execute($integration, $this->safetyEventPayload());

        $this->assertFalse($detect->execute($first));
        $this->assertTrue($detect->execute($second));
        $this->assertSame(RawEventStatus::DuplicateDetected, $second->fresh()->status);
    }

    public function test_state_change_passes_dedup_and_dismissal_normalizes_as_resolved(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $this->seed(NormalizationSeeder::class);

        $ingest = app(IngestSafetyEvent::class);
        $detect = app(DetectDuplicateEvent::class);

        $open = $ingest->execute($integration, $this->safetyEventPayload());
        $dismissed = $ingest->execute($integration, $this->safetyEventPayload([
            'eventState' => 'dismissed',
            'updatedAtTime' => '2026-06-10T09:30:00Z',
        ]));

        $this->assertFalse($detect->execute($open), 'the original state must not be a duplicate');
        $this->assertFalse($detect->execute($dismissed), 'a state transition must pass through dedup as an update');

        $normalized = app(NormalizeRawEvent::class)->execute($dismissed);

        $this->assertSame('collision', $normalized->payload_normalized_json['event_type_code']);
        $this->assertTrue($normalized->payload_normalized_json['is_resolved']);
        $this->assertSame('2026-06-10T09:30:00Z', $normalized->payload_normalized_json['external_resolved_at']);
        $this->assertSame('dismissed', $normalized->payload_normalized_json['event_state']);

        Queue::assertPushed(
            ApplyExternalResolutionJob::class,
            fn (ApplyExternalResolutionJob $job) => $job->normalizedEventId === $normalized->id,
        );
    }

    public function test_dismissal_annotates_existing_incident_without_duplicating_it(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $team = Team::findOrFail($integration->team_id);

        $original = app(IngestSafetyEvent::class)->execute($integration, $this->safetyEventPayload());
        $originalEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $original->id,
            'payload_normalized_json' => ['event_type_code' => 'collision'],
        ]);

        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $originalEvent->id,
        ]);

        IncidentEventLink::factory()->create([
            'incident_id' => $incident->id,
            'normalized_event_id' => $originalEvent->id,
            'relation_type' => EventRelationType::RootTrigger,
        ]);

        $dismissedRaw = app(IngestSafetyEvent::class)->execute($integration, $this->safetyEventPayload([
            'eventState' => 'dismissed',
            'updatedAtTime' => '2026-06-10T09:30:00Z',
        ]));
        $dismissedEvent = app(NormalizeRawEvent::class)->execute($dismissedRaw);

        (new ApplyExternalResolutionJob($dismissedEvent->id))->handle(app(ApplyExternalResolution::class));

        $this->assertNotNull($incident->fresh()->external_resolved_at);
        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_inline_media_is_downloaded_once_into_attachments(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();

        Http::fake([
            'media.samsara.com/*' => Http::response('binary-video-bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $payload = $this->safetyEventPayload([
            'downloadForwardVideoUrl' => 'https://media.samsara.com/evt-1/forward.mp4',
        ]);

        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $payload);

        $attachment = RawEventAttachment::where('raw_event_id', $rawEvent->id)->sole();
        $expectedPath = "teams/{$integration->team_id}/raw-events/{$rawEvent->id}/forward-video.mp4";
        $this->assertSame($expectedPath, $attachment->storage_path);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame(['source_url_key' => 'downloadForwardVideoUrl'], $attachment->metadata_json);
        Storage::disk('rustfs')->assertExists($expectedPath);

        // A same-state re-delivery is a known duplicate: the expiring URL is
        // not fetched again and no second attachment is created.
        $duplicate = app(IngestSafetyEvent::class)->execute($integration, $payload);

        $this->assertSame(0, RawEventAttachment::where('raw_event_id', $duplicate->id)->count());
        Http::assertSentCount(1);
    }

    public function test_usage_is_recorded_once_per_event_state(): void
    {
        Queue::fake();

        $this->seed(IngestionMeterSeeder::class);
        $integration = $this->makeIntegration();
        $ingest = app(IngestSafetyEvent::class);

        $ingest->execute($integration, $this->safetyEventPayload());
        $ingest->execute($integration, $this->safetyEventPayload());

        $usageCount = UsageEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count();
        $this->assertSame(1, $usageCount, 'a re-delivered event must not double-bill');

        $ingest->execute($integration, $this->safetyEventPayload(['eventState' => 'dismissed']));

        $this->assertSame(2, UsageEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
    }

    public function test_events_are_scoped_to_their_own_tenant(): void
    {
        Queue::fake();

        $provider = IntegrationProvider::factory()->samsara()->create();
        $integrationA = $this->makeIntegration(provider: $provider);
        $integrationB = $this->makeIntegration(provider: $provider);

        $ingest = app(IngestSafetyEvent::class);
        $detect = app(DetectDuplicateEvent::class);

        $eventA = $ingest->execute($integrationA, $this->safetyEventPayload());
        $eventB = $ingest->execute($integrationB, $this->safetyEventPayload());

        $this->assertSame($integrationA->team_id, $eventA->team_id);
        $this->assertSame($integrationB->team_id, $eventB->team_id);
        $this->assertFalse($detect->execute($eventA));
        $this->assertFalse(
            $detect->execute($eventB),
            'the same provider event id in another tenant must not collide with this tenant\'s dedup keys',
        );
    }

    public function test_orchestrator_fans_out_only_eligible_samsara_integrations(): void
    {
        Queue::fake();

        $samsara = IntegrationProvider::factory()->samsara()->create();
        $other = IntegrationProvider::factory()->create(['code' => 'geotab', 'name' => 'Geotab']);

        $eligible = $this->makeIntegration(provider: $samsara);
        $this->makeIntegration(provider: $samsara, attributes: [
            'config_json' => ['sync' => ['poll_safety_events' => false]],
        ]);
        $this->makeIntegration(provider: $samsara, attributes: ['status' => 'inactive']);
        $this->makeIntegration(provider: $other);

        (new PollSamsaraSafetyEventsJob)->handle();

        Queue::assertPushed(PollSafetyEventsJob::class, 1);
        Queue::assertPushed(
            PollSafetyEventsJob::class,
            fn (PollSafetyEventsJob $job) => $job->integration->id === $eligible->id,
        );
    }

    public function test_severe_speeding_label_maps_to_high_severity_type(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $this->seed(NormalizationSeeder::class);

        $rawEvent = app(IngestSafetyEvent::class)->execute($integration, $this->safetyEventPayload([
            'id' => 'evt-speeding',
            'behaviorLabels' => [['label' => 'SevereSpeeding']],
        ]));

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertSame('severe_speeding', $normalized->payload_normalized_json['event_type_code']);
        $this->assertSame('high', $normalized->payload_normalized_json['severity_code']);
        $this->assertSame(
            ['latitude' => 19.4326, 'longitude' => -99.1332],
            $normalized->payload_normalized_json['location'],
        );
    }
}
