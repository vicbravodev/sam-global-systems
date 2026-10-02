<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Jobs\FreezeIncidentLocationTrailJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un pánico rescatado horas después (ReprocessStuckRawEventsJob), un webhook
 * atrasado o un poller con cursor viejo abren su incidente "ahora": el
 * incidente y su aviso deben decir cuánto hace que ocurrió, en la hora local
 * del tenant, sin bajar la prioridad ni saltarse canales.
 */
class LateIncidentNoticeTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // 18:00 UTC = 12:00 en Ciudad de México (UTC−6 todo el año).
        Carbon::setTestNow(Carbon::parse('2026-09-30 18:00:00', 'UTC'));

        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);
        Bus::fake([SendNotificationJob::class, PlaceVerificationCallJob::class, RunAutomationWorkflowJob::class, FreezeIncidentLocationTrailJob::class]);

        $this->team = User::factory()->create()->currentTeam;
        TenantScheduleProfile::factory()->create(['team_id' => $this->team->id, 'timezone' => 'America/Mexico_City']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function panic(CarbonInterface $occurredAt, array $raw = [], ?Team $team = null): NormalizedEvent
    {
        $team ??= $this->team;
        $category = EventCategory::query()->where('code', 'emergency')->first()
            ?? EventCategory::factory()->create(['code' => 'emergency']);
        $type = EventType::query()->where('code', 'panic_button')->first()
            ?? EventType::factory()->create(['code' => 'panic_button', 'category_id' => $category->id]);

        $rawEventId = $raw['raw_event_id'] ?? RawEvent::factory()->create(array_merge([
            'team_id' => $team->id,
            'occurred_at' => $occurredAt,
            'received_at' => now()->subMinute(),
        ], $raw))->id;

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $rawEventId,
            'asset_id' => Asset::factory()->create(['team_id' => $team->id])->id,
            'event_type_id' => $type->id,
            'event_category_id' => $category->id,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function open(NormalizedEvent $event, ?Team $team = null): Incident
    {
        app()->call([new OpenEmergencyIncidentJob($event->id, ($team ?? $this->team)->id), 'handle']);

        return Incident::withoutGlobalScopes()->with('priority')->where('related_event_id', $event->id)->sole();
    }

    private function notificationFor(Incident $incident): Notification
    {
        return Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->sole();
    }

    /**
     * @return array{subject: string|null, body: string}
     */
    private function render(Notification $notification, ChannelType $channel): array
    {
        $recipient = NotificationRecipient::factory()->make(['address' => 'destino']);
        $rendered = app(RenderNotificationContent::class)->execute($notification, $recipient, $channel);

        return ['subject' => $rendered->subject, 'body' => $rendered->body];
    }

    public function test_a_panic_that_arrives_nine_hours_late_says_so_everywhere(): void
    {
        $incident = $this->open($this->panic(now()->subHours(9)));

        $this->assertSame('critical', $incident->priority->code, 'Un pánico tardío sigue siendo emergencia.');

        $late = $incident->metadata_json['late_arrival'];
        $this->assertSame(9 * 3600, $late['delay_seconds']);
        $this->assertSame('03:00', $late['occurred_at_local']);
        $this->assertSame('America/Mexico_City', $late['timezone']);
        $this->assertSame('schedule_profile', $late['timezone_source']);
        $this->assertSame('received_late', $late['cause']);
        $this->assertFalse($late['rescued']);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::LateArrival)
            ->sole();
        $this->assertSame('Evento ocurrido hace 9 h; recibido con retraso', $entry->title);
        $this->assertStringContainsString('Ocurrió a las 03:00 (America/Mexico_City)', (string) $entry->description);

        $notification = $this->notificationFor($incident);
        $this->assertSame('incident.panic_emergency.created', $notification->notification_type);
        $this->assertSame(NotificationPriority::Critical, $notification->priority);
        $this->assertSame('⚠️ Ocurrió hace 9 h (hora local 03:00)', $notification->payload_json['late_notice']);
        // El equipo, en la app y por correo; la persona en turno (o su
        // respaldo) recibe el aviso por la política crítica, con el retraso.
        $this->assertSame(['web', 'email'], $notification->payload_json['force_channels']);
        $responder = Notification::withoutGlobalScopes()->where('event_key', "incident_created_responder:{$incident->id}")->sole();
        $this->assertArrayNotHasKey('force_channels', $responder->payload_json);
        $this->assertSame('⚠️ Ocurrió hace 9 h (hora local 03:00)', $responder->payload_json['late_notice']);

        $email = $this->render($notification, ChannelType::Email);
        $this->assertStringStartsWith('🚨 PÁNICO:', (string) $email['subject']);
        $this->assertStringContainsString('⚠️ Ocurrió hace 9 h (hora local 03:00)', (string) $email['subject']);
        $this->assertStringStartsWith("⚠️ Ocurrió hace 9 h (hora local 03:00)\n\nSe activó un botón de pánico.", $email['body']);

        foreach ([ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Web] as $channel) {
            $this->assertStringStartsWith('⚠️ Ocurrió hace 9 h (hora local 03:00)', $this->render($notification, $channel)['body'], $channel->value);
        }

        $voice = $this->render($notification, ChannelType::Voice);
        $this->assertStringStartsWith('Atención: este evento ocurrió hace 9 horas, a las 03:00 hora local.', $voice['body']);
        $this->assertStringNotContainsString('⚠️', $voice['body'].$voice['subject']);

        $c = $this->assertSystemLogged('incidents.late_arrival.assessed', fn (array $c) => ($c['reason'] ?? null) === 'late_arrival');
        $this->assertSame('degraded', $c['outcome']);
        $this->assertSame(32400, $c['calc']['delay_seconds']);
        $this->assertSame(600, $c['calc']['threshold_seconds']);
        $this->assertSame($entry->id, $c['result']['timeline_entry_id']);
        $c = $this->assertSystemLogged('notifications.late_notice.attached', fn (array $c) => ($c['outcome'] ?? null) === 'ok');
        $this->assertSame(32400, $c['calc']['delay_seconds']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_recent_panic_carries_no_delay_notice(): void
    {
        $incident = $this->open($this->panic(now()->subMinutes(2)));

        $this->assertArrayNotHasKey('late_arrival', $incident->metadata_json ?? []);
        $this->assertFalse(IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::LateArrival)
            ->exists());

        $notification = $this->notificationFor($incident);
        $this->assertArrayNotHasKey('late_notice', $notification->payload_json);

        $email = $this->render($notification, ChannelType::Email);
        $this->assertStringNotContainsString('Ocurrió hace', (string) $email['subject'].$email['body']);
        $this->assertStringStartsWith('Se activó un botón de pánico.', $email['body']);

        $c = $this->assertSystemLogged('incidents.late_arrival.assessed', fn (array $c) => ($c['outcome'] ?? null) === 'ok');
        $this->assertSame(120, $c['calc']['delay_seconds']);
        $this->assertFalse($c['result']['late']);
        $this->assertSystemLogged('notifications.late_notice.attached', fn (array $c) => ($c['reason'] ?? null) === 'not_late');
    }

    public function test_the_delay_threshold_is_configurable(): void
    {
        config(['incidents.late_notice_after_minutes' => 60]);

        $incident = $this->open($this->panic(now()->subMinutes(30)));

        $this->assertArrayNotHasKey('late_arrival', $incident->metadata_json ?? []);
    }

    public function test_a_panic_rescued_by_the_reprocess_job_says_it_was_rescued(): void
    {
        $occurredAt = now()->subHours(9);
        $incident = $this->open($this->panic($occurredAt, [
            'received_at' => $occurredAt->copy()->addSeconds(5),
            'reprocess_attempts' => 1,
        ]));

        $late = $incident->metadata_json['late_arrival'];
        $this->assertTrue($late['rescued']);
        $this->assertSame(1, $late['reprocess_attempts']);
        $this->assertSame('processed_late', $late['cause']);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::LateArrival)
            ->sole();
        $this->assertSame('Evento ocurrido hace 9 h; procesado con retraso tras rescate automático (1 reproceso)', $entry->title);
        $this->assertStringContainsString('llegó a SAM a las 03:00', (string) $entry->description);

        $notification = $this->notificationFor($incident);
        $this->assertSame('⚠️ Ocurrió hace 9 h (hora local 03:00) — recuperado por reproceso automático', $notification->payload_json['late_notice']);
        $this->assertStringEndsWith('Se recuperó por reproceso automático.', $notification->payload_json['late_notice_spoken']);

        $c = $this->assertSystemLogged('incidents.late_arrival.assessed', fn (array $c) => ($c['reason'] ?? null) === 'late_arrival');
        $this->assertTrue($c['calc']['rescued']);
        $this->assertSame('processed_late', $c['calc']['cause']);
    }

    public function test_notification_stays_idempotent_for_a_late_incident(): void
    {
        $incident = $this->open($this->panic(now()->subHours(9)));

        IncidentCreated::dispatch($incident->fresh());

        $this->assertSame(1, Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->count());
    }

    public function test_falls_back_to_the_team_timezone_without_a_schedule_profile(): void
    {
        $team = User::factory()->create()->currentTeam;
        $team->forceFill(['timezone' => 'America/Bogota'])->save();

        $incident = $this->open($this->panic(now()->subHours(9), team: $team), $team);

        $late = $incident->metadata_json['late_arrival'];
        $this->assertSame('America/Bogota', $late['timezone']);
        $this->assertSame('team', $late['timezone_source']);
        $this->assertSame('04:00', $late['occurred_at_local']);
    }

    public function test_uses_only_its_own_tenant_timezone_and_raw_event(): void
    {
        $teamB = User::factory()->create()->currentTeam;
        TenantScheduleProfile::factory()->create(['team_id' => $teamB->id, 'timezone' => 'Europe/Madrid']);

        // Un raw event del tenant A rescatado dos veces: el evento de B que
        // apunta a él por error nunca debe heredar su rescate.
        $foreignRaw = RawEvent::factory()->create([
            'team_id' => $this->team->id,
            'received_at' => now()->subHours(9),
            'reprocess_attempts' => 2,
        ]);
        $event = $this->panic(now()->subHours(9), ['raw_event_id' => $foreignRaw->id], $teamB);

        $incident = $this->assertNoTenantLeak($teamB, fn () => $this->open($event, $teamB));

        $this->assertSame($teamB->id, (int) $incident->team_id);
        $late = $incident->metadata_json['late_arrival'];
        $this->assertSame('Europe/Madrid', $late['timezone']);
        $this->assertSame('11:00', $late['occurred_at_local']);
        $this->assertFalse($late['rescued']);
        $this->assertSame(0, $late['reprocess_attempts']);
        $this->assertNull($late['received_at']);

        $this->assertSame('⚠️ Ocurrió hace 9 h (hora local 11:00)', $this->notificationFor($incident)->payload_json['late_notice']);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }
}
