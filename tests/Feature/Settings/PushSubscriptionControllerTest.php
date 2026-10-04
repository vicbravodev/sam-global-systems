<?php

namespace Tests\Feature\Settings;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertFalse(TenantContext::for($alice->currentTeam->id, fn () => PushSubscription::existsFor($alice->currentTeam->id, $alice->id)));
        $this->assertTrue(TenantContext::for($bob->currentTeam->id, fn () => PushSubscription::existsFor($bob->currentTeam->id, $bob->id)));

        // Alice ya no es dueña de ese navegador: su baja no toca la fila de Bob.
        $this->actingAs($alice)
            ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertNoContent();

        $survivor = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($bob->id, $survivor->user_id);
        $this->assertSame($bob->currentTeam->id, $survivor->team_id);
    }

    public function test_validates_the_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://insecure.example.com/x', 'keys' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedEndpoints(): array
    {
        return [
            'metadata de la nube' => ['https://169.254.169.254/x'],
            'localhost' => ['https://localhost/x'],
            'host ajeno' => ['https://evil.example.com/x'],
            'puerto distinto de 443' => ['https://fcm.googleapis.com:8443/x'],
        ];
    }

    #[DataProvider('disallowedEndpoints')]
    public function test_rejects_endpoints_outside_the_known_push_services(string $endpoint): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), $this->body($endpoint))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint' => 'Este navegador usa un servicio de avisos que SAM no admite.']);

        $this->assertSame(0, PushSubscription::withoutGlobalScopes()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedEndpoints(): array
    {
        return [
            'chrome' => ['https://fcm.googleapis.com/fcm/send/abc'],
            'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/abc'],
        ];
    }

    #[DataProvider('allowedEndpoints')]
    public function test_accepts_endpoints_of_known_push_services(string $endpoint): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), $this->body($endpoint))
            ->assertCreated();

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_rejects_keys_of_the_wrong_size(): void
    {
        $user = User::factory()->create();
        $body = $this->body();
        // 32 bytes en vez de los 65 de una llave P-256; 8 bytes de auth (< 16).
        $body['keys'] = ['p256dh' => rtrim(strtr(base64_encode(str_repeat('a', 32)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode('12345678'), '+/', '-_'), '=')];

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->assertSame(0, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_keeps_at_most_ten_devices_per_user_and_team_dropping_the_oldest(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $team = $user->currentTeam;
        $team->members()->attach($other, ['role' => 'member']);

        $oldest = PushSubscription::factory()->forMember($user, $team)->create(['last_used_at' => now()->subDays(30)]);
        foreach (range(1, 9) as $day) {
            PushSubscription::factory()->forMember($user, $team)->create(['last_used_at' => now()->subDays($day)]);
        }
        PushSubscription::factory()->count(10)->forMember($other, $team)->create(['last_used_at' => now()->subDays(60)]);

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), $this->body('https://fcm.googleapis.com/fcm/send/newest'))
            ->assertCreated();

        $mine = PushSubscription::withoutGlobalScopes()->where('team_id', $team->id)->where('user_id', $user->id);
        $this->assertSame(PushSubscription::MAX_PER_USER, $mine->count());
        $this->assertFalse(PushSubscription::withoutGlobalScopes()->whereKey($oldest->id)->exists());
        $this->assertTrue((clone $mine)->where('endpoint_hash', PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/newest'))->exists());
        $this->assertSame(10, PushSubscription::withoutGlobalScopes()->where('user_id', $other->id)->count());
        $this->assertSystemLogged('notifications.push_subscription.registered', fn (array $c) => $c['calc']['pruned'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_concurrent_duplicate_first_registration_answers_ok(): void
    {
        $user = User::factory()->create();
        $raced = false;

        // Simula la otra petición que gana la carrera: inserta el mismo
        // endpoint justo antes de que esta haga su INSERT.
        PushSubscription::creating(function (PushSubscription $subscription) use (&$raced, $user): void {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('push_subscriptions')->insert([
                'team_id' => $user->currentTeam->id,
                'user_id' => $user->id,
                'endpoint' => self::ENDPOINT,
                'endpoint_hash' => PushSubscription::hashEndpoint(self::ENDPOINT),
                'public_key' => 'old',
                'auth_token' => Crypt::encryptString('old'),
                'content_encoding' => 'aes128gcm',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->body())->assertOk();

        $subscription = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($this->body()['keys']['p256dh'], $subscription->public_key);
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
