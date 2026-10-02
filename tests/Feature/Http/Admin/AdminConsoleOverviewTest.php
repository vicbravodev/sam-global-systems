<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lo que la consola le cuenta al operador sobre cada cliente: en qué etapa
 * del alta va, qué le falta para operar y que cada acción deja feedback.
 */
class AdminConsoleOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function tenantWithOwner(bool $activated = true): Team
    {
        $team = Team::factory()->create(['is_personal' => false, 'timezone' => null]);
        $owner = $activated ? User::factory()->create() : User::factory()->unverified()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        return $team;
    }

    private function operating(Team $team): void
    {
        $integration = TenantIntegration::factory()->active()->create(['team_id' => $team->id]);
        WebhookEndpoint::factory()->create(['tenant_integration_id' => $integration->id]);
        Asset::factory()->create(['team_id' => $team->id]);
    }

    public function test_the_directory_shows_each_clients_onboarding_stage(): void
    {
        $pendingOwner = $this->tenantWithOwner(activated: false);
        $noIntegration = $this->tenantWithOwner();
        $noAssets = $this->tenantWithOwner();
        TenantIntegration::factory()->active()->create(['team_id' => $noAssets->id]);
        $operating = $this->tenantWithOwner();
        $this->operating($operating);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($pendingOwner, $noIntegration, $noAssets, $operating) {
                $page->component('admin/tenants/index')
                    ->where('stats.total', 4)
                    ->where('stats.operating', 1)
                    ->where('stats.onboarding', 3);

                $stages = collect($page->toArray()['props']['tenants'])->pluck('stage', 'id');
                $this->assertSame('owner_pending', $stages[$pendingOwner->id]);
                $this->assertSame('integration_pending', $stages[$noIntegration->id]);
                $this->assertSame('assets_pending', $stages[$noAssets->id]);
                $this->assertSame('operating', $stages[$operating->id]);
            });
    }

    public function test_the_directory_flags_a_pending_owner_access(): void
    {
        $team = $this->tenantWithOwner(activated: false);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenants.0.id', $team->id)
                ->where('tenants.0.owner.pendingAccess', true)
                ->where('tenants.0.monitoredAssets', 0));
    }

    public function test_the_detail_lists_what_is_missing_to_operate(): void
    {
        $team = $this->tenantWithOwner();
        $integration = TenantIntegration::factory()->active()->create(['team_id' => $team->id]);
        WebhookEndpoint::factory()->withoutSecret()->create(['tenant_integration_id' => $integration->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.show', $team))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('admin/tenants/show')->has('setup.steps', 6);
                $steps = collect($page->toArray()['props']['setup']['steps'])->keyBy('key');

                $this->assertTrue($steps['owner']['done']);
                $this->assertTrue($steps['integration']['done']);
                $this->assertFalse($steps['webhook']['done'], 'Sin Secret Key los pánicos se rechazan.');
                $this->assertStringContainsString('Secret Key', $steps['webhook']['detail']);
                $this->assertFalse($steps['assets']['done']);
                $this->assertFalse($steps['contacts']['done']);
                $this->assertFalse($steps['timezone']['done']);
                $this->assertSame(2, $page->toArray()['props']['setup']['completed']);
            });
    }

    public function test_a_fully_configured_client_completes_the_checklist(): void
    {
        $team = $this->tenantWithOwner();
        $team->forceFill(['timezone' => 'America/Monterrey'])->save();
        $this->operating($team);
        $monitor = User::factory()->withVerifiedPhone()->create();
        $team->members()->attach($monitor, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.show', $team))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.completed', 6)
                ->where('setup.total', 6)
                ->where('tenant.timezone', 'America/Monterrey'));
    }

    public function test_the_checklist_never_counts_another_tenants_data(): void
    {
        $team = $this->tenantWithOwner();
        $other = $this->tenantWithOwner();
        $this->operating($other);
        $other->members()->attach(User::factory()->withVerifiedPhone()->create(), ['role' => TeamRole::Member->value]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.show', $team))
            ->assertInertia(fn (Assert $page) => $page->where('setup.completed', 1));
    }

    public function test_the_operator_can_set_the_client_timezone(): void
    {
        $team = $this->tenantWithOwner();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.tenants.update', $team), ['name' => $team->name, 'timezone' => 'America/Tijuana'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('America/Tijuana', $team->fresh()?->timezone);
    }

    public function test_an_invalid_timezone_is_rejected_on_update(): void
    {
        $team = $this->tenantWithOwner();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.tenants.update', $team), ['name' => $team->name, 'timezone' => 'Marte/Base'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_feature_keys_must_be_codes(): void
    {
        $team = $this->tenantWithOwner();

        $this->actingAs($this->superAdmin())
            ->put('/admin/tenants/'.$team->slug.'/features/'.rawurlencode('Junk Key!'), ['enabled' => true])
            ->assertNotFound();
    }

    public function test_feature_changes_are_visible_in_the_console_audit(): void
    {
        $team = $this->tenantWithOwner();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.tenants.features.update', [$team, 'live_map']), ['enabled' => false]);

        $entry = TenantContext::withoutTenant(fn () => AuditLog::query()->where('action', 'tenant.feature_updated')->sole());
        $this->assertSame(AuditCategory::Billing, $entry->category);
    }

    public function test_server_feedback_reaches_the_ui_as_a_toast(): void
    {
        $team = $this->tenantWithOwner();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.tenants.update', $team), ['name' => 'Nuevo nombre'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Cliente actualizado.');
    }

    public function test_a_failure_is_reported_as_an_error_toast_not_a_success(): void
    {
        $team = $this->tenantWithOwner();

        // Sin suscripción: antes se mostraba "Suscripción suspendida." en verde.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.tenants.subscription.suspend', $team))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'El tenant no tiene suscripción.');
    }
}
