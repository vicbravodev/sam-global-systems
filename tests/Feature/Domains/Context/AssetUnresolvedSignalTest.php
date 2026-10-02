<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\AI\Actions\BuildAIInputContext;
use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Support\DecisionConditionCatalog;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Enums\AssetUnresolvedReason;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Infrastructure\AI\Agents\EventClassifierAgent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un pánico cuya unidad no existe en el tenant lleva la señal explícita
 * `asset_unresolved` (+ motivo) desde la normalización hasta el prompt de la
 * IA y los facts del motor de decisiones, en vez de parecer "sin datos".
 */
class AssetUnresolvedSignalTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private IntegrationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // Sólo interesa el contexto: la IA y los incidentes tienen sus tests.
        Event::fake([EventNormalized::class, EventContextBuilt::class, UnmonitoredAssetEmergencyReceived::class]);

        $this->provider = IntegrationProvider::factory()->create(['code' => 'samsara']);

        $category = EventCategory::factory()->emergency()->create();
        $severity = EventSeverity::factory()->critical()->create();
        $type = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'panicButton',
            'mapped_event_type_id' => $type->id,
            'is_active' => true,
        ]);
    }

    public function test_unknown_vehicle_id_yields_unknown_external_id(): void
    {
        $team = Team::factory()->create();

        $snapshot = $this->runPipeline($team, ['vehicle' => ['id' => 'ext-not-synced']]);

        $this->assertSame(AssetUnresolvedReason::UnknownExternalId->value, $snapshot->normalizedEvent->payload_normalized_json['asset_unresolved_reason']);
        $this->assertTrue($snapshot->signals_json['asset_unresolved']);
        $this->assertSame('unknown_external_id', $snapshot->signals_json['asset_unresolved_reason']);

        $ctx = $this->assertSystemLogged('context.snapshot.built');
        $this->assertFalse($ctx['calc']['asset_resolved']);
        $this->assertSame('unknown_external_id', $ctx['calc']['asset_unresolved_reason']);
        $this->assertStringNotContainsString('ext-not-synced', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_payload_without_vehicle_yields_no_vehicle_in_payload(): void
    {
        $team = Team::factory()->create();

        $snapshot = $this->runPipeline($team, ['data' => ['conditions' => [['description' => 'Pánico']]]]);

        $this->assertTrue($snapshot->signals_json['asset_unresolved']);
        $this->assertSame('no_vehicle_in_payload', $snapshot->signals_json['asset_unresolved_reason']);
    }

    public function test_signal_reaches_the_ai_prompt_and_the_decision_facts(): void
    {
        $team = Team::factory()->create();

        $snapshot = $this->runPipeline($team, ['vehicle' => ['id' => 'ext-not-synced']]);
        $event = $snapshot->normalizedEvent;

        $input = TenantContext::for($team->id, fn () => app(BuildAIInputContext::class)->execute(
            $event,
            $snapshot,
            app(ResolveTenantAIProfile::class)->execute($team->id),
        ));

        $this->assertTrue($input->toArray()['context_signals']['asset_unresolved']);
        $this->assertSame('unknown_external_id', $input->toArray()['context_signals']['asset_unresolved_reason']);

        $evaluation = AIEventEvaluation::factory()->create(['team_id' => $team->id, 'normalized_event_id' => $event->id]);
        $facts = TenantContext::for($team->id, fn () => (new DecisionFactsBuilder)->build($evaluation->fresh(), $snapshot));

        $this->assertTrue($facts['asset_unresolved']);
        $this->assertSame('unknown_external_id', $facts['asset_unresolved_reason']);

        $catalogKeys = array_column(DecisionConditionCatalog::fields(), 'key');
        $this->assertContains('asset_unresolved', $catalogKeys);
        $this->assertContains('asset_unresolved_reason', $catalogKeys);
    }

    public function test_prompt_explains_that_an_unknown_unit_never_downgrades_an_emergency(): void
    {
        $instructions = (string) (new EventClassifierAgent)->instructions();

        $this->assertStringContainsString('asset_unresolved', $instructions);
        $this->assertStringContainsString('never downgrades an emergency', $instructions);
        $this->assertStringContainsString('configuration error', $instructions);
    }

    public function test_resolved_asset_has_no_unresolved_signal(): void
    {
        $team = Team::factory()->create();
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => 'ext-own',
        ]);

        $snapshot = $this->runPipeline($team, ['vehicle' => ['id' => 'ext-own']]);

        $this->assertSame($asset->id, $snapshot->normalizedEvent->asset_id);
        $this->assertArrayNotHasKey('asset_unresolved_reason', $snapshot->normalizedEvent->payload_normalized_json);
        $this->assertFalse($snapshot->signals_json['asset_unresolved']);
        $this->assertNull($snapshot->signals_json['asset_unresolved_reason']);

        $evaluation = AIEventEvaluation::factory()->create(['team_id' => $team->id, 'normalized_event_id' => $snapshot->normalized_event_id]);
        $facts = TenantContext::for($team->id, fn () => (new DecisionFactsBuilder)->build($evaluation->fresh(), $snapshot));

        $this->assertFalse($facts['asset_unresolved']);
        $this->assertNull($facts['asset_unresolved_reason']);
    }

    public function test_foreign_asset_is_rejected_without_leaking_it(): void
    {
        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();

        $foreignAsset = Asset::factory()->create(['team_id' => $victim->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $foreignAsset->id,
            'provider_id' => $this->provider->id,
            'external_id' => 'ext-victim-unit',
        ]);

        // El evento entrante es del atacante y trae el id de la unidad ajena.
        $rawEvent = $this->incomingPanic($attacker, ['vehicle' => ['id' => 'ext-victim-unit']]);

        $snapshot = $this->assertNoTenantLeak(
            $attacker,
            fn () => $this->process($rawEvent),
        );

        $this->assertNull($snapshot->normalizedEvent->asset_id);
        $this->assertTrue($snapshot->signals_json['asset_unresolved']);
        $this->assertSame('foreign_asset_rejected', $snapshot->signals_json['asset_unresolved_reason']);

        // Nada del activo ajeno viaja en el contexto ni en lo que ve la IA.
        $input = TenantContext::for($attacker->id, fn () => app(BuildAIInputContext::class)->execute(
            $snapshot->normalizedEvent,
            $snapshot,
            app(ResolveTenantAIProfile::class)->execute($attacker->id),
        ));
        $this->assertSame([], $input->toArray()['asset']);

        $visible = json_encode([
            $snapshot->signals_json,
            $snapshot->asset_snapshot_json,
            $snapshot->normalizedEvent->payload_normalized_json,
            $input->toArray()['asset'],
            $input->toArray()['context_signals'],
            $this->systemLogEntries(),
        ]);
        $this->assertStringNotContainsString('ext-victim-unit', $visible);
        $this->assertStringNotContainsString((string) $foreignAsset->name, $visible);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_media_refresh_keeps_the_signal(): void
    {
        $team = Team::factory()->create();

        $snapshot = $this->runPipeline($team, ['vehicle' => ['id' => 'ext-not-synced']]);

        $refreshed = TenantContext::for($team->id, fn () => app(RefreshContextMediaSnapshot::class)->execute($snapshot->normalized_event_id));

        $this->assertTrue($refreshed->signals_json['asset_unresolved']);
        $this->assertSame('unknown_external_id', $refreshed->signals_json['asset_unresolved_reason']);
    }

    /**
     * Normaliza un pánico entrante del tenant (job real) y construye su
     * contexto.
     *
     * @param  array<string, mixed>  $payload
     */
    private function runPipeline(Team $team, array $payload): EventContextSnapshot
    {
        return $this->process($this->incomingPanic($team, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function incomingPanic(Team $team, array $payload): RawEvent
    {
        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'panicButton',
            'payload_json' => $payload,
        ]);
    }

    private function process(RawEvent $rawEvent): EventContextSnapshot
    {
        (new NormalizeEventJob($rawEvent->id))->handle(app(NormalizeRawEvent::class));

        $event = NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $rawEvent->id)->firstOrFail();

        return TenantContext::for((int) $event->team_id, fn () => app(BuildEventContext::class)->execute($event));
    }
}
