<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Test de fuga sobre el camino REAL de la feature, como exige CLAUDE.md §2.1
 * punto 8: no el scope de Eloquent, sino el job que corre en la cola.
 *
 * Los ids de proveedor (`external_id`) son únicos platform-wide, no por tenant.
 * Si el pipeline resuelve un activo o un conductor por ese id sin comprobar a
 * quién pertenece, acaba colgando los datos de un cliente del evento de otro.
 * Eso pasó de verdad dos veces (commits c64334a y 3695360).
 */
class NormalizeEventJobTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_the_job_does_not_bind_another_tenants_asset_or_driver(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'samsara']);

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->high()->create();
        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $eventType->id,
            'is_active' => true,
        ]);

        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();

        // El activo y el conductor son del tenant "víctima"...
        $foreignAsset = Asset::factory()->create(['team_id' => $victim->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $foreignAsset->id,
            'provider_id' => $provider->id,
            'external_id' => 'ext-asset-1',
        ]);

        $foreignDriver = Driver::factory()->create(['team_id' => $victim->id]);
        DriverExternalReference::factory()->create([
            'driver_id' => $foreignDriver->id,
            'provider_id' => $provider->id,
            'external_id' => 'ext-driver-1',
        ]);

        // ...y el evento entrante, que trae esos mismos ids, es de otro tenant.
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $attacker->id,
            'provider_id' => $provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => [
                'asset' => ['id' => 'ext-asset-1'],
                'driver' => ['id' => 'ext-driver-1'],
            ],
        ]);

        $this->assertNoTenantLeak(
            $attacker,
            fn () => (new NormalizeEventJob($rawEvent->id))->handle(app(NormalizeRawEvent::class)),
        );

        $normalized = NormalizedEvent::withoutGlobalScopes()
            ->where('raw_event_id', $rawEvent->id)
            ->firstOrFail();

        $this->assertSame($attacker->id, (int) $normalized->team_id);
        $this->assertNull($normalized->asset_id, 'El activo de otro tenant no debe quedar vinculado.');
        $this->assertNull($normalized->driver_id, 'El conductor de otro tenant no debe quedar vinculado.');
    }

    public function test_the_job_runs_inside_the_tenant_of_its_own_event(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'samsara']);

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->high()->create();
        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $eventType->id,
            'is_active' => true,
        ]);

        $team = Team::factory()->create();

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $provider->id,
            'external_id' => 'ext-asset-2',
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['asset' => ['id' => 'ext-asset-2']],
        ]);

        // Sin contexto previo ni usuario autenticado: el job tiene que
        // resolver su propio tenant desde el evento que recibe.
        (new NormalizeEventJob($rawEvent->id))->handle(app(NormalizeRawEvent::class));

        $normalized = NormalizedEvent::withoutGlobalScopes()
            ->where('raw_event_id', $rawEvent->id)
            ->firstOrFail();

        $this->assertSame($asset->id, $normalized->asset_id, 'El activo propio del tenant sí debe vincularse.');
    }
}
