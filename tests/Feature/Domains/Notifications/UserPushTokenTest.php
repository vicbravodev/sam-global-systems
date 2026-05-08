<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\UserPushToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPushTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_push_tokens(): void
    {
        $user = User::factory()->create();

        UserPushToken::factory()->ios()->create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
        ]);
        UserPushToken::factory()->android()->create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
        ]);

        $this->assertCount(2, $user->fresh()->pushTokens);
    }

    public function test_tokens_are_tenant_scoped_via_belongs_to_tenant(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        UserPushToken::factory()->create([
            'user_id' => $userA->id,
            'team_id' => $userA->currentTeam->id,
            'token' => 'tok-A',
        ]);
        UserPushToken::factory()->create([
            'user_id' => $userB->id,
            'team_id' => $userB->currentTeam->id,
            'token' => 'tok-B',
        ]);

        $this->actingAs($userA->fresh());

        $this->assertSame(1, UserPushToken::query()->count());
        $this->assertSame(2, UserPushToken::withoutGlobalScopes()->count());
        $this->assertSame('tok-A', UserPushToken::query()->value('token'));
    }

    public function test_token_unique_constraint(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        UserPushToken::factory()->create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'token' => 'unique-token',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        UserPushToken::factory()->create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'token' => 'unique-token',
        ]);
    }
}
