<?php

namespace Tests\Feature\Settings;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Access\Actions\VerifyPhoneOtp;
use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use App\Support\OtpCacheKeys;
use Database\Seeders\OtpMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OtpMeterSeeder::class);
    }

    private function fakeSmsSuccess(): void
    {
        $driver = Mockery::mock(SmsNotificationDriver::class);
        $driver->shouldReceive('send')->andReturn(DeliveryResult::success(providerMessageId: 'SM_test'));
        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->with(ChannelType::Sms)->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);
    }

    public function test_send_stores_code_and_dispatches_sms(): void
    {
        $this->fakeSmsSuccess();
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $team = $user->currentTeam;
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true, 'channel_type' => ChannelType::Sms]);
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHasNoErrors();

        $this->assertNotNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_send_records_otp_sms_sent_usage_event(): void
    {
        $this->fakeSmsSuccess();
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create(['phone' => '+5215555550123']);
        $team = $user->currentTeam;
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true, 'channel_type' => ChannelType::Sms]);
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHasNoErrors();

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'otp_sms_sent');
    }

    public function test_send_without_sms_channel_fails_clearly(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHas('errors');
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_verify_with_correct_code_sets_verified_at(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $this->patch(route('phone-verification.verify'), ['code' => '123456'])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_verify_with_wrong_code_increments_attempts_and_does_not_verify(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $this->patch(route('phone-verification.verify'), ['code' => '000000'])->assertSessionHas('errors');

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame(1, Cache::get(OtpCacheKeys::forUser($user->id))['attempts']);
    }

    public function test_action_enforces_attempt_cap_and_forgets_key(): void
    {
        // Drive the action directly to avoid the route's 5/min throttle.
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $team = $user->currentTeam;
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $action = app(VerifyPhoneOtp::class);

        // 5 wrong attempts increment to the cap.
        for ($i = 0; $i < 5; $i++) {
            $result = $action->execute($user, $team->id, '000000');
            $this->assertFalse($result->ok);
        }

        // 6th call hits the cap branch: forgets the key and reports too_many_attempts.
        $capped = $action->execute($user, $team->id, '123456');
        $this->assertFalse($capped->ok);
        $this->assertSame('too_many_attempts', $capped->reason);
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_action_reports_expired_when_no_code_cached(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $team = $user->currentTeam;

        $result = app(VerifyPhoneOtp::class)->execute($user, $team->id, '123456');

        $this->assertFalse($result->ok);
        $this->assertSame('expired', $result->reason);
    }
}
