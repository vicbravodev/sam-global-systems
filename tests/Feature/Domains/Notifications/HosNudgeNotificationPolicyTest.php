<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\SelectNotificationChannels;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Listeners\RetryOrFallbackOnNotificationFailed;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Domains\Notifications\Support\NotificationTypeLabels;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Los avisos HOS al chofer son seguridad vial y van en ruta: no los calla el
 * horario silencioso, y su escalera es su propia insistencia (sin reintento
 * ni fallback de la política del tenant).
 */
class HosNudgeNotificationPolicyTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach ([ChannelType::Email, ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice] as $type) {
            NotificationChannel::factory()->create(['channel_type' => $type, 'is_active' => true]);
        }
    }

    public function test_driver_hos_nudges_ignore_quiet_hours(): void
    {
        $team = $this->quietTeam();
        $this->travelTo(Carbon::parse('2026-09-27 23:30:00', $team->timezone));

        [$hos, $hosRecipient] = $this->nudge($team, NotificationSourceType::HosEpisode);
        $selection = app(SelectNotificationChannels::class)->explain($hos, $hosRecipient);

        $this->assertEqualsCanonicalizing(['whatsapp', 'voice'], array_map(fn (NotificationChannel $channel) => $channel->channel_type->value, $selection['channels']));
        $this->assertFalse($selection['calc']['quiet_hours_active']);
        $this->assertSame('bypassed', $selection['calc']['quiet_hours_source']);

        // El mismo aviso de otra fuente sí se calla.
        [$manual, $manualRecipient] = $this->nudge($team, NotificationSourceType::Manual);
        $this->assertSame([], app(SelectNotificationChannels::class)->execute($manual, $manualRecipient));
    }

    public function test_a_driver_never_inherits_the_preference_of_a_user_with_the_same_id(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $team->forceFill(['timezone' => 'UTC'])->save();
        NotificationPreference::factory()->create([
            'team_id' => $team->id, 'user_id' => $user->id, 'notification_type' => 'hos.nudge',
            'allowed_channels_json' => ['email'], 'quiet_hours_json' => ['start' => '00:00', 'end' => '23:59'],
        ]);
        [$notification, $recipient] = $this->nudge($team, NotificationSourceType::Manual, (string) $user->id);

        $selection = app(SelectNotificationChannels::class)->explain($notification, $recipient);

        $this->assertSame('none', $selection['calc']['quiet_hours_source']);
    }

    public function test_a_failed_hos_nudge_is_neither_retried_nor_moved_to_another_channel(): void
    {
        Queue::fake();
        $team = User::factory()->create()->currentTeam;
        $notification = Notification::factory()->create(['team_id' => $team->id, 'source_type' => NotificationSourceType::HosEpisode, 'notification_type' => 'hos.nudge']);
        $delivery = NotificationDelivery::factory()->failed()->create(['notification_id' => $notification->id, 'permanent_failure' => true]);

        TenantContext::for($team->id, fn () => $this->assertSame('own_ladder', DeliveryEscalationGuard::blockReason($delivery)));

        app(RetryOrFallbackOnNotificationFailed::class)->handle(new NotificationFailed($team->id, $notification->id, $delivery->id, 'whatsapp', 'boom'));

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
        $this->assertSame('own_ladder', $this->assertSystemLogged('notifications.escalation_guard.blocked')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_tenant_policy_fallback_channel_never_applies_to_a_hos_nudge(): void
    {
        Queue::fake();
        $team = User::factory()->create()->currentTeam;
        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id, 'policy_code' => 'default', 'notification_type' => null, 'priority' => null,
            'allowed_channels_json' => ['email', 'sms', 'whatsapp', 'voice'],
            'fallback_channels_json' => ['samsara_driver_app', 'sms', 'voice'],
        ]);
        $notification = Notification::factory()->create(['team_id' => $team->id, 'source_type' => NotificationSourceType::HosEpisode, 'notification_type' => 'hos.nudge']);
        $delivery = NotificationDelivery::factory()->failed()->create(['notification_id' => $notification->id, 'permanent_failure' => false]);

        $this->assertSame('own_ladder', TenantContext::for($team->id, fn () => DeliveryEscalationGuard::explain($delivery)['reason']));

        app(RetryOrFallbackOnNotificationFailed::class)->handle(new NotificationFailed($team->id, $notification->id, $delivery->id, 'whatsapp', 'boom'));

        Queue::assertNothingPushed();
        $this->assertSame(1, NotificationDelivery::query()->where('notification_id', $notification->id)->count());
        $this->assertSystemLogged('notifications.escalation_guard.blocked');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_nudge_type_has_a_human_label(): void
    {
        $this->assertSame('Recordatorio de horas de servicio al chofer', app(NotificationTypeLabels::class)->label('hos.nudge'));
    }

    private function quietTeam(): Team
    {
        $team = User::factory()->create()->currentTeam;
        $team->forceFill(['timezone' => 'America/Mexico_City'])->save();

        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id, 'policy_code' => 'default', 'notification_type' => null, 'priority' => null,
            'allowed_channels_json' => ['email', 'sms', 'whatsapp', 'voice'],
            'quiet_hours_json' => ['start' => '22:00', 'end' => '07:00'],
        ]);

        return $team;
    }

    /**
     * @return array{0: Notification, 1: NotificationRecipient}
     */
    private function nudge(Team $team, NotificationSourceType $source, ?string $referenceId = '1'): array
    {
        $notification = Notification::factory()->create([
            'team_id' => $team->id, 'source_type' => $source, 'notification_type' => 'hos.nudge',
            'priority' => NotificationPriority::High, 'payload_json' => ['force_channels' => ['whatsapp', 'voice']],
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id, 'team_id' => $team->id, 'recipient_type' => RecipientType::Driver,
            'recipient_reference_id' => $referenceId, 'address' => 'driver:'.$referenceId, 'phone' => '+5215512345678',
        ]);

        return [$notification, $recipient];
    }
}
