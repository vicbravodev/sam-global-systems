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
use Tests\TestCase;

class RawEventIngestionServiceTest extends TestCase
{
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
            'provider-without-row',
            'AlertIncident',
            ['eventType' => 'AlertIncident', 'eventId' => 'svc-2'],
        );

        $rawEvent = RawEvent::withoutGlobalScopes()->where('external_event_id', 'svc-2')->firstOrFail();

        $this->assertNull(
            $rawEvent->provider_id,
            'an unrecognized provider code resolves to a null provider id rather than throwing',
        );
    }
}
