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
 * console; tenant channels never appear here.
 */
class AdminGlobalChannelTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_index_lists_only_global_channels(): void
    {
        $admin = $this->superAdmin();

        $global = NotificationChannel::factory()->voice()->create(['team_id' => null]);
        NotificationChannel::factory()->sms()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.channels.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('admin/channels/index')
                ->has('channels', 1)
                ->where('channels.0.id', $global->id)
                ->where('channels.0.channelType', 'voice'),
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
                    'twilio_account_sid' => 'AC-platform',
                    'twilio_auth_token' => 'tok-platform',
                    'from' => '+5215500000000',
                ],
            ])
            ->assertRedirect(route('admin.channels.index'));

        $channel = NotificationChannel::query()->where('code', 'sam_voice_mx')->sole();
        $this->assertNull($channel->team_id);
        $this->assertSame(ChannelType::Voice, $channel->channel_type);
        $this->assertSame('+5215500000000', $channel->config_json['from']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform-channel.created']);
    }

    public function test_super_admin_updates_and_deletes_a_platform_channel(): void
    {
        $admin = $this->superAdmin();
        $channel = NotificationChannel::factory()->voice()->create(['team_id' => null]);

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

    public function test_tenant_channels_cannot_be_managed_through_the_admin_routes(): void
    {
        $admin = $this->superAdmin();
        $tenantChannel = NotificationChannel::factory()->sms()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.channels.update', $tenantChannel), ['is_active' => false])
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('admin.channels.destroy', $tenantChannel))
            ->assertNotFound();
    }

    public function test_regular_users_cannot_access_the_console(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.channels.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.channels.store'), [])->assertForbidden();
    }
}
