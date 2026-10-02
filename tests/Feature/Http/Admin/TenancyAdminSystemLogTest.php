<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Listeners\SyncCatalogOnIntegrationConnected;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Fase 6 del log narrativo: lo que el super-admin cambia de un tenant
 * (features, suscripción, plan, baja, términos) y la marca del tenant dejan
 * su código, con el tenant afectado y el actor, sin nombres ni notas.
 */
class TenancyAdminSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private User $admin;

    private Team $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['global_role' => 'super_admin']);
        $this->tenant = Team::factory()->create(['is_personal' => false, 'name' => 'Transportes Ana']);
    }

    private function assertTenantNameNeverLogged(): void
    {
        $this->assertStringNotContainsString('Transportes Ana', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_feature_toggle_is_logged_with_previous_and_new_state(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.tenants.features.update', [$this->tenant, 'live_map']), ['enabled' => false])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('tenancy.feature.changed');
        $this->assertSame(['team_id' => $this->tenant->id, 'feature_key' => 'live_map', 'actor_id' => $this->admin->id], $ctx['input']);
        $this->assertNull($ctx['result']['previous_enabled']);
        $this->assertFalse($ctx['result']['enabled']);
        $this->assertTenantNameNeverLogged();
    }

    public function test_suspending_a_tenant_is_logged_with_its_access_consequence(): void
    {
        $subscription = Subscription::factory()->create(['team_id' => $this->tenant->id, 'status' => SubscriptionStatus::Active]);

        $this->actingAs($this->admin)->post(route('admin.tenants.subscription.suspend', $this->tenant))->assertRedirect();

        $ctx = $this->assertSystemLogged('tenancy.subscription.status_changed');
        $this->assertSame($subscription->id, $ctx['input']['subscription_id']);
        $this->assertSame('active', $ctx['result']['previous_status']);
        $this->assertSame('suspended', $ctx['result']['status']);
        $this->assertFalse($ctx['result']['grants_operational_access']);
        $this->assertTenantNameNeverLogged();
    }

    public function test_a_plan_change_is_logged_after_commit(): void
    {
        $original = Subscription::factory()->create(['team_id' => $this->tenant->id, 'status' => SubscriptionStatus::Active]);
        $plan = Plan::factory()->create(['code' => 'growth']);

        $this->actingAs($this->admin)
            ->put(route('admin.tenants.subscription.update', $this->tenant), ['plan_code' => 'growth'])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('tenancy.plan.changed');
        $this->assertSame('growth', $ctx['input']['plan_code']);
        $this->assertSame($original->id, $ctx['result']['subscription_id']);
        $this->assertFalse($ctx['result']['subscription_created']);
        $this->assertSame($original->plan_id, $ctx['result']['previous_plan_id']);
        $this->assertSame($plan->id, $ctx['result']['plan_id']);
        $this->assertTenantNameNeverLogged();
    }

    public function test_deleting_a_tenant_is_logged_and_a_personal_team_is_refused(): void
    {
        $member = User::factory()->create();
        $this->tenant->members()->attach($member, ['role' => 'member']);
        $member->switchTeam($this->tenant);

        $this->actingAs($this->admin)->delete(route('admin.tenants.destroy', $this->tenant))->assertRedirect();

        $ctx = $this->assertSystemLogged('tenancy.tenant.deleted', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame($this->tenant->id, $ctx['input']['team_id']);
        $this->assertSame(1, $ctx['result']['users_switched_away']);

        $personal = Team::factory()->create(['is_personal' => true]);

        try {
            app(DeleteTenant::class)->execute($personal);
            $this->fail('Debió negarse.');
        } catch (RuntimeException) {
        }

        $this->assertSystemLogged('tenancy.tenant.deleted', fn (array $c) => ($c['reason'] ?? null) === 'personal_team');
        $this->assertTenantNameNeverLogged();
    }

    public function test_billing_terms_log_which_fields_are_overridden_never_the_notes(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.tenants.billing-terms.update', $this->tenant), [
                'unit_price' => 400,
                'currency' => 'MXN',
                'included_assets' => 120,
                'notes' => 'Contrato firmado con Ana',
            ])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('tenancy.billing_terms.updated');
        $this->assertEqualsCanonicalizing(['unit_price', 'currency', 'included_assets'], $ctx['result']['overridden_fields']);
        $this->assertTrue($ctx['result']['notes_present']);
        $this->assertStringNotContainsString('Contrato firmado', (string) json_encode($this->systemLogEntries()));
        $this->assertTenantNameNeverLogged();
    }

    public function test_branding_changes_log_the_changed_fields_and_the_logo_file_object(): void
    {
        $this->seed(AccessSeeder::class);
        Storage::fake('rustfs');
        $owner = User::factory()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)->putJson(route('tenant-config.branding.update', ['current_team' => $team->slug]), [
            'display_name' => 'Operaciones de Ana',
            'primary_color' => '#2563eb',
        ])->assertOk();

        $ctx = $this->assertSystemLogged('tenancy.branding.updated');
        $this->assertEqualsCanonicalizing(['display_name', 'primary_color'], $ctx['result']['changed_fields']);
        $this->assertSame($owner->id, $ctx['input']['user_id']);

        $this->actingAs($owner)->post(route('tenant-config.branding.logo', ['current_team' => $team->slug]), [
            'logo' => UploadedFile::fake()->image('logo-ana-perez.png', 64, 64),
        ])->assertCreated();

        $logo = $this->assertSystemLogged('tenancy.branding.logo_uploaded');
        $this->assertSame($team->id, $logo['input']['team_id']);
        $this->assertSame('image/png', $logo['result']['content_type']);

        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('Operaciones de Ana', $json);
        $this->assertStringNotContainsString('logo-ana-perez', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_connecting_an_integration_requests_the_catalog_sync(): void
    {
        Queue::fake();
        $integration = TenantIntegration::factory()->active()->create(['team_id' => $this->tenant->id]);

        app(SyncCatalogOnIntegrationConnected::class)->handle(new IntegrationConnected($this->tenant->id, $integration->id, 'samsara'));

        $ctx = $this->assertSystemLogged('integrations.catalog_sync.requested', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(['team_id' => $this->tenant->id, 'integration_id' => $integration->id], $ctx['input']);
        $this->assertSame('full', $ctx['result']['type']);

        app(SyncCatalogOnIntegrationConnected::class)->handle(new IntegrationConnected($this->tenant->id, 999_999, 'samsara'));
        $this->assertSystemLogged('integrations.catalog_sync.requested', fn (array $c) => ($c['reason'] ?? null) === 'integration_missing');
        $this->assertTenantNameNeverLogged();
    }
}
