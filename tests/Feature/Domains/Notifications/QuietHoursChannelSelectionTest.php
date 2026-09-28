<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\SelectNotificationChannels;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\QuietHours;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P1-12: dentro del horario de silencio (del tenant o del usuario, en la zona
 * del tenant) las prioridades no críticas no salen por SMS/WhatsApp/voz.
 */
class QuietHoursChannelSelectionTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach ([ChannelType::Email, ChannelType::Web, ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice] as $type) {
            NotificationChannel::factory()->create([
                'channel_type' => $type,
                'is_active' => true,
            ]);
        }
    }

    public function test_quiet_window_is_evaluated_in_the_tenant_timezone_and_crosses_midnight(): void
    {
        $window = ['start' => '22:00', 'end' => '07:00'];

        // 04:00 UTC = 22:00 en Ciudad de México (UTC-6): dentro.
        $this->assertTrue(QuietHours::isActive($window, 'America/Mexico_City', Carbon::parse('2026-09-27 04:30:00', 'UTC')));
        // 18:00 UTC = 12:00 en CDMX: fuera.
        $this->assertFalse(QuietHours::isActive($window, 'America/Mexico_City', Carbon::parse('2026-09-27 18:00:00', 'UTC')));
        $this->assertFalse(QuietHours::isActive($window + ['enabled' => false], 'UTC', Carbon::parse('2026-09-27 23:00:00', 'UTC')));
        $this->assertFalse(QuietHours::isActive(['start' => 'nope', 'end' => '07:00'], 'UTC'));
    }

    public function test_inside_tenant_quiet_hours_high_priority_drops_sms_but_keeps_email(): void
    {
        $team = $this->teamWithQuietPolicy();
        $this->travelToLocal($team, '23:30');

        $types = $this->selectedTypes($team, NotificationPriority::High);

        $this->assertContains('email', $types);
        $this->assertNotContains('sms', $types);
        $this->assertNotContains('whatsapp', $types);
    }

    public function test_outside_quiet_hours_sms_still_goes_out(): void
    {
        $team = $this->teamWithQuietPolicy();
        $this->travelToLocal($team, '12:00');

        $this->assertContains('sms', $this->selectedTypes($team, NotificationPriority::High));
    }

    public function test_critical_priority_ignores_quiet_hours(): void
    {
        $team = $this->teamWithQuietPolicy();
        $this->travelToLocal($team, '23:30');

        $this->assertContains('sms', $this->selectedTypes($team, NotificationPriority::Critical));
    }

    public function test_forced_channels_are_also_silenced_for_non_critical(): void
    {
        $team = $this->teamWithQuietPolicy();
        $this->travelToLocal($team, '02:00');

        $types = $this->selectedTypes($team, NotificationPriority::Normal, ['force_channels' => ['voice', 'email']]);

        $this->assertSame(['email'], $types);
    }

    public function test_user_preference_quiet_hours_apply_without_a_tenant_window(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $team->forceFill(['timezone' => 'UTC'])->save();
        $this->travelTo(Carbon::parse('2026-09-27 01:00:00', 'UTC'));

        NotificationPreference::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'notification_type' => 'manual.test',
            'allowed_channels_json' => ['sms', 'email'],
            'quiet_hours_json' => ['start' => '00:00', 'end' => '06:00'],
        ]);

        $types = $this->selectedTypes($team, NotificationPriority::High, [], (string) $user->id);

        $this->assertSame(['email'], $types);
    }

    public function test_another_tenants_quiet_hours_never_apply(): void
    {
        $quietTeam = $this->teamWithQuietPolicy();
        $this->travelToLocal($quietTeam, '23:30');

        $loud = User::factory()->create()->currentTeam;
        $loud->forceFill(['timezone' => 'America/Mexico_City'])->save();
        TenantNotificationPolicy::factory()->create([
            'team_id' => $loud->id,
            'policy_code' => 'default',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['email', 'sms'],
        ]);

        [$notification, $recipient] = $this->notificationFor($loud, NotificationPriority::High);

        // Los canales son de plataforma (team_id null): se comparan por tipo.
        $types = $this->assertNoTenantLeak($loud, fn () => array_map(
            fn (NotificationChannel $channel) => $channel->channel_type->value,
            app(SelectNotificationChannels::class)->execute($notification, $recipient),
        ));

        $this->assertContains('sms', $types);
    }

    private function teamWithQuietPolicy(): Team
    {
        $team = User::factory()->create()->currentTeam;
        $team->forceFill(['timezone' => 'America/Mexico_City'])->save();

        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id,
            'policy_code' => 'default',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['email', 'sms', 'whatsapp'],
            'quiet_hours_json' => ['start' => '22:00', 'end' => '07:00'],
        ]);

        return $team;
    }

    private function travelToLocal(Team $team, string $localTime): void
    {
        $this->travelTo(Carbon::parse("2026-09-27 {$localTime}:00", $team->timezone));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function selectedTypes(Team $team, NotificationPriority $priority, array $payload = [], ?string $userId = null): array
    {
        [$notification, $recipient] = $this->notificationFor($team, $priority, $payload, $userId);

        $types = array_map(
            fn (NotificationChannel $channel) => $channel->channel_type->value,
            app(SelectNotificationChannels::class)->execute($notification, $recipient),
        );
        sort($types);

        return $types;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Notification, 1: NotificationRecipient}
     */
    private function notificationFor(Team $team, NotificationPriority $priority, array $payload = [], ?string $userId = null): array
    {
        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => $priority,
            'payload_json' => $payload,
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'recipient_reference_id' => $userId,
            'phone' => '+5215555550999',
        ]);

        return [$notification, $recipient];
    }
}
