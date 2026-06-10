<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap B1b+F7: tenant-facing billing page and branding management.
 */
class BillingBrandingPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_billing_page_renders_plan_usage_and_invoices(): void
    {
        $plan = Plan::factory()->create(['name' => 'Flota Pro']);
        Subscription::factory()->create([
            'team_id' => $this->team->id,
            'plan_id' => $plan->id,
        ]);

        $meter = UsageMeter::factory()->create(['code' => 'ai_calls', 'name' => 'AI Calls']);
        TenantUsageCounter::factory()->create([
            'team_id' => $this->team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'consumed_value' => 120,
            'included_value' => 100,
            'overage_value' => 20,
        ]);

        TenantFeature::factory()->create([
            'team_id' => $this->team->id,
            'feature_key' => 'media_retrieval',
            'enabled' => true,
        ]);

        InvoiceSnapshot::factory()->create(['team_id' => $this->team->id]);

        $response = $this->actingAs($this->user)->get(
            route('billing.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('billing/index')
                ->where('subscription.planName', 'Flota Pro')
                ->has('usage', 1)
                ->where('usage.0.meterCode', 'ai_calls')
                ->where('usage.0.overage', 20)
                ->has('features', 1)
                ->has('invoices', 1),
        );
    }

    public function test_billing_page_hides_other_tenant_data(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        InvoiceSnapshot::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->actingAs($this->user)->get(
            route('billing.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(fn (Assert $page) => $page->has('invoices', 0));
    }

    public function test_branding_can_be_updated_via_web_route(): void
    {
        $response = $this->actingAs($this->user)->putJson(
            route('tenant-config.branding.update', ['current_team' => $this->team->slug]),
            [
                'display_name' => 'ServiExpress Operaciones',
                'primary_color' => '#2563eb',
                'secondary_color' => '#0f172a',
            ],
        );

        $response->assertOk();

        $branding = TenantBranding::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($branding);
        $this->assertSame('ServiExpress Operaciones', $branding->display_name);
        $this->assertSame('#2563eb', $branding->primary_color);
    }

    public function test_branding_rejects_invalid_color(): void
    {
        $this->actingAs($this->user)->putJson(
            route('tenant-config.branding.update', ['current_team' => $this->team->slug]),
            ['primary_color' => 'rojo'],
        )->assertStatus(422);
    }

    public function test_logo_upload_stores_file_object_and_sets_logo(): void
    {
        Storage::fake('rustfs');

        $response = $this->actingAs($this->user)->post(
            route('tenant-config.branding.logo', ['current_team' => $this->team->slug]),
            ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)],
        );

        $response->assertCreated();

        $branding = TenantBranding::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($branding?->logo_url);
        Storage::disk('rustfs')->assertExists($branding->logo_url);

        $this->assertDatabaseHas('file_objects', [
            'team_id' => $this->team->id,
            'category' => 'branding_logo',
        ]);
    }
}
