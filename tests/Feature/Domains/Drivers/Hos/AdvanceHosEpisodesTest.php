<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\AdvanceHosEpisodes;
use App\Domains\Drivers\Actions\LinkHosEpisodeIncident;
use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Actions\SendHosNudge;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\AppendReplyInstructions;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Domains\Notifications\Support\SmsText;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class AdvanceHosEpisodesTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    private TenantIntegration $integration;

    private Driver $driver;

    private Asset $asset;

    private \stdClass $twilio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        $this->hosChannels();
        $this->twilio = $this->fakeTwilio();
        $this->integration = $this->hosIntegration();
        [$this->driver, $this->asset] = $this->hosDriver($this->integration);
    }

    private function episode(HosSituation $situation = HosSituation::BreakDue, array $attributes = []): HosEpisode
    {
        return HosEpisode::factory()->create([
            'team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, 'asset_id' => $this->asset->id,
            'situation' => $situation, 'opened_at' => now(), ...$attributes,
        ]);
    }

    private function state(string $status = 'driving', int $break = 28800, ?string $disconnectedSince = null, int $drive = 30000): void
    {
        $attributes = [
            'asset_id' => $this->asset->id, 'duty_status' => HosDutyStatus::from($status), 'break_remaining_s' => $break,
            'drive_remaining_s' => $drive, 'shift_remaining_s' => 40000, 'cycle_remaining_s' => 200000, 'violation_s' => 0,
            'app_disconnected_since' => $disconnectedSince, 'observed_at' => now(),
        ];
        $state = HosDriverState::withoutGlobalScopes()->where('driver_id', $this->driver->id)->first();

        if ($state === null) {
            HosDriverState::factory()->create(['team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, ...$attributes]);

            return;
        }

        $state->forceFill($attributes)->save();
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function advance(array $stored = []): array
    {
        return app(AdvanceHosEpisodes::class)->execute($this->integration, HosMonitoringConfig::fromArray($stored, config('hos.defaults')), now()->toImmutable());
    }

    /** Un sondeo real: relojes → detector → episodios. */
    private function readClocks(string $status, int $drive, int $shift, int $break = 20000): void
    {
        app(ProcessHosReadings::class)->execute(
            $this->integration->team_id,
            HosMonitoringConfig::fromArray([], config('hos.defaults')),
            new HosEnrollment([[
                'reading' => new HosClockReading('58072405', '281', $status, $break, $drive, $shift, 200000, 0),
                'driver' => $this->driver,
                'asset' => $this->asset,
            ]], []),
            now()->toImmutable(),
        );
    }

    private function appMessages(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), '/v1/fleet/messages')));
    }

    public function test_a_warning_before_the_limit_goes_only_to_the_driver_app(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 26 * 60);

        $counts = $this->advance();

        $this->assertSame(1, $counts['notified']);
        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/fleet/messages')
            && $request['driverIds'] === [58072405]
            && str_contains((string) $request['text'], '26 min'));
        $this->assertSame([], $this->twilio->messages);
        $this->assertSame([], $this->twilio->calls);
        $this->assertSame(1, $episode->fresh()->ladder_step);

        $notification = Notification::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}:0", $notification->event_key);
        $this->assertSame(NotificationSourceType::HosEpisode, $notification->source_type);
        $this->assertSame('hos.nudge', $notification->notification_type);
        $recipient = NotificationRecipient::withoutGlobalScopes()->sole();
        $this->assertSame(RecipientType::Driver, $recipient->recipient_type);
        $this->assertSame("samsara:{$this->integration->id}:58072405", $recipient->metadata_json[NotificationRecipient::SAMSARA_APP_ADDRESS_KEY]);

        $sent = $this->assertSystemLogged('hos.nudge.sent');
        $this->assertSame($episode->id, $sent['input']['episode_id']);
        $this->assertSame(['samsara_driver_app'], $sent['calc']['channels']);
        $this->assertSame('break_lead', $sent['calc']['notice']);
        $this->assertNoSensitiveDataLogged();
        $logs = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('Secreto', $logs);
        $this->assertStringNotContainsString('5512345678', $logs);
        $this->assertStringNotContainsString('break obligatorio', $logs);
    }

    public function test_at_the_limit_the_ladder_climbs_to_a_call_and_then_raises_the_incident(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode();
        $this->state(break: 0);

        $this->advance();                                     // 12:00 app
        $this->travel(5)->minutes();
        $this->advance();                                     // 12:05 app + WhatsApp
        $this->travel(5)->minutes();
        $this->advance();                                     // 12:10 llamada
        $this->travel(5)->minutes();
        $counts = $this->advance();                           // 12:15 incidente

        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame('whatsapp:+5215512345678', $this->twilio->messages[0]['to']);
        $this->assertCount(1, $this->twilio->calls);
        $this->assertStringContainsString('Hola, te llama SAM.', $this->twilio->calls[0]['params']['twiml']);
        $this->assertSame(1, $counts['escalated']);

        $episode->refresh();
        $this->assertNotNull($episode->escalated_at);
        $this->assertNull($episode->resolved_at);
        $this->assertSame(6, $episode->ladder_step);

        $raw = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}", $raw->deduplication_key);
        $this->assertSame('hos_unattended', $raw->event_type_raw);
        $this->assertSame($this->driver->id, $raw->payload_json['internal']['driver_id']);
        Queue::assertPushed(ProcessRawEventJob::class, 1);

        // Ya escaló: no vuelve a avisar ni a levantar nada.
        $this->travel(5)->minutes();
        $this->advance();
        $this->assertSame(2, $this->appMessages());
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('hos.incident.raised');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_ladder_pauses_while_the_driver_is_stopped_and_resumes_if_they_drive_again(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 0);
        $this->advance();                                     // 12:00 app (escalón 2)

        $this->travel(6)->minutes();
        $this->state('offDuty', break: 0);
        $this->advance();                                     // 12:06 parado: nada

        $this->assertSame(1, $this->appMessages());
        $this->assertSame([], $this->twilio->messages);
        $held = $this->assertSystemLogged('hos.nudge.skipped', fn (array $c) => $c['reason'] === 'not_working');
        $this->assertSame($episode->id, $held['input']['episode_id']);

        $this->travel(6)->minutes();
        $this->state('driving', break: 0);
        $this->advance();                                     // 12:12 vuelve a manejar: sale el escalón vencido

        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame(4, $episode->fresh()->ladder_step);
    }

    public function test_a_step_is_never_sent_twice(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 26 * 60);

        $this->advance();
        $this->advance();                                     // mismo minuto: nada nuevo
        $this->assertSame(1, $this->appMessages());

        // Un ciclo solapado que leyó el escalón viejo: la clave del aviso lo frena.
        $episode->forceFill(['ladder_step' => 0])->save();
        $this->advance();

        $this->assertSame(1, $this->appMessages());
        $this->assertSame(1, Notification::withoutGlobalScopes()->count());
        $this->assertSame('event_key_exists', $this->assertSystemLogged('notifications.dedup.skipped')['reason']);
        $this->assertSystemLogged('hos.nudge.sent', fn (array $c) => $c['result']['notification_reused'] === true);
        $this->assertSame(1, $episode->fresh()->ladder_step);
    }

    public function test_an_undelivered_app_message_brings_the_next_step_forward(): void
    {
        $this->fakeAppMessages(403);
        $episode = $this->episode();
        $this->state(break: 0);
        $this->advance();                                     // 12:00 escalón 2 por la app: Samsara lo rechaza

        $notification = Notification::withoutGlobalScopes()->where('event_key', "hos:{$episode->id}:2")->sole();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame('own_ladder', $this->assertSystemLogged('notifications.escalation_guard.blocked')['reason']);

        $this->travel(1)->minutes();
        $this->advance();                                     // 12:01: no espera a las 12:05

        $this->assertCount(1, $this->twilio->messages);
        $unavailable = $this->assertSystemLogged('hos.nudge.channel_unavailable');
        $this->assertSame('previous_nudge_undelivered', $unavailable['reason']);
        $this->assertSame('failed', $unavailable['calc']['notification_status']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_disconnected_driver_app_holds_the_ladder(): void
    {
        $this->fakeAppMessages();
        $this->episode();
        $this->state(break: 0, disconnectedSince: '2026-10-04 11:59:00');

        $counts = $this->advance();

        $this->assertSame(1, $counts['held']);
        $this->assertSame(0, $this->appMessages());
        $held = $this->assertSystemLogged('hos.nudge.skipped');
        $this->assertSame('no_reading', $held['reason']);
        $this->assertTrue($held['calc']['app_disconnected']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_raises_the_incident_at_once_and_tells_the_driver(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode(HosSituation::Violation);
        $this->state(break: 0);

        $this->advance();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/fleet/messages') && str_contains((string) $request['text'], 'infracción'));
        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $this->assertNotNull($episode->fresh()->escalated_at);

        $sent = $this->assertSystemLogged('hos.nudge.sent');
        $this->assertSame($episode->id, $sent['input']['episode_id']);
        $this->assertSame('escalate', $sent['calc']['move']);
        $this->assertSame(0, $sent['calc']['step']);
        $this->assertSame('violation', $sent['calc']['notice']);
        $this->assertSame(['samsara_driver_app'], $sent['calc']['channels']);
        $this->assertSame(Notification::withoutGlobalScopes()->sole()->id, $sent['result']['notification_id']);
        $this->assertSystemLogged('hos.incident.raised');
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('infracción', (string) json_encode($this->systemLogEntries()));
    }

    public function test_the_incident_opened_by_the_pipeline_is_linked_to_the_episode(): void
    {
        $episode = $this->episode(attributes: ['escalated_at' => now(), 'ladder_step' => 6]);
        $this->state(break: 0);
        $raw = RawEvent::factory()->create(['team_id' => $episode->team_id, 'deduplication_key' => "hos:{$episode->id}"]);
        $event = NormalizedEvent::factory()->create(['team_id' => $episode->team_id, 'raw_event_id' => $raw->id]);
        $incident = Incident::factory()->create(['team_id' => $episode->team_id, 'related_event_id' => $event->id]);

        $this->advance();

        $this->assertSame($incident->id, $episode->fresh()->incident_id);
        $this->assertSame($incident->id, $this->assertSystemLogged('hos.incident.linked')['result']['incident_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_event_folded_into_an_existing_incident_still_links_it(): void
    {
        $episode = $this->episode(attributes: ['escalated_at' => now(), 'ladder_step' => 6]);
        $this->state(break: 0);
        $raw = RawEvent::factory()->create(['team_id' => $episode->team_id, 'deduplication_key' => "hos:{$episode->id}"]);
        $event = NormalizedEvent::factory()->create(['team_id' => $episode->team_id, 'raw_event_id' => $raw->id]);
        $incident = Incident::factory()->create(['team_id' => $episode->team_id]);
        IncidentEventLink::factory()->create(['incident_id' => $incident->id, 'normalized_event_id' => $event->id, 'relation_type' => EventRelationType::SupportingEvent]);

        $this->advance();

        $this->assertSame($incident->id, $episode->fresh()->incident_id);
        $this->assertSame($incident->id, $this->assertSystemLogged('hos.incident.linked')['result']['incident_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_advancing_one_tenant_never_touches_another(): void
    {
        $this->fakeAppMessages();
        $other = $this->hosIntegration();
        [$otherDriver, $otherAsset] = $this->hosDriver($other, '99000001', '999');
        $otherEpisode = HosEpisode::factory()->create(['team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'asset_id' => $otherAsset->id, 'opened_at' => now()]);
        HosDriverState::factory()->create(['team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 0]);

        $this->episode();
        $this->state(break: 0);

        $this->assertNoTenantLeak($this->integration->team_id, fn () => $this->advance());

        $this->assertSame(0, $otherEpisode->fresh()->ladder_step);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $other->team_id)->count());
        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => $request['driverIds'] === [58072405]);
    }

    public function test_an_open_violation_holds_the_drive_limit_ladder_of_the_same_driver(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $violation = $this->episode(HosSituation::Violation);
        $driveLimit = $this->episode(HosSituation::DriveLimit);
        $this->state(drive: 0);

        $counts = $this->advance();                           // 12:00 sólo la infracción

        $this->assertSame(1, $counts['escalated']);
        $this->assertSame(1, $counts['held']);
        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'infracción'));
        $held = $this->assertSystemLogged('hos.nudge.skipped', fn (array $c) => $c['reason'] === 'violation_open');
        $this->assertSame($driveLimit->id, $held['input']['episode_id']);
        $this->assertSame($violation->id, $held['calc']['violation_episode_id']);

        foreach ([5, 5, 5, 5] as $minutes) {                  // 12:20: sin la pausa, drive_limit ya habría escalado
            $this->travel($minutes)->minutes();
            $this->advance();
        }

        // Sólo insiste la infracción (12:05 app + WhatsApp, 12:10 llamada); drive_limit calla.
        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertCount(1, $this->twilio->calls);
        $this->assertSame(3, Notification::withoutGlobalScopes()->where('source_reference_id', (string) $violation->id)->count());
        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $driveLimit->refresh();
        $this->assertSame(0, $driveLimit->ladder_step);
        $this->assertNull($driveLimit->next_nudge_at);
        $this->assertNull($driveLimit->escalated_at);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('source_reference_id', (string) $driveLimit->id)->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_and_its_held_drive_limit_close_together_once_the_driver_rests(): void
    {
        // Lo que el detector sí produce: manejando con el manejo en 0 abre la
        // infracción y drive_limit; la infracción sólo cierra cuando ya puede
        // manejar (descanso de 10 h), y ese mismo reloj cierra drive_limit.
        // drive_limit nunca "se reanuda": la insistencia de la infracción lo cubre.
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $this->readClocks('driving', drive: 0, shift: 20000);
        $violation = HosEpisode::withoutGlobalScopes()->where('situation', HosSituation::Violation)->sole();
        $driveLimit = HosEpisode::withoutGlobalScopes()->where('situation', HosSituation::DriveLimit)->sole();

        $this->advance();                                     // 12:00 infracción: aviso + incidente
        $this->travel(5)->minutes();
        $this->advance();                                     // 12:05 insiste la infracción

        $this->travel(10)->hours();
        $this->readClocks('offDuty', drive: 39600, shift: 50400);
        $this->assertNotNull($violation->fresh()->resolved_at);
        $this->assertNotNull($driveLimit->fresh()->resolved_at);

        $this->advance();                                     // nada pendiente de ninguno de los dos

        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame([], $this->twilio->calls);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('source_reference_id', (string) $driveLimit->id)->count());
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_while_driving_insists_on_whatsapp_and_a_call_with_one_incident(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode(HosSituation::Violation);
        $this->state(drive: 0);

        $this->advance();                                     // 12:00 app + incidente
        $this->assertSame(1, $this->appMessages());
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());

        $this->travel(3)->minutes();
        $this->advance();                                     // 12:03 aún no toca
        $this->assertSame([], $this->twilio->messages);

        $this->travel(2)->minutes();
        $this->advance();                                     // 12:05 app + WhatsApp
        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame('whatsapp:+5215512345678', $this->twilio->messages[0]['to']);
        $this->assertSame([], $this->twilio->calls);

        $this->travel(5)->minutes();
        $this->advance();                                     // 12:10 llamada
        $this->assertCount(1, $this->twilio->calls);
        $this->assertStringContainsString('infracción', $this->twilio->calls[0]['params']['twiml']);

        foreach ([5, 5, 30] as $minutes) {                    // nada más: ni otro aviso ni otro incidente
            $this->travel($minutes)->minutes();
            $this->advance();
        }

        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertCount(1, $this->twilio->calls);
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        Queue::assertPushed(ProcessRawEventJob::class, 1);
        $this->assertSame(
            ["hos:{$episode->id}:0", "hos:{$episode->id}:1", "hos:{$episode->id}:2"],
            Notification::withoutGlobalScopes()->orderBy('id')->pluck('event_key')->all(),
        );
        $episode->refresh();
        $this->assertSame(3, $episode->ladder_step);
        $this->assertNull($episode->next_nudge_at);
        $insist = $this->assertSystemLogged('hos.nudge.sent', fn (array $c) => $c['calc']['step'] === 1);
        $this->assertSame('notify', $insist['calc']['move']);
        $this->assertSame('violation', $insist['calc']['notice']);
        $this->assertSame('violation_raised', $this->assertSystemLogged('hos.nudge.skipped', fn (array $c) => $c['reason'] === 'violation_raised')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_while_off_duty_only_tells_the_driver_once(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode(HosSituation::Violation);
        $this->state('offDuty', drive: 0);

        $this->advance();                                     // 12:00 aviso + incidente

        foreach ([5, 5, 5, 5] as $minutes) {                  // parado: la insistencia queda en pausa
            $this->travel($minutes)->minutes();
            $this->advance();
        }

        $this->assertSame(1, $this->appMessages());
        $this->assertSame([], $this->twilio->messages);
        $this->assertSame([], $this->twilio->calls);
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertSame(1, $episode->fresh()->ladder_step);
        $this->assertSame('not_working', $this->assertSystemLogged('hos.nudge.skipped', fn (array $c) => $c['input']['episode_id'] === $episode->id)['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_past_the_shift_limit_working_without_driving_does_not_climb_the_ladder(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode(HosSituation::ShiftLimit, ['ladder_step' => 2]);
        $this->state('onDuty');
        HosDriverState::withoutGlobalScopes()->where('driver_id', $this->driver->id)->update(['shift_remaining_s' => 0]);

        $counts = $this->advance();

        $this->assertSame(1, $counts['held']);
        $this->assertSame(0, $this->appMessages());
        $this->assertSame(2, $episode->fresh()->ladder_step);

        HosDriverState::withoutGlobalScopes()->where('driver_id', $this->driver->id)->update(['duty_status' => HosDutyStatus::Driving->value]);
        $this->advance();                                     // manejando fuera de turno: arranca la escalera

        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'turno de 14 h'));
        $this->assertSame(3, $episode->fresh()->ladder_step);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_failing_nudge_never_blocks_the_violation_incident(): void
    {
        Queue::fake([ProcessRawEventJob::class]);
        $this->app->instance(SendHosNudge::class, new class extends SendHosNudge
        {
            public function __construct() {}

            public function execute(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision): Notification
            {
                throw new \RuntimeException('samsara down');
            }
        });
        $episode = $this->episode(HosSituation::Violation);
        $this->state(drive: 0);

        $counts = $this->advance();

        $this->assertSame(1, $counts['escalated']);
        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $this->assertNotNull($episode->fresh()->escalated_at);
        $this->assertSystemLogged('hos.incident.raised');
        $failed = $this->assertSystemLogged('hos.nudge.failed');
        $this->assertSame('dispatch_error', $failed['reason']);
        $this->assertSame($episode->id, $failed['input']['episode_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_open_violation_does_not_hold_the_break_ladder(): void
    {
        $this->fakeAppMessages();
        $this->episode(HosSituation::Violation, ['ladder_step' => 1, 'escalated_at' => now()]);
        $break = $this->episode();
        $this->state(break: 0, drive: 0);

        $this->advance();

        $this->assertSame(1, $this->appMessages());
        $this->assertSame(3, $break->fresh()->ladder_step);
        $this->assertSame($break->id, $this->assertSystemLogged('hos.nudge.sent')['input']['episode_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_driver_app_address_comes_from_the_episode_driver_and_integration_only(): void
    {
        $this->fakeAppMessages();
        $other = $this->hosIntegration();
        [$otherDriver, $otherAsset] = $this->hosDriver($other, '99000001', '999');
        $otherEpisode = HosEpisode::factory()->create(['team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'asset_id' => $otherAsset->id, 'opened_at' => now()]);
        $episode = $this->episode();
        $this->state(break: 0);

        $this->advance();

        $recipient = NotificationRecipient::withoutGlobalScopes()->sole();
        $this->assertSame($this->integration->team_id, $recipient->team_id);
        $this->assertSame("samsara:{$this->integration->id}:58072405", $recipient->metadata_json[NotificationRecipient::SAMSARA_APP_ADDRESS_KEY]);
        Http::assertNotSent(fn ($request) => $request['driverIds'] === [99000001]);

        // La integración de otro tenant nunca arma la dirección del chofer de este.
        $decision = new HosLadderDecision(HosLadderMove::Notify, 'ladder_step', 3, null, 2, ['samsara_driver_app'], HosNotice::BreakLimit);

        foreach ([$episode->team_id, $other->team_id] as $context) {
            try {
                TenantContext::for($context, fn () => app(SendHosNudge::class)->execute($other, $episode, $decision));
                $this->fail('An episode must never be sent through another tenant\'s integration.');
            } catch (LogicException) {
            }
        }

        $this->assertSame(1, Notification::withoutGlobalScopes()->count());
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $other->team_id)->count());
        $this->assertSame(0, $otherEpisode->fresh()->ladder_step);
        $this->assertSame($episode->id, $this->assertSystemLogged('hos.nudge.sent')['input']['episode_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_long_hos_sms_is_sent_whole_without_reply_instructions(): void
    {
        $this->fakeAppMessages();
        NotificationChannel::factory()->sms()->create(['config_json' => ['from' => '+15005550006']]);
        $episode = $this->episode(attributes: ['ladder_step' => 3, 'next_nudge_at' => now()]);
        $this->state(break: 0);
        $ladder = [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'channels' => ['sms']],
            ['after_minutes' => 10, 'escalate' => 'incident'],
        ];

        $this->advance(['ladder' => $ladder]);                // escalón 3 = insistencia por SMS

        $body = HosNoticeCopy::for(HosNotice::BreakInsist)['body'];
        $this->assertGreaterThan(100, mb_strlen($body));      // lo que AppendReplyInstructions recortaría
        $this->assertCount(1, $this->twilio->messages);
        $sent = (string) $this->twilio->messages[0]['params']['body'];
        $this->assertSame(SmsText::gsm7($body), $sent);
        $this->assertStringNotContainsString('Responde', $sent);
        $this->assertSame(0, NotificationReplyToken::withoutGlobalScopes()->count());

        // La regla vive en AppendReplyInstructions: sólo la fuente `incident`
        // lleva instrucciones, aunque el aviso HOS fuera crítico.
        $notification = Notification::withoutGlobalScopes()->where('event_key', "hos:{$episode->id}:3")->sole();
        $notification->forceFill(['priority' => NotificationPriority::Critical])->save();
        $rendered = new RenderedNotification(ChannelType::Sms, '+5215512345678', null, $body);
        $result = app(AppendReplyInstructions::class)->execute($notification, NotificationRecipient::withoutGlobalScopes()->sole(), $rendered);
        $this->assertSame($body, $result->body);
    }

    public function test_a_failed_rest_notice_does_not_skip_ahead(): void
    {
        $this->fakeAppMessages();
        // Fin de pausa abierto 12:00; su aviso de las 12:15 (escalón 0) no llegó.
        $episode = $this->episode(HosSituation::RestComplete, ['ladder_step' => 1, 'next_nudge_at' => now()->addMinutes(30)]);
        Notification::factory()->create([
            'team_id' => $episode->team_id, 'source_type' => NotificationSourceType::HosEpisode,
            'source_reference_id' => (string) $episode->id, 'notification_type' => 'hos.nudge',
            'event_key' => "hos:{$episode->id}:0", 'status' => NotificationStatus::Failed,
        ]);
        $this->state('offDuty');
        $this->travel(16)->minutes();

        $this->advance();                                     // 12:16
        $this->travel(1)->minutes();
        $this->advance();                                     // 12:17

        $this->assertSystemNotLogged('hos.nudge.channel_unavailable');
        $this->assertSame(0, $this->appMessages());
        $episode->refresh();
        $this->assertSame(1, $episode->ladder_step);
        $this->assertTrue($episode->next_nudge_at->equalTo(CarbonImmutable::parse('2026-10-04 12:30:00')));
        $this->assertSame('not_due', $this->assertSystemLogged('hos.nudge.skipped')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_exhausted_ladder_without_a_unit_is_not_marked_escalated(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        // La unidad se borró: el pipeline interno no puede levantar el incidente.
        $episode = $this->episode(attributes: ['asset_id' => null, 'ladder_step' => 5, 'next_nudge_at' => now()]);
        $this->state(break: 0);

        $counts = $this->advance();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['escalated']);
        $episode->refresh();
        $this->assertNull($episode->escalated_at);
        $this->assertSame(5, $episode->ladder_step);
        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
        $failed = $this->assertSystemLogged('hos.ladder.escalation_failed');
        $this->assertSame('no_asset', $failed['reason']);
        $this->assertSame($episode->id, $failed['input']['episode_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_failing_episode_does_not_stop_the_others(): void
    {
        $this->fakeAppMessages();
        $broken = $this->episode(HosSituation::DriveLimit, ['escalated_at' => now(), 'ladder_step' => 6]);
        $healthy = $this->episode();
        $this->state(break: 0);
        $this->app->instance(LinkHosEpisodeIncident::class, new class($broken->id) extends LinkHosEpisodeIncident
        {
            public function __construct(private readonly int $brokenId) {}

            public function execute(HosEpisode $episode): ?Incident
            {
                if ($episode->id === $this->brokenId) {
                    throw new \RuntimeException('link failed');
                }

                return parent::execute($episode);
            }
        });

        $counts = $this->advance();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['notified']);
        $this->assertSame(1, $this->appMessages());
        $this->assertSame(3, $healthy->fresh()->ladder_step);
        $failed = $this->assertSystemLogged('hos.ladder.episode_failed');
        $this->assertSame($broken->id, $failed['input']['episode_id']);
        $this->assertSame(1, $this->assertSystemLogged('hos.ladder.advanced')['result']['failed']);
        $this->assertNoSensitiveDataLogged();
    }
}
