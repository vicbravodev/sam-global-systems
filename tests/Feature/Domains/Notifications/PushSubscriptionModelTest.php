<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PushSubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriptions_are_tenant_scoped(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        PushSubscription::factory()->forMember($userA, $userA->currentTeam)->create();
        PushSubscription::factory()->forMember($userB, $userB->currentTeam)->create();

        $this->actingAs($userA->fresh());

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($userA->id, PushSubscription::query()->value('user_id'));
    }

    public function test_endpoint_hash_is_unique(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => $endpoint]);

        $this->expectException(UniqueConstraintViolationException::class);
        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => $endpoint]);
    }

    public function test_auth_token_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $subscription = PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['auth_token' => 'secret-auth']);

        $raw = DB::table('push_subscriptions')->where('id', $subscription->id)->value('auth_token');

        $this->assertNotSame('secret-auth', $raw);
        $this->assertSame('secret-auth', $subscription->fresh()->auth_token);
    }

    public function test_user_has_many_push_subscriptions(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->count(2)->forMember($user, $user->currentTeam)->create();

        $this->assertCount(2, $user->fresh()->pushSubscriptions);
    }

    public function test_user_push_tokens_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('user_push_tokens'));
    }
}
