<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Access\Actions\SendPhoneOtp;
use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\OtpMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P1-13: tenants suspendidos/cancelados/expirados no generan envíos con
 * coste; active y past_due sí. Lo bloqueado queda cancelado con motivo.
 */
class TenantCanSendGateTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_policy_per_subscription_status(): void
    {
        $this->assertTrue(TenantCanSend::allows($this->teamWith(null)->id), 'sin suscripción envía');
        $this->assertTrue(TenantCanSend::allows($this->teamWith('active')->id));
        $this->assertTrue(TenantCanSend::allows($this->teamWith('pastDue')->id));

        $this->assertSame('subscription_suspended', TenantCanSend::blockedReason($this->teamWith('suspended')->id));
        $this->assertSame('subscription_canceled', TenantCanSend::blockedReason($this->teamWith('canceled')->id));
        $this->assertSame('subscription_expired', TenantCanSend::blockedReason($this->teamWith('expired')->id));

        $deleted = $this->teamWith(null);
        $deleted->delete();
        $this->assertSame(TenantCanSend::REASON_TEAM_MISSING, TenantCanSend::blockedReason($deleted->id));
    }

    public function test_another_tenants_suspension_never_blocks_this_tenant(): void
    {
        $this->teamWith('suspended');
        $teamB = $this->teamWith(null);

        $allowed = $this->assertNoTenantLeak($teamB, fn () => TenantCanSend::allows($teamB->id));

        $this->assertTrue($allowed);
    }

    public function test_suspended_tenant_notification_is_cancelled_with_reason_and_never_delivered(): void
    {
        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();
        NotificationChannel::factory()->email()->create(['is_active' => true]);

        $team = $this->teamWith('suspended');
        $notification = $this->notificationFor($team);

        app(DispatchNotification::class)->execute($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Cancelled, $notification->status);
        $this->assertSame('subscription_suspended', $notification->payload_json['cancelled_reason']);
        $this->assertSame(0, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count());
    }

    public function test_past_due_tenant_notification_is_still_delivered(): void
    {
        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();
        NotificationChannel::factory()->email()->create(['is_active' => true]);

        $team = $this->teamWith('pastDue');
        $notification = $this->notificationFor($team);

        app(DispatchNotification::class)->execute($notification);

        $this->assertNotSame(NotificationStatus::Cancelled, $notification->fresh()->status);
        $this->assertSame(1, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count());
    }

    public function test_canceled_tenant_automation_action_is_cancelled(): void
    {
        $team = $this->teamWith('canceled');

        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
        ]);

        app(ExecuteAction::class)->execute($execution);

        $execution->refresh();
        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->status);
        $this->assertStringContainsString('subscription_canceled', (string) $execution->error_message);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_expired_tenant_never_places_a_verification_call(): void
    {
        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $team = $this->teamWith('expired');
        NotificationChannel::factory()->voice()->create(['is_active' => true]);

        $incident = Incident::factory()->open()->create(['team_id' => $team->id]);
        $verification = IncidentCallVerification::factory()->create([
            'team_id' => $team->id,
            'incident_id' => $incident->id,
        ]);

        $this->mock(TwilioVoiceCaller::class, fn ($mock) => $mock->shouldReceive('createCall')->never());

        app()->call([new PlaceVerificationCallJob($verification->id), 'handle']);

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::Failed, $fresh->status);
        $this->assertSame('subscription_expired', $fresh->metadata_json['failure_reason']);
    }

    public function test_phone_otp_is_blocked_for_inactive_tenant_non_member_and_already_verified_numbers(): void
    {
        $this->seed(OtpMeterSeeder::class);

        $sent = 0;
        $driver = Mockery::mock(SmsNotificationDriver::class);
        $driver->shouldReceive('send')->andReturnUsing(function () use (&$sent) {
            $sent++;

            return DeliveryResult::success(providerMessageId: 'SM_test');
        });
        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->with(ChannelType::Sms)->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);

        NotificationChannel::factory()->sms()->create(['is_active' => true, 'channel_type' => ChannelType::Sms]);

        $otp = app(SendPhoneOtp::class);

        // Legítimo: miembro, tenant activo, número sin verificar.
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->assertTrue($otp->execute($user, $user->currentTeam->id)->ok);
        $this->assertSame(1, $sent);

        // Tenant suspendido.
        $suspended = User::factory()->create(['phone' => '+5215555550124']);
        Subscription::factory()->suspended()->create(['team_id' => $suspended->currentTeam->id]);
        $this->assertSame('tenant_inactive', $otp->execute($suspended, $suspended->currentTeam->id)->reason);

        // current_team_id apuntando a un team ajeno.
        $foreignTeam = Team::factory()->create();
        $this->assertSame('not_member', $otp->execute($user, $foreignTeam->id)->reason);

        // Número ya verificado.
        $verified = User::factory()->create(['phone' => '+5215555550125', 'phone_verified_at' => now()]);
        $this->assertSame('already_verified', $otp->execute($verified, $verified->currentTeam->id)->reason);

        $this->assertSame(1, $sent);
    }

    private function teamWith(?string $subscriptionState): Team
    {
        $team = User::factory()->create()->currentTeam;

        if ($subscriptionState === 'active') {
            Subscription::factory()->create(['team_id' => $team->id]);
        } elseif ($subscriptionState !== null) {
            Subscription::factory()->{$subscriptionState}()->create(['team_id' => $team->id]);
        }

        return $team;
    }

    private function notificationFor(Team $team): Notification
    {
        return Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [['recipient_type' => 'external_contact', 'address' => 'ops@example.test']],
                'force_channels' => ['email'],
            ],
        ]);
    }
}
