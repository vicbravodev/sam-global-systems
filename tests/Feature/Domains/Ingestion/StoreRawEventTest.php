<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Ingestion\Models\EventReceipt;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class StoreRawEventTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function createTeamSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        return [$user, $team, $provider];
    }

    public function test_webhook_persists_raw_event_and_returns_200(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team, $provider] = $this->createTeamSetup();

        $payload = [
            'eventType' => 'AlertIncident',
            'eventId' => 'evt-001',
            'eventTime' => '2026-04-12T14:41:56+00:00',
            'data' => ['conditions' => [['description' => 'Forward Collision Warning']]],
        ];

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: $payload,
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'evt-001',
            transportMeta: [
                'source_ip' => '192.168.1.1',
                'user_agent' => 'Samsara-Webhook/1.0',
                'request_id' => 'req-abc-123',
            ],
        );

        $this->assertNotNull(
            $rawEvent->id,
            'RawEvent should be persisted with a valid ID after StoreRawEvent executes',
        );

        $this->assertEquals(
            RawEventStatus::Received,
            $rawEvent->status,
            'RawEvent status should be "received" immediately after persistence',
        );

        $this->assertEquals(
            $team->id,
            $rawEvent->team_id,
            'RawEvent should be scoped to the correct team',
        );

        $this->assertEquals(
            $payload,
            $rawEvent->payload_json,
            'RawEvent payload_json should be stored exactly as received — no transformation',
        );

        $this->assertEquals(
            'AlertIncident',
            $rawEvent->event_type_raw,
            'RawEvent event_type_raw should be extracted from the payload eventType field',
        );

        $this->assertNotNull(
            $rawEvent->checksum,
            'RawEvent checksum should be computed as SHA-256 of the payload',
        );

        $this->assertEquals(
            'evt-001',
            $rawEvent->deduplication_key,
            'RawEvent deduplication_key should use the externalEventId when provided',
        );

        $this->assertNotNull(
            $rawEvent->received_at,
            'RawEvent received_at timestamp should be set at persistence time',
        );
    }

    public function test_malformed_payload_is_persisted_with_malformed_status(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team] = $this->createTeamSetup();

        $malformedPayload = ['raw_body' => 'not-json-{broken}'];

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: $malformedPayload,
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: null,
        );

        $this->assertNotNull(
            $rawEvent->id,
            'Even malformed payloads must be persisted — never drop inbound events',
        );

        $this->assertEquals(
            $malformedPayload,
            $rawEvent->payload_json,
            'Malformed payload should be stored exactly as received for debugging',
        );

        $this->assertNull(
            $rawEvent->event_type_raw,
            'Malformed payload without eventType should have null event_type_raw',
        );
    }

    public function test_raw_event_received_domain_event_dispatched(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team, $provider] = $this->createTeamSetup();

        $payload = [
            'eventType' => 'AlertIncident',
            'eventId' => 'evt-dispatch-test',
        ];

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: $payload,
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'evt-dispatch-test',
        );

        Event::assertDispatched(RawEventReceived::class, function ($event) use ($rawEvent) {
            return $event->rawEvent->id === $rawEvent->id;
        });
    }

    public function test_event_receipt_records_transport_metadata(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team, $provider] = $this->createTeamSetup();

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: ['eventType' => 'TestEvent', 'eventId' => 'receipt-test'],
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'receipt-test',
            transportMeta: [
                'source_ip' => '10.0.0.5',
                'user_agent' => 'TestAgent/2.0',
                'request_id' => 'req-xyz-789',
            ],
        );

        $receipt = EventReceipt::where('raw_event_id', $rawEvent->id)->first();

        $this->assertNotNull(
            $receipt,
            'An EventReceipt should be created for every persisted RawEvent',
        );

        $this->assertEquals(
            '10.0.0.5',
            $receipt->source_ip,
            'EventReceipt should record the source IP from transport metadata',
        );

        $this->assertEquals(
            'TestAgent/2.0',
            $receipt->user_agent,
            'EventReceipt should record the user agent from transport metadata',
        );

        $this->assertEquals(
            'req-xyz-789',
            $receipt->request_id,
            'EventReceipt should record the request ID from transport metadata',
        );

        $this->assertEquals(
            'webhook',
            $receipt->received_via,
            'EventReceipt received_via should match the source type',
        );
    }

    public function test_event_source_is_resolved_or_created(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team, $provider] = $this->createTeamSetup();

        $action = app(StoreRawEvent::class);

        $action->execute(
            payload: ['eventType' => 'Test', 'eventId' => 'src-1'],
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'src-1',
        );

        $action->execute(
            payload: ['eventType' => 'Test', 'eventId' => 'src-2'],
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'src-2',
        );

        $sourceCount = EventSource::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('provider_id', $provider->id)
            ->where('source_type', 'webhook')
            ->count();

        $this->assertEquals(
            1,
            $sourceCount,
            'Multiple events from the same source type and provider should reuse the same EventSource',
        );
    }

    public function test_a_platform_event_without_team_or_provider_reuses_its_event_source(): void
    {
        Event::fake([RawEventReceived::class]);

        $action = app(StoreRawEvent::class);

        // `where('team_id', null)` del lookup compila a `IS NULL` (Laravel):
        // la fila de plataforma se reutiliza, no se crea una por evento.
        foreach (['platform-1', 'platform-2'] as $externalId) {
            $action->execute(
                payload: ['eventType' => 'Test', 'eventId' => $externalId],
                sourceType: 'internal_monitor',
                teamId: null,
                providerId: null,
                externalEventId: $externalId,
            );
        }

        $this->assertSame(1, EventSource::withoutGlobalScopes()
            ->whereNull('team_id')
            ->whereNull('provider_id')
            ->where('source_type', 'internal_monitor')
            ->count());
    }

    public function test_deduplication_key_falls_back_to_checksum_when_no_external_id(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team] = $this->createTeamSetup();

        $payload = ['eventType' => 'NoIdEvent', 'data' => ['value' => 42]];
        $expectedChecksum = hash('sha256', json_encode($payload));

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: $payload,
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: null,
        );

        $this->assertEquals(
            $expectedChecksum,
            $rawEvent->deduplication_key,
            'When no externalEventId is provided, deduplication_key should fall back to the payload checksum',
        );
    }

    public function test_unparseable_timestamp_is_persisted_with_null_occurred_at(): void
    {
        Event::fake([RawEventReceived::class]);

        [, $team, $provider] = $this->createTeamSetup();

        $action = app(StoreRawEvent::class);
        $rawEvent = $action->execute(
            payload: [
                'eventType' => 'AlertIncident',
                'eventId' => 'evt-bad-time',
                'eventTime' => 'not-a-real-timestamp',
            ],
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'evt-bad-time',
        );

        $this->assertNotNull(
            $rawEvent->id,
            'An unparseable timestamp must never drop the inbound event — it still has to be persisted',
        );

        $this->assertNull(
            $rawEvent->occurred_at,
            'When the payload timestamp cannot be parsed, occurred_at should degrade to null instead of throwing',
        );

        $this->assertEquals(
            'AlertIncident',
            $rawEvent->event_type_raw,
            'A bad timestamp should not prevent the rest of the payload from being extracted',
        );
    }

    /**
     * El stream de safety events trae la hora real en `startMs` (ISO 8601 o
     * epoch ms) y `createdAtTime`; sin mapearlos, el evento quedaba fechado a
     * la hora de recepción y la correlación ±30 min del pánico mentía.
     */
    public function test_safety_stream_events_keep_their_real_occurrence_time(): void
    {
        Event::fake([RawEventReceived::class]);
        [, $team, $provider] = $this->createTeamSetup();

        $iso = app(StoreRawEvent::class)->execute(
            payload: ['id' => 'se-1', 'startMs' => '2026-09-27T06:43:31.483Z', 'createdAtTime' => '2026-09-27T06:45:59.494Z'],
            sourceType: 'polling',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'se-1',
        );

        $epoch = app(StoreRawEvent::class)->execute(
            payload: ['id' => 'se-2', 'startMs' => 1790491411483],
            sourceType: 'polling',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'se-2',
        );

        $created = app(StoreRawEvent::class)->execute(
            payload: ['id' => 'se-3', 'createdAtTime' => '2026-09-27T06:45:59.494Z'],
            sourceType: 'polling',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: 'se-3',
        );

        $this->assertSame('2026-09-27 06:43:31', $iso->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1790491411, $epoch->occurred_at->getTimestamp());
        $this->assertSame('2026-09-27 06:45:59', $created->occurred_at->utc()->format('Y-m-d H:i:s'));
    }

    private function storeLogged(array $payload, ?string $externalEventId = null): RawEvent
    {
        Event::fake([RawEventReceived::class]);
        [, $team, $provider] = $this->createTeamSetup();

        return app(StoreRawEvent::class)->execute(
            payload: $payload,
            sourceType: 'polling',
            teamId: $team->id,
            providerId: $provider->id,
            externalEventId: $externalEventId,
        );
    }

    public function test_log_reports_the_payload_key_used_for_occurred_at(): void
    {
        $rawEvent = $this->storeLogged(['id' => 'se-log', 'startMs' => 1790491411483], 'se-log');

        $c = $this->assertSystemLogged('ingestion.raw_event.stored');
        $this->assertSame('startMs', $c['calc']['occurred_at_source']);
        $this->assertFalse($c['calc']['occurred_at_parse_failed']);
        $this->assertSame('external_event_id', $c['calc']['dedup_key_strategy']);
        $this->assertSame($rawEvent->id, $c['result']['raw_event_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_log_flags_an_unparseable_timestamp(): void
    {
        $this->storeLogged(['eventType' => 'X', 'eventTime' => 'no-es-fecha']);

        $c = $this->assertSystemLogged('ingestion.raw_event.stored');
        $this->assertTrue($c['calc']['occurred_at_parse_failed']);
        $this->assertSame('eventTime', $c['calc']['occurred_at_source']);
    }

    public function test_log_reports_no_date_source_and_checksum_strategy_without_leaking_the_checksum(): void
    {
        $rawEvent = $this->storeLogged(['eventType' => 'NoDate', 'data' => ['value' => 1]]);

        $c = $this->assertSystemLogged('ingestion.raw_event.stored');
        $this->assertNull($c['calc']['occurred_at_source']);
        $this->assertFalse($c['calc']['occurred_at_parse_failed']);
        $this->assertSame('checksum', $c['calc']['dedup_key_strategy']);
        $this->assertStringNotContainsString($rawEvent->checksum, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }
}
