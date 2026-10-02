<?php

namespace Tests\Feature\Broadcasting;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'sam-key',
            'secret' => 'sam-secret',
            'app_id' => 'sam-local',
            'options' => [
                'host' => 'soketi',
                'port' => 6001,
                'scheme' => 'http',
                'encrypted' => false,
                'useTLS' => false,
            ],
        ]);

        // Channels.php was loaded against the 'null' driver at boot (phpunit.xml).
        // Switching the default driver to 'pusher' requires re-registering the
        // channel callbacks so that PusherBroadcaster can authorize against them.
        require base_path('routes/channels.php');
        Broadcast::driver();
    }

    public function test_user_can_subscribe_to_own_private_channel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-users.{$user->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_user_cannot_subscribe_to_another_users_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-users.{$other->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_member_can_subscribe_to_team_accounts_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-accounts.{$team->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_member_of_a_suspended_tenant_cannot_subscribe_to_its_accounts_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $this->actingAs($user);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-accounts.{$team->id}",
        ])->assertStatus(403);

        // La suspensión de su team no afecta a otro team activo del usuario.
        $active = Team::factory()->create();
        $active->members()->attach($user, ['role' => TeamRole::Member->value]);
        Subscription::factory()->create(['team_id' => $active->id]);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-accounts.{$active->id}",
        ])->assertStatus(200);
    }

    public function test_super_admin_member_of_a_suspended_tenant_can_still_subscribe(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $team = $admin->currentTeam;
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $this->actingAs($admin);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-accounts.{$team->id}",
        ])->assertStatus(200);
    }

    public function test_reactivated_tenant_can_subscribe_again(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $subscription = Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $this->actingAs($user);
        $payload = ['socket_id' => '1234.5678', 'channel_name' => "private-accounts.{$team->id}"];

        $this->postJson('/broadcasting/auth', $payload)->assertStatus(403);

        $subscription->update(['status' => SubscriptionStatus::Active]);

        $this->postJson('/broadcasting/auth', $payload)->assertStatus(200);
    }

    public function test_non_member_cannot_subscribe_to_foreign_accounts_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreignTeam = $other->currentTeam;
        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-accounts.{$foreignTeam->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_subscribe(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-accounts.1',
        ]);

        $this->assertContains($response->status(), [401, 403], 'Unauthenticated subscription should be rejected');
    }
}
