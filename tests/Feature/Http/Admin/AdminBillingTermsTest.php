<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\ResolveBillingTerms;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AdminBillingTermsTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

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

    public function test_volume_tiers_from_json_are_normalized_to_the_promised_shape(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        // JSON tal como puede venir de la fila: números como texto, `to`
        // ausente o vacío, una entrada basura que no es escalón y un escalón
        // sin precio (se descarta: nunca cobrar 0 en silencio).
        TenantBillingTerms::factory()->create([
            'team_id' => $team->id,
            'volume_tiers_json' => [
                ['from' => '1', 'to' => '25', 'unit_price' => '500'],
                ['from' => 26, 'unit_price' => 450.5],
                'no-es-un-escalon',
                ['from' => 500],
            ],
        ]);

        $terms = app(ResolveBillingTerms::class)->execute($team->id);

        $this->assertSame([
            ['from' => 1, 'to' => 25, 'unit_price' => 500.0],
            ['from' => 26, 'to' => null, 'unit_price' => 450.5],
        ], $terms->volumeTiers);
        $this->assertSame(500.0, $terms->unitPriceFor(20));
        $this->assertSame(450.5, $terms->unitPriceFor(300));
        $this->assertNull($terms->explainUnitPriceFor(300)['tier_to']);
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

        $requested = $this->assertSystemLogged('billing.invoice.generation_requested');
        $this->assertSame($team->id, $requested['input']['team_id']);
        $this->assertSame($admin->id, $requested['input']['actor_user_id']);
        $this->assertSame('2026-09-01', $requested['input']['period_start']);
        $this->assertSame('2026-09-30', $requested['input']['period_end']);
        $this->assertTrue($requested['result']['chain_requested']);
        $this->assertSystemNotLogged('billing.invoice.already_exists');

        // Repetir no duplica.
        $this->actingAs($admin)->post(route('admin.tenants.invoices.generate', $team))->assertRedirect();
        $this->assertSame(1, InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->count());

        $exists = $this->assertSystemLogged('billing.invoice.already_exists', fn (array $c) => $c['input']['stage'] === 'admin_request');
        $this->assertSame('period_already_invoiced', $exists['reason']);
        $this->assertSame($team->id, $exists['input']['team_id']);
        $this->assertSame($admin->id, $exists['input']['actor_user_id']);
        $this->assertSame('2026-09-01', $exists['input']['period_start']);
        $this->assertCount(1, $this->systemLogEntries('billing.invoice.generation_requested'));

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($admin->email, $json);
        $this->assertStringNotContainsString(json_encode($team->name), $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_super_admin_generates_an_explicit_period_invoice_on_demand(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        Subscription::factory()->create(['team_id' => $team->id]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.invoices.generate', $team), ['period' => '2026-02'])
            ->assertRedirect();

        $invoice = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame('2026-02-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-02-28', $invoice->period_end->toDateString());

        $requested = $this->assertSystemLogged('billing.invoice.generation_requested');
        $this->assertSame('2026-02-01', $requested['input']['period_start']);
        $this->assertSame('2026-02-28', $requested['input']['period_end']);
        $this->assertNoSensitiveDataLogged();
    }
}
