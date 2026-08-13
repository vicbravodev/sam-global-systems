<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\RecordAssetTelemetry;
use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Jobs\PollAllAssetTelemetryJob;
use App\Domains\Assets\Jobs\PollAssetTelemetryJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PollAssetTelemetryJobTest extends TestCase
{
    use RefreshDatabase;

    /** Owner of the most recently created integration's team. */
    private User $owner;

    private function makeSamsaraIntegration(array $attributes = []): TenantIntegration
    {
        $user = $this->owner = User::factory()->create();
        // `code` is unique, so tests with two tenants share one provider row.
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
            ...$attributes,
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    private function linkAsset(TenantIntegration $integration, string $externalId): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);

        AssetExternalReference::create([
            'asset_id' => $asset->id,
            'provider_id' => $integration->provider_id,
            'external_id' => $externalId,
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $asset;
    }

    private function fakeStats(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 54],
                        'engineStates' => ['time' => '2026-08-12T10:00:00Z', 'value' => 'On'],
                        'batteryMilliVolts' => ['time' => '2026-08-12T10:00:00Z', 'value' => 12640],
                    ],
                    // Unknown asset — no external reference yet, must be skipped.
                    ['id' => '999', 'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 10]],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);
    }

    public function test_it_persists_telemetry_for_known_assets_and_skips_unknown(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');
        $this->fakeStats();

        (new PollAssetTelemetryJob($integration))->handle(
            app(ProviderAdapter::class),
            app(ResolveAssetFromExternalId::class),
            app(RecordAssetTelemetry::class),
        );

        $this->assertSame(3, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
        $this->assertSame(3, AssetTelemetrySnapshot::count(), 'The unknown vehicle must not create rows.');

        $fuel = AssetTelemetrySnapshot::where('telemetry_type', TelemetryType::Fuel)->sole();
        $this->assertEqualsWithDelta(54.0, $fuel->data_json['value'], 0.001);
        $this->assertSame('%', $fuel->data_json['unit']);
    }

    public function test_it_marks_the_integration_as_polled(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $this->linkAsset($integration, '100');
        $this->fakeStats();

        $this->assertNull($integration->last_telemetry_poll_at);

        (new PollAssetTelemetryJob($integration))->handle(
            app(ProviderAdapter::class),
            app(ResolveAssetFromExternalId::class),
            app(RecordAssetTelemetry::class),
        );

        $this->assertNotNull($integration->fresh()->last_telemetry_poll_at);
    }

    public function test_a_second_poll_with_unchanged_values_adds_no_rows(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');
        $this->fakeStats();

        foreach (range(1, 2) as $ignored) {
            (new PollAssetTelemetryJob($integration))->handle(
                app(ProviderAdapter::class),
                app(ResolveAssetFromExternalId::class),
                app(RecordAssetTelemetry::class),
            );
        }

        $this->assertSame(3, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
    }

    public function test_the_orchestrator_dispatches_for_due_integrations_only(): void
    {
        Bus::fake();

        $due = $this->makeSamsaraIntegration(['last_telemetry_poll_at' => now()->subHour()]);
        $this->makeSamsaraIntegration(['last_telemetry_poll_at' => now()->subMinute()]);

        (new PollAllAssetTelemetryJob)->handle();

        Bus::assertDispatchedTimes(PollAssetTelemetryJob::class, 1);
        Bus::assertDispatched(
            PollAssetTelemetryJob::class,
            fn (PollAssetTelemetryJob $job) => $job->integration->is($due),
        );
    }

    public function test_the_orchestrator_honours_the_per_integration_opt_out(): void
    {
        Bus::fake();

        $this->makeSamsaraIntegration([
            'config_json' => ['sync' => ['poll_telemetry' => false]],
        ]);

        (new PollAllAssetTelemetryJob)->handle();

        Bus::assertNotDispatched(PollAssetTelemetryJob::class);
    }

    public function test_a_poll_makes_telemetry_appear_on_the_asset_detail_page(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');
        $this->fakeStats();

        (new PollAssetTelemetryJob($integration))->handle(
            app(ProviderAdapter::class),
            app(ResolveAssetFromExternalId::class),
            app(RecordAssetTelemetry::class),
        );

        $response = $this->actingAs($this->owner)->get(route('assets.show', [
            'current_team' => $asset->team->slug,
            'asset' => $asset->id,
        ]));

        // The regression this whole PR exists for: the detail page had a
        // telemetry panel, a model and an API, but nothing ever wrote a row.
        // Asserting through the real poll (not a factory) is what catches that.
        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('assets/show')
                ->has('telemetry', 3)
                ->where('telemetry.0.type', 'fuel')
                ->where('telemetry.0.label', 'Combustible')
                ->where('telemetry.0.data.unit', '%'),
        );
    }

    public function test_it_never_writes_telemetry_onto_another_tenants_asset(): void
    {
        $first = $this->makeSamsaraIntegration();
        $firstAsset = $this->linkAsset($first, '100');

        // `asset_external_references` is unique on (provider_id, external_id)
        // globally, so the other tenant owns a different id — and the resolver
        // deliberately ignores tenant scope when looking it up.
        $second = $this->makeSamsaraIntegration();
        $otherTenantAsset = $this->linkAsset($second, '999');

        $this->fakeStats();

        (new PollAssetTelemetryJob($first))->handle(
            app(ProviderAdapter::class),
            app(ResolveAssetFromExternalId::class),
            app(RecordAssetTelemetry::class),
        );

        $this->assertSame(3, AssetTelemetrySnapshot::where('asset_id', $firstAsset->id)->count());
        $this->assertSame(
            0,
            AssetTelemetrySnapshot::where('asset_id', $otherTenantAsset->id)->count(),
            'A poll for one tenant must never write rows against another tenant asset.',
        );
    }
}
