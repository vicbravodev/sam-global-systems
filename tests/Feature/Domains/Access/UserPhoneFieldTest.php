<?php

namespace Tests\Feature\Domains\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPhoneFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_persists_phone_and_casts_verified_at(): void
    {
        $user = User::factory()->create([
            'phone' => '+5215555550123',
            'phone_verified_at' => now(),
        ]);

        $fresh = $user->fresh();

        $this->assertSame('+5215555550123', $fresh->phone);
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->phone_verified_at);
    }

    public function test_phone_is_nullable_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
    }
}
