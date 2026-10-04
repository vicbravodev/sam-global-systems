<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Notifications\PipelineFailureNotification;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un job del camino crítico que agota sus reintentos avisa a humanos: los
 * super-admins siempre, y los owners/admins del tenant del evento cuando es
 * una emergencia. Nunca a otro tenant, y una sola vez por evento y etapa.
 */
class PipelineFailureAlertTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $superAdmin;

    private User $ownerA;

    private User $adminA;

    private User $memberA;

    private User $ownerB;

    private Team $teamA;

    private Team $teamB;

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
    }

    private function normalizedEvent(Team $team, string $typeCode = 'panic_button', string $categoryCode = 'emergency'): NormalizedEvent
    {
        $category = EventCategory::query()->where('code', $categoryCode)->first()
            ?? EventCategory::factory()->create(['code' => $categoryCode]);
        $type = EventType::query()->where('code', $typeCode)->first()
            ?? EventType::factory()->create(['code' => $typeCode, 'category_id' => $category->id]);
        $asset = Asset::factory()->create(['team_id' => $team->id, 'name' => 'Tracto 42']);
        $raw = RawEvent::factory()->processed()->create(['team_id' => $team->id]);

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $raw->id,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'event_category_id' => $category->id,
        ]);
    }

    public function test_a_failed_emergency_job_alerts_super_admins_and_the_event_tenant_admins_only(): void
    {
        Notification::fake();
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('boom'));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class, function (PipelineFailureNotification $n) use ($event) {
            return $n->audience === PipelineFailureNotification::AUDIENCE_PLATFORM
                && $n->details['team_id'] === $this->teamA->id
                && $n->details['normalized_event_id'] === $event->id
                && $n->details['raw_event_id'] === $event->raw_event_id
                && $n->details['event_type_code'] === 'panic_button'
                && $n->details['asset_name'] === 'Tracto 42'
                && $n->details['is_emergency'] === true
                && $n->details['stage'] === 'incidents.open_emergency_incident'
                && $n->details['error_class'] === RuntimeException::class;
        });
        Notification::assertSentTo([$this->ownerA, $this->adminA], PipelineFailureNotification::class, fn (PipelineFailureNotification $n) => $n->audience === PipelineFailureNotification::AUDIENCE_TENANT);
        Notification::assertNotSentTo($this->memberA, PipelineFailureNotification::class);
        Notification::assertNotSentTo($this->ownerB, PipelineFailureNotification::class);

        $alert = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertSame($this->teamA->id, (int) $alert->team_id);
        $this->assertTrue($alert->is_emergency);
        $this->assertSame(1, $alert->platform_recipients);
        $this->assertSame(2, $alert->tenant_recipients);
        $this->assertNotNull($alert->notified_at);

        $c = $this->assertSystemLogged('ingestion.failure_alert.sent');
        $this->assertSame('ok', $c['outcome']);
        $this->assertTrue($c['calc']['tenant_notified']);
        $this->assertSame(2, $c['result']['tenant_recipients']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_non_emergency_failure_only_alerts_the_platform(): void
    {
        Notification::fake();
        $event = $this->normalizedEvent($this->teamA, 'harsh_brake', 'safety');

        (new CreateIncidentJob($event->id))->failed(new RuntimeException('boom'));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class);
        Notification::assertNotSentTo([$this->ownerA, $this->adminA, $this->ownerB], PipelineFailureNotification::class);

        $c = $this->assertSystemLogged('ingestion.failure_alert.sent');
        $this->assertSame('not_emergency', $c['calc']['tenant_skip_reason']);
    }

    public function test_the_same_failure_alerts_only_once(): void
    {
        Notification::fake();
        $event = $this->normalizedEvent($this->teamA);

        $job = new OpenEmergencyIncidentJob($event->id, $this->teamA->id);
        $job->failed(new RuntimeException('boom'));
        $job->failed(new RuntimeException('boom again'));
        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('third worker'));

        Notification::assertSentToTimes($this->superAdmin, PipelineFailureNotification::class, 1);
        Notification::assertSentToTimes($this->ownerA, PipelineFailureNotification::class, 1);
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->count());

        $c = $this->assertSystemLogged('ingestion.failure_alert.skipped');
        $this->assertSame('already_alerted', $c['reason']);
    }

    public function test_the_exception_reaches_the_alert_redacted(): void
    {
        Notification::fake();
        $raw = RawEvent::factory()->pendingProcessing()->create(['team_id' => $this->teamA->id]);

        (new NormalizeEventJob($raw->id))->failed(new RuntimeException('call driver@example.com at +5215512345678 with Bearer abc.def.ghi'));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class, function (PipelineFailureNotification $n) use ($raw) {
            $message = (string) $n->details['error_message'];
            $mail = (string) $n->toMail($this->superAdmin)->render();

            return $n->details['raw_event_id'] === $raw->id
                && ! str_contains($message, 'driver@example.com')
                && ! str_contains($message, '5512345678')
                && ! str_contains($message, 'abc.def.ghi')
                && ! str_contains($mail, 'driver@example.com')
                && str_contains($message, '[email]');
        });

        $stored = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString('driver@example.com', json_encode($stored->error_json));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_tenant_notifications_never_carry_internal_error_details(): void
    {
        Notification::fake();
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('SQLSTATE internal detail'));

        Notification::assertSentTo($this->ownerA, PipelineFailureNotification::class, function (PipelineFailureNotification $n) {
            $data = $n->toArray($this->ownerA);
            $mail = (string) $n->toMail($this->ownerA)->render();

            return ! array_key_exists('error_message', $data)
                && ! str_contains($mail, 'SQLSTATE');
        });
    }

    public function test_a_job_whose_team_does_not_match_its_event_never_alerts_a_tenant(): void
    {
        Notification::fake();
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamB->id))->failed(new RuntimeException('boom'));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class);
        Notification::assertNotSentTo([$this->ownerA, $this->adminA, $this->ownerB], PipelineFailureNotification::class);
        $this->assertSystemLogged('ingestion.failure_alert.team_mismatch');
    }

    public function test_the_ingestion_job_failure_alerts_through_its_raw_event(): void
    {
        Notification::fake();
        $raw = RawEvent::factory()->processing()->create(['team_id' => $this->teamA->id]);

        (new ProcessRawEventJob($raw->id))->failed(new RuntimeException('boom'));

        Notification::assertSentTo($this->superAdmin, PipelineFailureNotification::class, fn (PipelineFailureNotification $n) => $n->details['stage'] === 'ingestion.process_raw_event'
            && $n->details['raw_event_id'] === $raw->id
            && $n->details['team_id'] === $this->teamA->id);
    }

    public function test_the_real_send_writes_mail_and_in_app_notifications_in_the_right_tenant(): void
    {
        $event = $this->normalizedEvent($this->teamA);

        $this->assertNoTenantLeak($this->teamA, function () use ($event): void {
            (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('boom'));
        });

        $rows = TenantContext::withoutTenant(fn () => UserNotification::query()->get());
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            [$this->superAdmin->id, $this->ownerA->id, $this->adminA->id],
            $rows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all(),
        );

        // Los avisos del tenant quedan en su tenant; el de plataforma, en ninguno.
        $tenantRows = $rows->where('data.audience', PipelineFailureNotification::AUDIENCE_TENANT);
        $this->assertCount(2, $tenantRows);
        $this->assertSame([$this->teamA->id], $tenantRows->pluck('team_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertNull($rows->firstWhere('notifiable_id', $this->superAdmin->id)->team_id);

        // Desde el tenant B no se ve ningún aviso del A.
        $this->assertSame(0, TenantContext::for($this->teamB->id, fn () => UserNotification::query()->count()));
        $this->assertSame(1, TenantContext::for($this->teamA->id, fn () => $this->ownerA->notifications()->count()));
    }

    public function test_a_super_admin_who_is_also_a_member_never_sees_the_technical_alert_inside_that_tenant(): void
    {
        $this->teamA->members()->attach($this->superAdmin, ['role' => TeamRole::Member->value]);
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('SQLSTATE internal detail'));

        $this->assertSame(0, TenantContext::for($this->teamA->id, fn () => $this->superAdmin->notifications()->count()));

        $platform = TenantContext::withoutTenant(fn () => $this->superAdmin->notifications()->sole());
        $this->assertNull($platform->team_id);
        $this->assertSame(PipelineFailureNotification::AUDIENCE_PLATFORM, $platform->data['audience']);
    }

    public function test_a_failing_platform_delivery_does_not_stop_the_tenant_alert_nor_repeat_it(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $sending): void {
            if ($sending->notifiable instanceof User && $sending->notifiable->is($this->superAdmin)) {
                throw new RuntimeException('smtp down');
            }
        });
        Mail::fake();
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('boom'));

        $tenantRows = TenantContext::for($this->teamA->id, fn () => UserNotification::query()->get());
        $this->assertEqualsCanonicalizing(
            [$this->ownerA->id, $this->adminA->id],
            $tenantRows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all(),
        );

        $alert = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertSame(0, $alert->platform_recipients);
        $this->assertSame(2, $alert->tenant_recipients);

        $this->assertSystemLogged('ingestion.failure_alert.failed', fn (array $c) => $c['reason'] === 'send_failed' && $c['input']['audience'] === 'platform');
        $c = $this->assertSystemLogged('ingestion.failure_alert.sent');
        $this->assertSame('partial_delivery', $c['reason']);
        $this->assertTrue($c['calc']['platform_send_failed']);

        // Un segundo fallo del mismo evento no repite el aviso que ya salió.
        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('boom again'));

        $this->assertSame(2, TenantContext::for($this->teamA->id, fn () => UserNotification::query()->count()));
        $this->assertSystemLogged('ingestion.failure_alert.skipped');
    }

    public function test_when_nothing_is_delivered_the_claim_is_released_for_a_later_retry(): void
    {
        Event::listen(NotificationSending::class, function (): void {
            throw new RuntimeException('smtp down');
        });
        $event = $this->normalizedEvent($this->teamA);

        (new OpenEmergencyIncidentJob($event->id, $this->teamA->id))->failed(new RuntimeException('boom'));

        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('ingestion.failure_alert.sent');
    }
}
