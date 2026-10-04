<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HosMonitoringPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function enable(Team $team, bool $enabled = true): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => $enabled]);
        app(AuthorizeAction::class)->forgetTeamAccess($team->id);
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function userWithRole(array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'hos_role_'.$user->id, 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync(array_map(fn (string $code): int => Permission::firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'module' => explode('.', $code, 2)[0]],
        )->id, $permissionCodes));
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);

        return [$user, $team];
    }

    public function test_without_the_feature_nobody_sees_hos_not_even_an_admin(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
        $this->assertFalse(Gate::allows('viewConfig', HosDriverState::class));
        $this->assertFalse(Gate::allows('updateConfig', HosDriverState::class));

        // Una fila apagada tampoco cuenta.
        $this->enable($owner->currentTeam, enabled: false);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
    }

    public function test_with_the_feature_each_ability_follows_its_permission(): void
    {
        [$user, $team] = $this->userWithRole(['drivers.view', 'config.view']);
        $this->enable($team);
        $this->actingAs($user);

        $this->assertTrue(Gate::allows('viewAny', HosDriverState::class));
        $this->assertTrue(Gate::allows('viewConfig', HosDriverState::class));
        $this->assertFalse(Gate::allows('updateConfig', HosDriverState::class));
    }

    public function test_the_feature_of_another_team_does_not_count(): void
    {
        $owner = User::factory()->create();
        $this->enable(Team::factory()->create());
        $this->actingAs($owner);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
    }

    public function test_the_nav_map_carries_hos_only_with_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('nav.hos', false)->where('nav.hosConfig', false));

        $this->enable($team);

        $this->actingAs($owner)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('nav.hos', true)->where('nav.hosConfig', true));
    }
}
