<?php

namespace Tests\Feature\Settings;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\VAPID;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

    private function body(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
        ];
    }

    public function test_registers_the_device_for_the_user_in_the_current_team(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1')
            ->postJson(route('push-subscriptions.store'), $this->body())
            ->assertCreated();

        $subscription = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame($user->currentTeam->id, $subscription->team_id);
        $this->assertSame('iPhone', $subscription->device_label);
        $this->assertSystemLogged('notifications.push_subscription.registered', fn (array $c) => $c['calc']['created'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_registering_the_same_device_twice_keeps_one_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->body())->assertCreated();
        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->body())->assertOk();

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_same_endpoint_moves_to_the_new_user_and_team(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson(route('push-subscriptions.store'), $this->body())->assertCreated();
        $this->actingAs($bob)->postJson(route('push-subscriptions.store'), $this->body())->assertOk();

        $subscription = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($bob->id, $subscription->user_id);
        $this->assertSame($bob->currentTeam->id, $subscription->team_id);
        $this->assertSystemLogged('notifications.push_subscription.registered', fn (array $c) => $c['calc']['moved'] === true);
    }

    public function test_validates_the_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://insecure.example.com/x', 'keys' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_removes_only_the_users_own_device(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => self::ENDPOINT]);

        $this->actingAs($user)
            ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertNoContent();

        $this->assertSame(0, PushSubscription::withoutGlobalScopes()->count());
        $this->assertSystemLogged('notifications.push_subscription.removed');
    }

    public function test_cannot_remove_another_tenants_device(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        PushSubscription::factory()->forMember($victim, $victim->currentTeam)->create(['endpoint' => self::ENDPOINT]);

        $this->assertNoTenantLeak($attacker->currentTeam, function () use ($attacker) {
            $this->actingAs($attacker)
                ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
                ->assertNoContent();
        });

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_guests_cannot_register(): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->body())->assertUnauthorized();
    }

    public function test_shares_the_public_key_when_configured(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:x@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('webPush.publicKey', $keys['publicKey']));
    }

    public function test_shares_null_public_key_when_not_configured(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('webPush.publicKey', null));
    }
}
