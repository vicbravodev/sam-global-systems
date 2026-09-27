<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de PR #113: el super-admin real TIENE un team actual (su equipo
 * personal), y las rutas /admin no fijan TenantContext. Si las Actions de
 * Tenancy dependen del scope global, filtran por el team del ADMIN y no por
 * el tenant sobre el que opera — duplicando filas o tocando las del admin.
 */
class AdminConsoleCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Super-admin con team actual propio, y con datos de Tenancy en ese team
     * para que un scope mal aplicado los encuentre (en vez de no encontrar nada).
     *
     * @return array{0: User, 1: Team}
     */
    private function superAdminWithOwnTeamData(): array
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $adminTeam = $admin->currentTeam;

        $this->assertNotNull($adminTeam, 'El super-admin debe tener team actual para reproducir el bug.');

        Subscription::factory()->create([
            'team_id' => $adminTeam->id,
            'status' => SubscriptionStatus::Active,
        ]);
        TenantFeature::factory()->create([
            'team_id' => $adminTeam->id,
            'feature_key' => 'live_map',
            'enabled' => true,
        ]);
        TenantBranding::factory()->create([
            'team_id' => $adminTeam->id,
            'display_name' => 'Admin Brand',
        ]);

        return [$admin, $adminTeam];
    }

    private function tenant(): Team
    {
        return Team::factory()->create(['is_personal' => false]);
    }

    public function test_changing_plan_of_suspended_tenant_keeps_a_single_suspended_subscription(): void
    {
        [$admin, $adminTeam] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        $original = Subscription::factory()->create([
            'team_id' => $tenant->id,
            'status' => SubscriptionStatus::Suspended,
        ]);
        $plan = Plan::factory()->create(['code' => 'growth']);
        $adminPlanId = Subscription::withoutGlobalScopes()->where('team_id', $adminTeam->id)->value('plan_id');

        $this->actingAs($admin)
            ->put(route('admin.tenants.subscription.update', $tenant), ['plan_code' => 'growth'])
            ->assertRedirect(route('admin.tenants.show', $tenant));

        $subscriptions = Subscription::withoutGlobalScopes()->where('team_id', $tenant->id)->get();

        $this->assertCount(1, $subscriptions);
        $this->assertSame($original->id, $subscriptions->first()->id);
        $this->assertSame($plan->id, $subscriptions->first()->plan_id);
        $this->assertSame(SubscriptionStatus::Suspended, $subscriptions->first()->status);

        // La suscripción del admin no se toca.
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('team_id', $adminTeam->id)->count());
        $this->assertSame($adminPlanId, Subscription::withoutGlobalScopes()->where('team_id', $adminTeam->id)->value('plan_id'));
    }

    public function test_suspending_a_tenant_suspends_its_subscription_not_the_admins(): void
    {
        [$admin, $adminTeam] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        Subscription::factory()->create(['team_id' => $tenant->id, 'status' => SubscriptionStatus::Active]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.subscription.suspend', $tenant))
            ->assertRedirect();

        $this->assertSame(SubscriptionStatus::Suspended, Subscription::withoutGlobalScopes()
            ->where('team_id', $tenant->id)->sole()->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::withoutGlobalScopes()
            ->where('team_id', $adminTeam->id)->sole()->status);
    }

    public function test_changing_plan_preserves_the_tenants_manual_feature_overrides(): void
    {
        [$admin] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        Subscription::factory()->create(['team_id' => $tenant->id]);
        $plan = Plan::factory()->create(['code' => 'growth']);
        $meter = UsageMeter::factory()->create(['code' => 'live_map']);
        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 10,
        ]);
        TenantFeature::factory()->create([
            'team_id' => $tenant->id,
            'feature_key' => 'live_map',
            'enabled' => false,
            'source' => FeatureSource::ManualOverride,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.tenants.subscription.update', $tenant), ['plan_code' => 'growth'])
            ->assertRedirect();

        $features = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $tenant->id)
            ->where('feature_key', 'live_map')
            ->get();

        $this->assertCount(1, $features);
        $this->assertFalse($features->first()->enabled);
        $this->assertSame(FeatureSource::ManualOverride, $features->first()->source);
    }

    public function test_toggling_an_existing_tenant_feature_updates_it_in_place(): void
    {
        [$admin, $adminTeam] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        $existing = TenantFeature::factory()->create([
            'team_id' => $tenant->id,
            'feature_key' => 'live_map',
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.tenants.features.update', [$tenant, 'live_map']), ['enabled' => false])
            ->assertRedirect(route('admin.tenants.show', $tenant));

        $features = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $tenant->id)
            ->where('feature_key', 'live_map')
            ->get();

        $this->assertCount(1, $features);
        $this->assertSame($existing->id, $features->first()->id);
        $this->assertFalse($features->first()->enabled);
        $this->assertSame(FeatureSource::ManualOverride, $features->first()->source);

        $this->assertTrue(TenantFeature::withoutGlobalScopes()
            ->where('team_id', $adminTeam->id)
            ->where('feature_key', 'live_map')
            ->value('enabled'));
    }

    public function test_updating_tenant_branding_persists_a_single_row(): void
    {
        [$admin, $adminTeam] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        $branding = TenantBranding::factory()->create([
            'team_id' => $tenant->id,
            'display_name' => 'Antes',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.tenants.update', $tenant), [
                'name' => 'Tenant Renombrado',
                'display_name' => 'Después',
                'primary_color' => '#2563eb',
            ])
            // Renombrar regenera el slug: la redirección apunta al nuevo.
            ->assertRedirect(route('admin.tenants.show', $tenant->fresh()));

        $rows = TenantBranding::withoutGlobalScopes()->where('team_id', $tenant->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($branding->id, $rows->first()->id);
        $this->assertSame('Después', $rows->first()->display_name);
        $this->assertSame('#2563eb', $rows->first()->primary_color);

        $this->assertSame('Admin Brand', TenantBranding::withoutGlobalScopes()
            ->where('team_id', $adminTeam->id)
            ->value('display_name'));
    }

    public function test_show_page_renders_the_target_tenants_data_not_the_admins(): void
    {
        [$admin] = $this->superAdminWithOwnTeamData();
        $tenant = $this->tenant();
        TenantBranding::factory()->create(['team_id' => $tenant->id, 'display_name' => 'Tenant Brand']);

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/tenants/show')
                ->where('tenant.branding.displayName', 'Tenant Brand')
                ->where('subscription', null));
    }

    public function test_creating_a_tenant_seeds_its_rows_in_the_new_team_only(): void
    {
        [$admin, $adminTeam] = $this->superAdminWithOwnTeamData();
        $owner = User::factory()->create();
        Plan::factory()->create(['code' => 'starter']);

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'Nuevo Tenant',
                'plan_code' => 'starter',
                'owner_email' => $owner->email,
            ])
            ->assertRedirect();

        $tenant = Team::query()->where('name', 'Nuevo Tenant')->firstOrFail();

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('team_id', $tenant->id)->count());
        $this->assertSame(1, TenantBranding::withoutGlobalScopes()->where('team_id', $tenant->id)->count());
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('team_id', $adminTeam->id)->count());
        $this->assertSame(1, TenantBranding::withoutGlobalScopes()->where('team_id', $adminTeam->id)->count());
    }
}
