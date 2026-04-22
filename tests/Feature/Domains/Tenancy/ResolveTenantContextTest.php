<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\ResolveTenantContext;
use App\Domains\Tenancy\Exceptions\TenantContextException;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_resolves_from_authenticated_user(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $user->load('currentTeam');

        $action = app(ResolveTenantContext::class);
        $resolvedTeam = $action->execute($user);

        $this->assertEquals(
            $team->id,
            $resolvedTeam->id,
            'ResolveTenantContext should return the user\'s current team',
        );
    }

    public function test_it_throws_when_no_team_is_set(): void
    {
        $user = User::factory()->create();
        $user->update(['current_team_id' => null]);
        $user->unsetRelation('currentTeam');
        $user->refresh();

        $this->expectException(TenantContextException::class);

        $action = app(ResolveTenantContext::class);
        $action->execute($user);
    }
}
