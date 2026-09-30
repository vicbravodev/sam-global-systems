<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Models\Team;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Con las reglas sembradas hay varias candidatas para `AlertIncident`, así que
 * se hidratan como colección y Eloquent aplica la prevención de lazy loading
 * (activa fuera de producción). Un pánico real debe normalizarse igual: si una
 * relación de la regla o del tipo no viene precargada, el job muere y el
 * pánico se pierde.
 */
class NormalizeSeededRulesStrictLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_panic_normalizes_with_seeded_rules_under_strict_loading(): void
    {
        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $team = Team::factory()->create();
        $provider = IntegrationProvider::query()->where('code', 'samsara')->firstOrFail();

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'event_type_raw' => 'AlertIncident',
            'payload_json' => [
                'eventType' => 'AlertIncident',
                'eventId' => 'evt-strict-001',
                'data' => [
                    'conditions' => [[
                        'description' => 'Panic Button',
                        'details' => ['panicButton' => ['vehicle' => ['id' => '281474994288623', 'name' => 'ROBUST VW']]],
                    ]],
                    'happenedAtTime' => '2026-09-29T21:45:03Z',
                    'isResolved' => false,
                ],
            ],
        ]);

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertNotNull($normalized);
        $normalized->load(['eventType', 'eventSeverity', 'eventCategory']);
        $this->assertSame('panic_button', $normalized->eventType->code);
        $this->assertSame('critical', $normalized->eventSeverity->code);
        $this->assertSame('emergency', $normalized->eventCategory->code);

        // La ruta rápida de emergencia abre el incidente sin esperar a la IA.
        $this->assertTrue(Incident::query()->where('team_id', $team->id)->where('related_event_id', $normalized->id)->exists());
    }
}
