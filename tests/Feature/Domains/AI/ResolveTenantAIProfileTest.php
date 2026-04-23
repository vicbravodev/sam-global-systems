<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTenantAIProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_returns_defaults_for_any_team(): void
    {
        $user = User::factory()->create();

        $profile = app(ResolveTenantAIProfile::class)->execute($user->currentTeam->id);

        $this->assertSame($user->currentTeam->id, $profile->teamId);
        $this->assertSame('semi', $profile->automationLevel);
        $this->assertGreaterThan(0, $profile->monthlyTokenLimit);
    }
}
