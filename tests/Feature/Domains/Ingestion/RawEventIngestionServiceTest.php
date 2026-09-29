<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RawEventIngestionServiceTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_ingest_resolves_provider_from_code_and_uses_valid_source_type(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        app(RawEventIngestion::class)->ingest(
            $team->id,
            'samsara',
            'AlertIncident',
            ['eventType' => 'AlertIncident', 'eventId' => 'svc-1'],
        );

        $rawEvent = RawEvent::withoutGlobalScopes()->where('external_event_id', 'svc-1')->firstOrFail();

        $this->assertSame(
            $provider->id,
            $rawEvent->provider_id,
            'provider code "samsara" must resolve to the provider id so normalization can map the event',
        );

        $source = EventSource::withoutGlobalScopes()->findOrFail($rawEvent->event_source_id);

        $this->assertSame(
            EventSourceType::Webhook,
            $source->source_type,
            'the provider code must not leak into the EventSourceType enum column',
        );
    }

    public function test_ingest_tolerates_unknown_provider_code(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        app(RawEventIngestion::class)->ingest(
            $team->id,
            'provider_without_row',
            'AlertIncident',
            ['eventType' => 'AlertIncident', 'eventId' => 'svc-2'],
        );

        $rawEvent = RawEvent::withoutGlobalScopes()->where('external_event_id', 'svc-2')->firstOrFail();

        $this->assertNull(
            $rawEvent->provider_id,
            'an unrecognized provider code resolves to a null provider id rather than throwing',
        );
    }

    public function test_unknown_provider_code_is_logged_as_unresolved(): void
    {
        Bus::fake();

        $team = User::factory()->create()->currentTeam;

        app(RawEventIngestion::class)->ingest($team->id, 'provider_without_row', 'AlertIncident', ['eventId' => 'svc-unk']);

        $this->assertSystemLogged('ingestion.provider.unresolved', fn (array $c): bool => $c['reason'] === 'unknown_provider_code'
            && $c['outcome'] === 'degraded'
            && $c['input']['provider_code'] === 'provider_without_row'
            && $c['input']['event_type'] === 'AlertIncident'
            && $c['input']['event_type_valid'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_unresolved_provider_line_never_logs_values_that_are_not_codes(): void
    {
        Bus::fake();

        $team = User::factory()->create()->currentTeam;

        app(RawEventIngestion::class)->ingest($team->id, "bad\nprovider", "x\ninjected", ['eventId' => 'svc-inj']);

        $c = $this->assertSystemLogged('ingestion.provider.unresolved', fn (array $c): bool => $c['reason'] === 'unknown_provider_code');
        $this->assertNull($c['input']['event_type']);
        $this->assertFalse($c['input']['event_type_valid']);
        $this->assertNull($c['input']['provider_code']);
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('injected', $json);
        $this->assertStringNotContainsString('bad', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_resolution_state_payload_is_stored_with_an_explicit_dedup_key(): void
    {
        Bus::fake();

        $team = User::factory()->create()->currentTeam;
        IntegrationProvider::factory()->samsara()->create();

        app(RawEventIngestion::class)->ingest($team->id, 'samsara', 'AlertIncident', ['eventId' => 'svc-res', 'data' => ['isResolved' => true]]);

        $rawEvent = RawEvent::withoutGlobalScopes()->where('external_event_id', 'svc-res')->firstOrFail();

        $this->assertSystemLogged('ingestion.raw_event.stored', fn (array $c): bool => $c['calc']['dedup_key_strategy'] === 'explicit'
            && $c['result']['raw_event_id'] === $rawEvent->id
            && $c['input']['external_event_id'] === 'svc-res');
        $this->assertSystemNotLogged('ingestion.provider.unresolved');
        $this->assertNoSensitiveDataLogged();
    }
}
