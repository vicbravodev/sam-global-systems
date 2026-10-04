<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Ingestion\Actions\AlertPipelineFailure;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Notifications\PipelineFailureNotification;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Una alerta del proveedor (AlertIncident) que ninguna regla reconoce se
 * normaliza como `unmapped` y no abre incidente: puede ser un pánico
 * malformado. Nunca se pierde en silencio: se avisa a plataforma y a los
 * owners/admins del tenant del evento, una sola vez por raw event.
 */
class UnmappedProviderAlertTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $superAdmin;

    private User $ownerA;

    private User $adminA;

    private User $memberA;

    private User $ownerB;

    private Team $teamA;

    private Team $teamB;

    private IntegrationProvider $samsara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['global_role' => 'super_admin'])->save();

        $this->ownerA = User::factory()->create();
        $this->teamA = $this->ownerA->currentTeam;
        $this->adminA = User::factory()->create();
        $this->teamA->members()->attach($this->adminA, ['role' => TeamRole::Admin->value]);
        $this->memberA = User::factory()->create();
        $this->teamA->members()->attach($this->memberA, ['role' => TeamRole::Member->value]);

        $this->ownerB = User::factory()->create();
        $this->teamB = $this->ownerB->currentTeam;

        $emergency = EventCategory::factory()->emergency()->create();
        $operational = EventCategory::factory()->operational()->create();
        $low = EventSeverity::factory()->low()->create();
        $critical = EventSeverity::factory()->critical()->create();

        EventType::factory()->create([
            'code' => 'unmapped',
            'category_id' => $operational->id,
            'default_severity_id' => $low->id,
        ]);
        $panic = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $emergency->id,
            'default_severity_id' => $critical->id,
        ]);

        $this->samsara = IntegrationProvider::factory()->samsara()->create();

        // La regla real de pánico exige condiciones: un AlertIncident sin
        // `data.conditions` no la cumple y cae a `unmapped`.
        EventMappingRule::factory()->create([
            'provider_id' => $this->samsara->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => ['data.conditions.0.description' => 'Panic Button'],
            'mapped_event_type_id' => $panic->id,
            'priority' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rawEvent(Team $team, string $type = 'AlertIncident', array $payload = []): RawEvent
    {
        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $this->samsara->id,
            'event_type_raw' => $type,
            'payload_json' => $payload ?: [
                'eventType' => $type,
                'eventId' => 'evt-malformed',
                'data' => ['happenedAtTime' => '2026-09-30T10:00:00+00:00', 'driverPhone' => '+5215512345678'],
            ],
        ]);
    }

    public function test_an_alert_incident_without_conditions_alerts_the_platform_and_the_event_tenant_only(): void
    {
        Notification::fake();
        $raw = $this->rawEvent($this->teamA);

        $this->assertNoTenantLeak($this->teamA, fn () => (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class)));

        $normalized = NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $raw->id)->sole();
        $this->assertSame('unmapped', $normalized->status->value);

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class, function (PipelineFailureNotification $n) use ($raw) {
            return $n->audience === PipelineFailureNotification::AUDIENCE_PLATFORM
                && $n->details['kind'] === PipelineFailureAlert::KIND_UNMAPPED_ALERT
                && $n->details['stage'] === 'normalization.unmapped_alert'
                && $n->details['team_id'] === $this->teamA->id
                && $n->details['raw_event_id'] === $raw->id
                && $n->details['external_event_type'] === 'AlertIncident'
                && $n->details['is_emergency'] === true;
        });
        Notification::assertSentTo([$this->ownerA, $this->adminA], PipelineFailureNotification::class, fn (PipelineFailureNotification $n) => $n->audience === PipelineFailureNotification::AUDIENCE_TENANT);
        Notification::assertNotSentTo([$this->memberA, $this->ownerB], PipelineFailureNotification::class);

        $alert = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertSame($this->teamA->id, (int) $alert->team_id);
        $this->assertSame(PipelineFailureAlert::KIND_UNMAPPED_ALERT, $alert->kind);
        $this->assertSame($raw->id, (int) $alert->raw_event_id);
        $this->assertTrue($alert->is_emergency);
        $this->assertSame(2, $alert->tenant_recipients);

        $c = $this->assertSystemLogged('normalization.unmapped_alert.escalated');
        $this->assertSame('AlertIncident', $c['input']['external_event_type']);
        $this->assertSame($raw->id, $c['input']['raw_event_id']);
        $this->assertSame('unreadable', $c['calc']['trigger_class']);
        $sent = $this->assertSystemLogged('ingestion.failure_alert.sent');
        $this->assertSame(PipelineFailureAlert::KIND_UNMAPPED_ALERT, $sent['input']['kind']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_alert_never_carries_the_raw_payload(): void
    {
        Notification::fake();
        $raw = $this->rawEvent($this->teamA);

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class, function (PipelineFailureNotification $n) {
            $mail = $n->toMail($this->superAdmin);
            $text = $mail->subject.implode("\n", $mail->introLines).json_encode($n->toArray($this->superAdmin));

            return ! str_contains($text, '5512345678')
                && ! str_contains($text, 'evt-malformed')
                && str_contains($mail->subject, 'AlertIncident');
        });
        Notification::assertSentTo($this->ownerA, PipelineFailureNotification::class, function (PipelineFailureNotification $n) {
            $data = $n->toArray($this->ownerA);

            return ! array_key_exists('stage', $data) && ! array_key_exists('kind', $data);
        });
    }

    public function test_the_same_raw_event_alerts_only_once(): void
    {
        Notification::fake();
        $raw = $this->rawEvent($this->teamA);
        $normalize = app(NormalizeRawEvent::class);

        (new NormalizeEventJob($raw->id))->handle($normalize);
        (new NormalizeEventJob($raw->id))->handle($normalize);

        Notification::assertSentToTimes($this->superAdmin, PipelineFailureNotification::class, 1);
        Notification::assertSentToTimes($this->ownerA, PipelineFailureNotification::class, 1);
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->count());

        $c = $this->assertSystemLogged('ingestion.failure_alert.skipped');
        $this->assertSame('already_alerted', $c['reason']);
    }

    public function test_an_unmapped_non_alert_type_does_not_alert(): void
    {
        Notification::fake();
        $raw = $this->rawEvent($this->teamA, 'SomeUnknownLabel', ['behaviorLabels' => [['label' => 'SomeUnknownLabel']]]);

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        Notification::assertNothingSent();
        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->count());

        $c = $this->assertSystemLogged('normalization.unmapped_alert.skipped');
        $this->assertSame('not_alert_type', $c['reason']);
        $this->assertSame('SomeUnknownLabel', $c['input']['external_event_type']);
    }

    public function test_the_alert_types_are_configurable(): void
    {
        Notification::fake();
        config(['pipeline.unmapped_alert_types' => ['PanicLike']]);

        $alertIncident = $this->rawEvent($this->teamA);
        $panicLike = $this->rawEvent($this->teamA, 'PanicLike', ['eventType' => 'PanicLike']);
        $normalize = app(NormalizeRawEvent::class);

        (new NormalizeEventJob($alertIncident->id))->handle($normalize);
        (new NormalizeEventJob($panicLike->id))->handle($normalize);

        $alert = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertSame($panicLike->id, (int) $alert->raw_event_id);
    }

    public function test_an_alert_incident_that_maps_does_not_alert(): void
    {
        Notification::fake();
        Event::fake([EventNormalized::class]);
        $raw = $this->rawEvent($this->teamA, 'AlertIncident', [
            'eventType' => 'AlertIncident',
            'data' => ['conditions' => [['description' => 'Panic Button']]],
        ]);

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->count());
        Notification::assertNotSentTo($this->superAdmin, PipelineFailureNotification::class);
    }

    public function test_a_failing_alert_never_breaks_the_normalization_job(): void
    {
        $this->app->instance(AlertPipelineFailure::class, new class extends AlertPipelineFailure
        {
            public function __construct() {}

            public function forUnmappedAlert(RawEvent $rawEvent, string $externalEventType): void
            {
                throw new \RuntimeException('smtp caído');
            }
        });

        $raw = $this->rawEvent($this->teamA);

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        $normalized = NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $raw->id)->sole();
        $this->assertSame('unmapped', $normalized->status->value);

        $failed = $this->assertSystemLogged('normalization.unmapped_alert.failed');
        $this->assertSame('exception', $failed['reason']);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array<string, mixed>
     */
    private function alertIncident(array $conditions): array
    {
        return [
            'eventType' => 'AlertIncident',
            'eventId' => 'evt-'.count($conditions),
            'data' => ['happenedAtTime' => '2026-10-04T10:00:00Z', 'conditions' => $conditions],
        ];
    }

    public function test_a_recognized_non_emergency_alert_is_recorded_without_alerting(): void
    {
        Notification::fake();
        Event::fake([EventNormalized::class]);
        // Geocerca configurada por el cliente en Samsara: sin regla en SAM.
        $raw = $this->rawEvent($this->teamA, payload: $this->alertIncident([
            ['triggerId' => 5016, 'description' => 'Geofence Entry'],
        ]));

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        // Queda en "Sin mapear" para que alguien cree la regla…
        $normalized = NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $raw->id)->sole();
        $this->assertSame('unmapped', $normalized->status->value);
        $this->assertSame('AlertIncident', $normalized->payload_normalized_json['external_event_type']);
        $this->assertSame([5016], $normalized->payload_normalized_json['provider_trigger_ids']);

        // …pero no se avisa como posible pánico, ni pasa por la IA (un
        // unmapped nunca dispara EventNormalized).
        Notification::assertNothingSent();
        Event::assertNotDispatched(EventNormalized::class);
        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->count());

        $c = $this->assertSystemLogged('normalization.unmapped_alert.skipped', fn (array $c): bool => $c['reason'] === 'recognized_non_emergency_trigger');
        $this->assertSame([5016], $c['calc']['trigger_ids']);
        $this->assertSame($raw->id, $c['input']['raw_event_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_emergency_trigger_without_a_rule_still_alerts(): void
    {
        Notification::fake();
        // Un pánico (1034) junto a otra condición, sin regla que lo reconozca
        // (la de este test exige la descripción vieja).
        $raw = $this->rawEvent($this->teamA, payload: $this->alertIncident([
            ['triggerId' => 5016, 'description' => 'Geofence Entry'],
            ['triggerId' => 1034, 'description' => 'Botón SOS'],
        ]));

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->where('raw_event_id', $raw->id)->count());
        $c = $this->assertSystemLogged('normalization.unmapped_alert.escalated');
        $this->assertSame('emergency', $c['calc']['trigger_class']);
    }

    public function test_conditions_without_trigger_ids_are_unreadable_and_alert(): void
    {
        Notification::fake();
        $raw = $this->rawEvent($this->teamA, payload: $this->alertIncident([
            ['description' => 'Algo'],
        ]));

        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->where('raw_event_id', $raw->id)->count());
    }
}
