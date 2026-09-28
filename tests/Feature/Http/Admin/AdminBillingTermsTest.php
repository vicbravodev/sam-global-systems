<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\ResolveBillingTerms;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBillingTermsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_platform_defaults_apply_until_the_operator_sets_terms(): void
    {
        config()->set('billing.unit_price', 450);
        config()->set('billing.currency', 'mxn');
        $team = Team::factory()->create(['is_personal' => false]);

        $terms = app(ResolveBillingTerms::class)->execute($team->id);

        $this->assertFalse($terms->explicit);
        $this->assertSame(450.0, $terms->unitPrice);
        $this->assertSame('mxn', $terms->currency);
        $this->assertNull($terms->includedAssets);
    }

    public function test_super_admin_sets_terms_and_they_drive_the_asset_cap(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        // Un tope por feature que los términos deben ganar.
        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'monitored_assets',
            'enabled' => true,
            'source' => FeatureSource::ManualOverride,
            'limits_json' => ['included_quantity' => 25],
        ]);

        $this->actingAs($admin)
            ->put(route('admin.tenants.billing-terms.update', $team), [
                'unit_price' => 400,
                'currency' => 'MXN',
                'included_assets' => 120,
                'min_billable_assets' => 10,
                'ai_fair_use_per_asset' => 50,
                'ai_overage_unit_price' => 4,
                'fx_usd_rate' => 19.2,
                'notes' => 'Contrato 2026',
            ])
            ->assertRedirect(route('admin.tenants.show', $team));

        $this->assertDatabaseHas('tenant_billing_terms', [
            'team_id' => $team->id,
            'currency' => 'mxn',
            'included_assets' => 120,
            'min_billable_assets' => 10,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.billing_terms_updated',
        ]);

        $terms = app(ResolveBillingTerms::class)->execute($team->id);
        $this->assertTrue($terms->explicit);
        $this->assertSame(400.0, $terms->unitPrice);
        $this->assertSame(120, app(ResolveAssetLimit::class)->execute($team->id));

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('billingTerms.unit_price', 400)
                ->where('billingTerms.included_assets', 120)
                ->where('assetUsage.limit', 120)
                ->has('billingDefaults'));
    }

    public function test_non_super_admin_cannot_set_terms(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.tenants.billing-terms.update', $team), ['unit_price' => 1])
            ->assertForbidden();
    }

    public function test_super_admin_generates_the_previous_month_invoice_on_demand(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        Subscription::factory()->create(['team_id' => $team->id]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.invoices.generate', $team))
            ->assertRedirect();

        $invoice = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame('2026-09-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-09-30', $invoice->period_end->toDateString());
        $this->assertSame('finalized', $invoice->status->value);
        $this->assertDatabaseHas('audit_logs', ['team_id' => $team->id, 'action' => 'tenant.invoice_generated']);

        // Repetir no duplica.
        $this->actingAs($admin)->post(route('admin.tenants.invoices.generate', $team))->assertRedirect();
        $this->assertSame(1, InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }
}
