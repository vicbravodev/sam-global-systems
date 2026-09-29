<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Tenancy\Listeners\ChargeUnmonitoredEmergency;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: una emergencia de una unidad NO vigilada se atiende
 * siempre; ese día la unidad se cobra como tracto-día + recargo y el admin
 * recibe el aviso de uso extra. Lo que no es emergencia se sigue descartando.
 */
class UnmonitoredAssetEmergencyTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private int $teamId;

    private IntegrationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->owner = User::factory()->create();
        $this->teamId = $this->owner->currentTeam->id;
        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $emergency = EventCategory::factory()->emergency()->create();
        $safety = EventCategory::factory()->safety()->create();
        $critical = EventSeverity::factory()->critical()->create();

        $panic = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $emergency->id,
            'default_severity_id' => $critical->id,
        ]);
        $speeding = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $safety->id,
            'default_severity_id' => $critical->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'PanicButton',
            'mapped_event_type_id' => $panic->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speeding->id,
        ]);
    }

    private function rawEventFor(Asset $asset, string $type, int $teamId): RawEvent
    {
        $exists = AssetExternalReference::query()
            ->where('provider_id', $this->provider->id)
            ->where('external_id', "ext-{$asset->id}-{$type}")
            ->exists();

        if (! $exists) {
            AssetExternalReference::factory()->create([
                'asset_id' => $asset->id,
                'provider_id' => $this->provider->id,
                'external_id' => "ext-{$asset->id}-{$type}",
            ]);
        }

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => $type,
            'payload_json' => ['asset' => ['id' => "ext-{$asset->id}-{$type}"]],
        ]);
    }

    public function test_a_panic_from_a_pending_unit_is_attended_and_flagged(): void
    {
        Event::fake([EventNormalized::class, UnmonitoredAssetEmergencyReceived::class]);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->rawEventFor($asset, 'PanicButton', $this->teamId);

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertNotNull($normalized);
        $this->assertSame($asset->id, $normalized->asset_id);
        $this->assertTrue($normalized->payload_normalized_json['unmonitored_asset']);
        $this->assertSame(RawEventStatus::Processed, $rawEvent->fresh()->status);
        Event::assertDispatched(EventNormalized::class);
        Event::assertDispatched(UnmonitoredAssetEmergencyReceived::class);
    }

    public function test_a_non_emergency_from_a_pending_unit_is_still_discarded(): void
    {
        Event::fake([EventNormalized::class, UnmonitoredAssetEmergencyReceived::class]);
        $asset = Asset::factory()->excluded()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->rawEventFor($asset, 'MaxSpeed', $this->teamId);

        $this->assertNull(app(NormalizeRawEvent::class)->execute($rawEvent));
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
        Event::assertNotDispatched(UnmonitoredAssetEmergencyReceived::class);
    }

    public function test_a_panic_from_a_monitored_unit_is_not_flagged_or_surcharged(): void
    {
        Event::fake([EventNormalized::class, UnmonitoredAssetEmergencyReceived::class]);
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->rawEventFor($asset, 'PanicButton', $this->teamId);

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertArrayNotHasKey('unmonitored_asset', $normalized->payload_normalized_json);
        Event::assertNotDispatched(UnmonitoredAssetEmergencyReceived::class);
    }

    public function test_the_extra_day_is_charged_once_per_unit_and_day_and_the_admin_is_told(): void
    {
        Event::fake([EventNormalized::class]);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId, 'name' => 'Tracto 12', 'code' => 'PLACA-XYZ-99']);

        // Diez pulsaciones el mismo día = un solo recargo.
        foreach (range(1, 3) as $i) {
            $normalized = app(NormalizeRawEvent::class)->execute($this->rawEventFor($asset, 'PanicButton', $this->teamId));
            app(ChargeUnmonitoredEmergency::class)->handle(new UnmonitoredAssetEmergencyReceived($normalized));
        }

        $meterId = UsageMeter::query()->where('code', AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE)->value('id');
        $events = UsageEvent::withoutGlobalScopes()->where('usage_meter_id', $meterId)->get();

        $this->assertCount(1, $events);
        $this->assertSame($this->teamId, (int) $events->first()->team_id);
        $this->assertSame(1, (int) $events->first()->quantity);

        $notice = Notification::withoutGlobalScopes()->where('notification_type', 'billing.unmonitored_emergency')->sole();
        $this->assertSame($this->teamId, (int) $notice->team_id);
        $this->assertStringContainsString('Tracto 12', (string) $notice->subject);
        $this->assertStringContainsString('10%', (string) $notice->body_preview);

        $localDate = AssetDayPricing::localDate($normalized->occurred_at ?? now());
        $eventKey = "unmonitored_emergency:{$this->teamId}:{$asset->id}:{$localDate}";

        $this->assertCount(1, $this->systemLogEntries('billing.emergency_surcharge.charged'));
        $this->assertSystemLogged('billing.emergency_surcharge.charged', fn (array $c) => $c['input']['team_id'] === $this->teamId
            && $c['input']['asset_id'] === $asset->id
            && $c['calc']['local_date'] === $localDate
            && $c['calc']['occurred_at_source'] === 'event'
            && $c['calc']['surcharge_percent'] === 10.0
            && $c['calc']['meter_code'] === AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE
            && $c['result']['event_key'] === $eventKey
            && $c['result']['recorded'] === true);
        $this->assertCount(2, $this->systemLogEntries('billing.emergency_surcharge.skipped'));
        $this->assertSystemLogged('billing.emergency_surcharge.skipped', fn (array $c) => $c['reason'] === 'already_charged_today'
            && $c['input']['team_id'] === $this->teamId
            && $c['input']['asset_id'] === $asset->id
            && $c['calc']['local_date'] === $localDate
            && $c['result']['event_key'] === $eventKey);
        $this->assertSystemLogged('billing.emergency_surcharge.notified', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['team_id'] === $this->teamId
            && $c['input']['asset_id'] === $asset->id
            && $c['result']['notification_id'] === $notice->id
            && $c['result']['recipients_count'] >= 1);
        $notified = $this->systemLogEntries('billing.emergency_surcharge.notified');
        $this->assertSame(['ok', 'skipped', 'skipped'], array_map(fn (array $e) => $e['context']['outcome'], $notified));
        $this->assertCount(2, array_filter($notified, fn (array $e) => ($e['context']['reason'] ?? null) === 'already_notified'
            && $e['context']['input']['team_id'] === $this->teamId
            && $e['context']['input']['asset_id'] === $asset->id
            && $e['context']['result']['notification_id'] === $notice->id));

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('Tracto 12', $json);
        $this->assertStringNotContainsString('PLACA-XYZ-99', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_emergency_without_asset_is_skipped_and_said_so(): void
    {
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        Event::fake([EventNormalized::class, UnmonitoredAssetEmergencyReceived::class]);
        $normalized = app(NormalizeRawEvent::class)->execute($this->rawEventFor($asset, 'PanicButton', $this->teamId));
        $normalized->asset_id = null;

        app(ChargeUnmonitoredEmergency::class)->handle(new UnmonitoredAssetEmergencyReceived($normalized));

        $this->assertSystemLogged('billing.emergency_surcharge.skipped', fn (array $c) => $c['reason'] === 'no_asset'
            && $c['input']['team_id'] === $this->teamId
            && $c['input']['normalized_event_id'] === $normalized->id);
        $this->assertSystemNotLogged('billing.emergency_surcharge.charged');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_emergency_without_supervisors_is_charged_but_not_notified(): void
    {
        Event::fake([EventNormalized::class]);
        $orphanTeam = Team::factory()->create();
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $orphanTeam->id]);
        $normalized = app(NormalizeRawEvent::class)->execute($this->rawEventFor($asset, 'PanicButton', $orphanTeam->id));
        $this->assertSame([], IncidentSupervisors::recipients($orphanTeam->id));

        app(ChargeUnmonitoredEmergency::class)->handle(new UnmonitoredAssetEmergencyReceived($normalized));

        $this->assertSystemLogged('billing.emergency_surcharge.charged', fn (array $c) => $c['input']['team_id'] === $orphanTeam->id);
        $this->assertSystemLogged('billing.emergency_surcharge.notified', fn (array $c) => $c['reason'] === 'no_supervisors'
            && $c['input']['team_id'] === $orphanTeam->id
            && $c['input']['asset_id'] === $asset->id);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('notification_type', 'billing.unmonitored_emergency')->count());
    }

    public function test_the_surcharge_line_is_the_daily_rate_plus_ten_percent(): void
    {
        $line = AssetDayPricing::unmonitoredEmergencyLine(2, 15.0);

        $this->assertSame(10.0, $line['surcharge_percent']);
        $this->assertSame(16.5, $line['overage_unit_price']);
        $this->assertSame(33.0, $line['amount']);
    }

    public function test_charging_an_emergency_never_touches_another_tenant(): void
    {
        Event::fake([EventNormalized::class]);
        $other = Team::factory()->create();
        Asset::factory()->pendingMonitoring()->create(['team_id' => $other->id]);

        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        $normalized = app(NormalizeRawEvent::class)->execute($this->rawEventFor($asset, 'PanicButton', $this->teamId));

        $this->assertNoTenantLeak(
            $this->teamId,
            fn () => app(ChargeUnmonitoredEmergency::class)->handle(new UnmonitoredAssetEmergencyReceived($normalized)),
        );

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('team_id', $other->id)->count());

        $billing = array_filter($this->systemLogEntries(), fn (array $e) => str_starts_with($e['code'], 'billing.'));
        $this->assertNotEmpty($billing);

        foreach ($billing as $entry) {
            $this->assertSame($this->teamId, $entry['context']['input']['team_id'] ?? null, "[{$entry['code']}] sin el team propio");
            $this->assertNotSame($other->id, $entry['context']['input']['team_id'] ?? null);
        }

        $this->assertNoSensitiveDataLogged();
    }
}
