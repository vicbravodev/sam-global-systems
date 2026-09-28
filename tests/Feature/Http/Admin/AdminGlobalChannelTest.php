<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap V2-B1: SAM platform channels are managed from the super-admin
 * console. Twilio credentials never live in a channel: they are platform env
 * (TWILIO_*), so the console rejects credential keys in `config_json`.
 */
class AdminGlobalChannelTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_index_lists_platform_channels(): void
    {
        $admin = $this->superAdmin();

        $global = NotificationChannel::factory()->voice()->create();

        $response = $this->actingAs($admin)->get(route('admin.channels.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('admin/channels/index')
                ->has('channels', 1)
                ->where('channels.0.id', $global->id)
                ->where('channels.0.channelType', 'voice')
                ->where('channelTypes.0', ['value' => 'email', 'label' => 'Correo']),
        );
    }

    public function test_super_admin_creates_a_platform_channel(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.channels.store'), [
                'code' => 'sam_voice_mx',
                'name' => 'Voz SAM México',
                'provider' => 'twilio',
                'channel_type' => 'voice',
                'config_json' => [
                    'from' => '+5215500000000',
                    'ring_timeout_seconds' => '30',
                ],
            ])
            ->assertRedirect(route('admin.channels.index'));

        $channel = NotificationChannel::query()->where('code', 'sam_voice_mx')->sole();
        $this->assertSame(ChannelType::Voice, $channel->channel_type);
        $this->assertSame('+5215500000000', $channel->config_json['from']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform-channel.created']);
    }

    public function test_super_admin_updates_and_deletes_a_platform_channel(): void
    {
        $admin = $this->superAdmin();
        $channel = NotificationChannel::factory()->voice()->create();

        $this->actingAs($admin)
            ->put(route('admin.channels.update', $channel), ['is_active' => false])
            ->assertRedirect(route('admin.channels.index'));

        $this->assertFalse($channel->fresh()->is_active);

        $this->actingAs($admin)
            ->delete(route('admin.channels.destroy', $channel))
            ->assertRedirect(route('admin.channels.index'));

        $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform-channel.deleted']);
    }

    public function test_twilio_credentials_are_rejected_in_channel_config(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.channels.store'), [
                'code' => 'sam_sms_legacy',
                'name' => 'SMS con credenciales',
                'provider' => 'twilio',
                'channel_type' => 'sms',
                'config_json' => [
                    'twilio_account_sid' => 'AC-platform',
                    'twilio_auth_token' => 'tok-platform',
                    'from' => '+5215500000000',
                ],
            ])
            ->assertSessionHasErrors('config_json');

        $this->assertDatabaseMissing('notification_channels', ['code' => 'sam_sms_legacy']);
    }

    public function test_twilio_channel_accepts_only_non_secret_overrides(): void
    {
        $admin = $this->superAdmin();
        $channel = NotificationChannel::factory()->sms()->create();

        $this->actingAs($admin)
            ->put(route('admin.channels.update', $channel), [
                'config_json' => ['from' => '+15550001111', 'auth_token' => 'tok'],
            ])
            ->assertSessionHasErrors('config_json');

        $this->actingAs($admin)
            ->put(route('admin.channels.update', $channel), [
                'config_json' => ['from' => '+15550001111', 'some_unknown_key' => 'x'],
            ])
            ->assertSessionHasErrors('config_json');

        $this->actingAs($admin)
            ->put(route('admin.channels.update', $channel), [
                'config_json' => ['from' => '+15550001111'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['from' => '+15550001111'], $channel->fresh()->config_json);
    }

    public function test_non_twilio_channels_keep_their_own_secrets(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.channels.store'), [
                'code' => 'sam_slack',
                'name' => 'Slack SAM',
                'provider' => 'slack',
                'channel_type' => 'slack',
                'config_json' => ['slack_webhook_url' => 'https://hooks.slack.com/services/x'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notification_channels', ['code' => 'sam_slack']);
    }

    public function test_regular_users_cannot_access_the_console(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.channels.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.channels.store'), [])->assertForbidden();
    }
}
