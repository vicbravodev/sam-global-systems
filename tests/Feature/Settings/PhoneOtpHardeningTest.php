<?php

namespace Tests\Feature\Settings;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Access\Actions\SendPhoneOtp;
use App\Domains\Access\Actions\VerifyPhoneOtp;
use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use App\Support\OtpCacheKeys;
use Database\Seeders\OtpMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * El OTP queda atado al número al que se envió (antes: enviar a A, cambiar a
 * B y verificar B con el código de A) y tiene tope diario por usuario y por
 * team (cada SMS cuesta y se factura).
 */
class PhoneOtpHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $lastCode = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OtpMeterSeeder::class);

        $driver = Mockery::mock(SmsNotificationDriver::class);
        $driver->shouldReceive('send')->andReturnUsing(function ($rendered) {
            preg_match('/\d{6}/', $rendered->body, $m);
            $this->lastCode = $m[0] ?? '';

            return DeliveryResult::success(providerMessageId: 'SM_test');
        });
        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->with(ChannelType::Sms)->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);
    }

    private function userWithSms(string $phone = '+5215555550123'): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        NotificationChannel::factory()->sms()->create([
            'team_id' => $user->currentTeam->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

        return $user;
    }

    public function test_code_sent_to_one_number_cannot_verify_another(): void
    {
        $user = $this->userWithSms('+5215555550123');
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHasNoErrors();
        $code = $this->lastCode;
        $this->assertNotSame('', $code);

        // Cambia a otro número (que no controla quien verificó el primero).
        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+5215555559999',
        ])->assertSessionHasNoErrors();

        $this->patch(route('phone-verification.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_code_verifies_the_number_it_was_sent_to(): void
    {
        $user = $this->userWithSms('+5215555550123');
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHasNoErrors();

        $this->patch(route('phone-verification.verify'), ['code' => $this->lastCode])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_daily_cap_per_user(): void
    {
        $user = $this->userWithSms();
        $send = app(SendPhoneOtp::class);

        for ($i = 0; $i < OtpCacheKeys::DAILY_PER_USER; $i++) {
            $this->assertTrue($send->execute($user, $user->currentTeam->id)->ok, "Envío #{$i} debería pasar");
        }

        $result = $send->execute($user, $user->currentTeam->id);
        $this->assertFalse($result->ok);
        $this->assertSame('daily_limit', $result->reason);

        // Al día siguiente el tope se reinicia.
        $this->travel(1)->days();
        $this->assertTrue($send->execute($user, $user->currentTeam->id)->ok);
    }

    public function test_daily_cap_per_team(): void
    {
        $user = $this->userWithSms();
        $teamId = $user->currentTeam->id;

        Cache::put(OtpCacheKeys::dailyForTeam($teamId), OtpCacheKeys::DAILY_PER_TEAM, now()->endOfDay());

        $result = app(SendPhoneOtp::class)->execute($user, $teamId);

        $this->assertFalse($result->ok);
        $this->assertSame('daily_limit', $result->reason);
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_cache_entry_without_phone_binding_is_rejected(): void
    {
        $user = $this->userWithSms();
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $result = app(VerifyPhoneOtp::class)->execute($user, $user->currentTeam->id, '123456');

        $this->assertFalse($result->ok);
        $this->assertNull($user->fresh()->phone_verified_at);
    }
}
