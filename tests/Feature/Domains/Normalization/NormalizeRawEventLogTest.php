<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Narrativa de normalización: mapeo de tipo, severidad, resolución de activo y
 * conductor (con rechazo cross-tenant), gate de vigilancia y resumen final.
 */
class NormalizeRawEventLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private const int FOREIGN_ID = 987654;

    private int $teamId;

    private IntegrationProvider $provider;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = User::factory()->create()->currentTeam->id;
        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->medium()->create();
        $this->eventType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $this->eventType->id,
        ]);
    }

    private function providerEventFor(Asset $asset, string $type = 'MaxSpeed'): RawEvent
    {
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => "ext-{$asset->id}",
        ]);

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => $type,
            'payload_json' => ['asset' => ['id' => "ext-{$asset->id}"]],
        ]);
    }

    private function run_(RawEvent $rawEvent): void
    {
        app(NormalizeRawEvent::class)->execute($rawEvent);
    }

    public function test_a_mapped_event_of_a_monitored_asset_tells_its_whole_story(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.type.mapped');
        $this->assertSame('MaxSpeed', $c['input']['external_event_type']);
        $this->assertSame(1, $c['calc']['candidates']);
        $this->assertSame(1, $c['calc']['evaluated']);
        $this->assertSame([], $c['calc']['rejected']);
        $this->assertSame('speeding', $c['result']['event_type_code']);

        $c = $this->assertSystemLogged('normalization.asset.resolved');
        $this->assertSame('asset.id', $c['calc']['asset_path_used']);
        $this->assertTrue($c['calc']['reference_found']);
        $this->assertFalse($c['calc']['cross_tenant_rejected']);
        $this->assertSame($asset->id, $c['result']['asset_id']);

        $c = $this->assertSystemLogged('normalization.severity.resolved');
        $this->assertSame('type_default', $c['calc']['severity_source']);
        $this->assertSame('medium', $c['result']['severity_code']);

        $c = $this->assertSystemLogged('normalization.driver.resolved');
        $this->assertNull($c['calc']['driver_path_used']);
        $this->assertFalse($c['calc']['reference_found']);

        $c = $this->assertSystemLogged('normalization.event.normalized');
        $this->assertSame('mapped', $c['result']['route']);
        $this->assertSame($rawEvent->id, $c['input']['raw_event_id']);
        $this->assertSame($asset->id, $c['result']['asset_id']);
        $this->assertSame('speeding', $c['result']['event_type_code']);
        $this->assertSame('safety', $c['result']['category_code']);
        $this->assertSame('medium', $c['result']['severity_code']);
        $this->assertFalse($c['result']['unmonitored_asset']);

        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_unmonitored_asset_with_a_non_emergency_type_is_discarded_with_a_line(): void
    {
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.event.discarded', fn ($c) => $c['reason'] === 'asset_not_monitored');
        $this->assertSame($asset->id, $c['input']['asset_id']);
        $this->assertSame('speeding', $c['input']['event_type_code']);
        $this->assertSame('safety', $c['input']['category_code']);
        $this->assertFalse($c['calc']['is_emergency']);
        $this->assertSame('pending', $c['input']['monitoring_state']);
        $this->assertTrue($c['calc']['emergency_exemption_applies']);
        $this->assertSystemNotLogged('normalization.event.normalized');
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_emergency_of_an_unmonitored_asset_passes_and_says_so(): void
    {
        Event::fake([EventNormalized::class, UnmonitoredAssetEmergencyReceived::class]);
        $panic = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => EventCategory::factory()->emergency()->create()->id,
            'default_severity_id' => EventSeverity::factory()->critical()->create()->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'PanicButton',
            'mapped_event_type_id' => $panic->id,
        ]);
        $asset = Asset::factory()->excluded()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset, 'PanicButton');

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.event.emergency_unmonitored_passed');
        $this->assertTrue($c['calc']['is_emergency']);
        $this->assertSame($asset->id, $c['input']['asset_id']);
        $this->assertSame('panic_button', $c['input']['event_type_code']);
        $this->assertSame('emergency', $c['input']['category_code']);
        // El cargo real lo registra billing después (idempotente por activo y
        // día local): la línea sólo afirma lo que ya es cierto al emitirse.
        $this->assertArrayNotHasKey('billed_as_extra_asset_day', $c['result']);
        $this->assertTrue($c['result']['extra_charge_dispatched']);
        $this->assertSame('asset_local_day', $c['result']['charge_scope']);
        $this->assertNotNull($c['result']['normalized_event_id']);

        $c = $this->assertSystemLogged('normalization.event.normalized');
        $this->assertTrue($c['result']['unmonitored_asset']);
        $this->assertSystemNotLogged('normalization.event.discarded');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_foreign_asset_reference_is_rejected_without_logging_its_id(): void
    {
        Asset::factory()->count(3)->create(['team_id' => $this->teamId]);
        $victim = Team::factory()->create();
        $foreign = Asset::factory()->create(['id' => self::FOREIGN_ID, 'team_id' => $victim->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $foreign->id,
            'provider_id' => $this->provider->id,
            'external_id' => 'ext-foreign',
        ]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['vehicle' => ['id' => 'ext-foreign']],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.asset.resolved', fn ($c) => ($c['reason'] ?? null) === 'cross_tenant_reference');
        $this->assertTrue($c['calc']['cross_tenant_rejected']);
        $this->assertSame('cross_tenant_reference', $c['calc']['rejection']);
        $this->assertTrue($c['calc']['reference_found']);
        $this->assertSame('vehicle.id', $c['calc']['asset_path_used']);
        $this->assertNull($c['result']['asset_id']);
        $this->assertStringNotContainsString((string) self::FOREIGN_ID, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_foreign_driver_reference_is_rejected_without_logging_its_id(): void
    {
        $victim = Team::factory()->create();
        $foreign = Driver::factory()->create(['id' => self::FOREIGN_ID, 'team_id' => $victim->id]);
        DriverExternalReference::factory()->create([
            'driver_id' => $foreign->id,
            'provider_id' => $this->provider->id,
            'external_id' => 'ext-foreign-driver',
        ]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['driver' => ['id' => 'ext-foreign-driver']],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.driver.resolved', fn ($c) => ($c['reason'] ?? null) === 'cross_tenant_reference');
        $this->assertTrue($c['calc']['cross_tenant_rejected']);
        $this->assertSame('driver.id', $c['calc']['driver_path_used']);
        $this->assertNull($c['result']['driver_id']);
        $this->assertStringNotContainsString((string) self::FOREIGN_ID, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_type_without_rule_is_unmapped_and_falls_back_on_missing_catalog_rows(): void
    {
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'NeverSeen',
            'payload_json' => [],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.type.unmapped', fn ($c) => $c['reason'] === 'no_rule_for_type');
        $this->assertSame(0, $c['calc']['candidates']);
        $this->assertSame('NeverSeen', $c['input']['external_event_type']);

        $c = $this->assertSystemLogged('normalization.event.normalized');
        $this->assertSame('unmapped', $c['result']['route']);

        foreach ([['unmapped', 'event_types'], ['operational', 'event_categories'], ['low', 'event_severities']] as [$code, $table]) {
            $this->assertSystemLogged('normalization.catalog.fallback_used', fn ($c) => $c['reason'] === 'catalog_row_missing'
                && $c['input']['expected_code'] === $code
                && $c['input']['table'] === $table);
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_raw_event_without_provider_is_unmapped_with_no_provider(): void
    {
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'whatever',
            'payload_json' => [],
        ]);

        $this->run_($rawEvent);

        $this->assertSystemLogged('normalization.type.unmapped', fn ($c) => $c['reason'] === 'no_provider'
            && $c['input']['raw_event_id'] === $rawEvent->id);
        $c = $this->assertSystemLogged('normalization.event.normalized');
        $this->assertSame('unmapped', $c['result']['route']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rule_whose_conditions_fail_reports_only_the_failed_path(): void
    {
        EventMappingRule::query()->delete();
        $rule = EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $this->eventType->id,
            'external_conditions_json' => ['data.conditions.0.trigger' => 'expected-value'],
        ]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['data' => ['conditions' => [['trigger' => 'actual-secret-value']]]],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.type.unmapped', fn ($c) => $c['reason'] === 'conditions_not_met');
        $this->assertSame(1, $c['calc']['candidates']);
        $this->assertSame($rule->id, $c['calc']['rejected'][0]['mapping_rule_id']);
        $this->assertSame('data.conditions.0.trigger', $c['calc']['rejected'][0]['failed_path']);
        $encoded = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('actual-secret-value', $encoded);
        $this->assertStringNotContainsString('expected-value', $encoded);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_internal_event_logs_its_route_and_a_foreign_internal_asset_is_rejected(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => $asset->id]],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.internal.resolved');
        $this->assertSame('speeding', $c['input']['event_type_code']);
        $c = $this->assertSystemLogged('normalization.event.normalized');
        $this->assertSame('internal', $c['result']['route']);
        $this->assertSame($asset->id, $c['result']['asset_id']);

        $victim = Team::factory()->create();
        $foreign = Asset::factory()->create(['id' => self::FOREIGN_ID, 'team_id' => $victim->id]);
        $forged = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => $foreign->id]],
        ]);

        $this->run_($forged);

        $this->assertSystemLogged('normalization.asset.rejected', fn ($c) => $c['reason'] === 'cross_tenant_internal_asset'
            && $c['calc']['rejection'] === 'cross_tenant_internal_asset'
            && $c['input']['raw_event_id'] === $forged->id);
        $this->assertStringNotContainsString((string) self::FOREIGN_ID, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_unmonitored_internal_event_is_discarded_with_the_internal_type(): void
    {
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => $asset->id]],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.event.discarded', fn ($c) => $c['reason'] === 'asset_not_monitored');
        $this->assertSame('speeding', $c['input']['event_type_code']);
        $this->assertSame($asset->id, $c['input']['asset_id']);
        $this->assertSame('pending', $c['input']['monitoring_state']);
        $this->assertFalse($c['calc']['is_emergency']);
        $this->assertFalse($c['calc']['emergency_exemption_applies']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_internal_emergency_of_an_unmonitored_asset_is_discarded_and_the_line_says_it_was_an_emergency(): void
    {
        EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => EventCategory::factory()->emergency()->create()->id,
            'default_severity_id' => EventSeverity::factory()->critical()->create()->id,
        ]);
        $asset = Asset::factory()->excluded()->create(['team_id' => $this->teamId]);
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'panic_button',
            'payload_json' => ['internal' => ['asset_id' => $asset->id]],
        ]);

        $this->run_($rawEvent);

        // Comportamiento actual: la ruta interna descarta incluso emergencias.
        $c = $this->assertSystemLogged('normalization.event.discarded', fn ($c) => $c['reason'] === 'asset_not_monitored');
        $this->assertSame('excluded', $c['input']['monitoring_state']);
        $this->assertTrue($c['calc']['is_emergency']);
        $this->assertFalse($c['calc']['emergency_exemption_applies']);
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_trashed_own_asset_reference_is_not_reported_as_cross_tenant(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);
        $asset->delete();

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.asset.resolved', fn ($c) => ($c['reason'] ?? null) === 'referenced_asset_trashed');
        $this->assertSame('referenced_asset_trashed', $c['calc']['rejection']);
        $this->assertFalse($c['calc']['cross_tenant_rejected']);
        $this->assertTrue($c['calc']['reference_found']);
        $this->assertNull($c['result']['asset_id']);
        $this->assertStringNotContainsString('team_id', json_encode($this->systemLogEntries('normalization.asset.resolved')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_trashed_own_driver_reference_is_not_reported_as_cross_tenant(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);
        DriverExternalReference::factory()->create([
            'driver_id' => $driver->id,
            'provider_id' => $this->provider->id,
            'external_id' => 'ext-own-driver',
        ]);
        $driver->delete();
        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['driver' => ['id' => 'ext-own-driver']],
        ]);

        $this->run_($rawEvent);

        $c = $this->assertSystemLogged('normalization.driver.resolved', fn ($c) => ($c['reason'] ?? null) === 'referenced_driver_trashed');
        $this->assertSame('referenced_driver_trashed', $c['calc']['rejection']);
        $this->assertFalse($c['calc']['cross_tenant_rejected']);
        $this->assertNull($c['result']['driver_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_internal_asset_rejections_are_classified_as_trashed_or_missing(): void
    {
        $trashed = Asset::factory()->create(['team_id' => $this->teamId]);
        $trashed->delete();
        $onTrashed = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => $trashed->id]],
        ]);
        $onMissing = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => self::FOREIGN_ID]],
        ]);

        $this->run_($onTrashed);
        $this->run_($onMissing);

        $this->assertSystemLogged('normalization.asset.rejected', fn ($c) => $c['reason'] === 'internal_asset_trashed'
            && $c['calc']['rejection'] === 'internal_asset_trashed'
            && $c['input']['raw_event_id'] === $onTrashed->id);
        $this->assertSystemLogged('normalization.asset.rejected', fn ($c) => $c['reason'] === 'internal_asset_missing'
            && $c['calc']['rejection'] === 'internal_asset_missing'
            && $c['input']['raw_event_id'] === $onMissing->id);
        $this->assertSame([], array_filter(
            $this->systemLogEntries('normalization.asset.rejected'),
            fn (array $e) => $e['context']['reason'] === 'cross_tenant_internal_asset',
        ));
        $this->assertStringNotContainsString((string) self::FOREIGN_ID, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_resolution_without_any_payload_id_is_logged_at_debug_level(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $this->run_($this->providerEventFor($asset));

        $driverLine = $this->systemLogEntries('normalization.driver.resolved')[0];
        $this->assertSame('debug', $driverLine['level']);
        $this->assertNull($driverLine['context']['calc']['driver_path_used']);

        $assetLine = $this->systemLogEntries('normalization.asset.resolved')[0];
        $this->assertSame('info', $assetLine['level']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_job_logs_when_the_raw_event_is_missing_or_not_normalizable(): void
    {
        (new NormalizeEventJob(99999999))->handle(app(NormalizeRawEvent::class));

        $this->assertSystemLogged('normalization.job.skipped', fn ($c) => $c['reason'] === 'raw_event_missing'
            && $c['input']['raw_event_id'] === 99999999);

        $rawEvent = RawEvent::factory()->duplicate()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
        ]);

        (new NormalizeEventJob($rawEvent->id))->handle(app(NormalizeRawEvent::class));

        $c = $this->assertSystemLogged('normalization.job.skipped', fn ($c) => $c['reason'] === 'status_not_normalizable');
        $this->assertSame(RawEventStatus::DuplicateDetected->value, $c['input']['status']);
        $this->assertNoSensitiveDataLogged();
    }
}
