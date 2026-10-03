<?php

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Normalization\Queries\DbNormalizedEventStatsQuery;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * UI audit: dashboard usage never exposes raw provider cost (P0-1), AI
 * precision ignores placeholder verdicts (P0-3) and integration cards count
 * per integration (P1-3).
 */
class DashboardOpsCorrectnessTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private function moneyCounter(Team $team): TenantUsageCounter
    {
        $meter = UsageMeter::factory()->create([
            'code' => 'messaging_cost',
            'name' => 'Mensajería',
            'unit' => CostPlusPricing::MICRO_UNIT,
        ]);

        return TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'consumed_value' => 2_000_000, // 2 USD of provider cost
            'included_value' => 0,
        ]);
    }

    public function test_money_meters_are_hidden_from_roles_without_billing_permission(): void
    {
        $this->seed(AccessSeeder::class);

        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $monitor = User::factory()->create();
        // Monitorista: operates incidents, has no `tenancy.billing.view`.
        $team->members()->attach($monitor, [
            'role' => 'member',
            'role_id' => Role::query()->where('code', 'monitorista')->value('id'),
        ]);

        $this->moneyCounter($team);
        TenantUsageCounter::factory()->create(['team_id' => $team->id, 'consumed_value' => 5]);

        $this->actingAs($monitor)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload
                ->has('usage', 1)
                ->where('usage.0.amount', null)
                ->where('usage.0.unit', fn ($unit) => $unit !== CostPlusPricing::MICRO_UNIT)));
    }

    public function test_billing_roles_see_the_charged_amount_not_the_provider_cost(): void
    {
        $this->seed(AccessSeeder::class);

        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->moneyCounter($team);

        $expected = CostPlusPricing::charged(2_000_000, CostPlusPricing::defaultMarkup());
        $this->assertGreaterThan(2.0, $expected);

        $this->actingAs($owner)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload
                ->has('usage', 1)
                ->where('usage.0.amount', $expected)));
    }

    public function test_ai_precision_ignores_placeholder_null_agent_decisions(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $decision = function (string $model) use ($team): Decision {
            return Decision::factory()->create([
                'team_id' => $team->id,
                'decided_at' => now()->subDay(),
                'ai_evaluation_id' => AIEventEvaluation::factory()->create([
                    'team_id' => $team->id,
                    'model_used' => $model,
                ])->id,
            ]);
        };

        // Two real verdicts, one overridden: 50 %.
        $overridden = $decision('openai:gpt-5-mini');
        $decision('openai:gpt-5-mini');
        DecisionOverride::factory()->create(['decision_id' => $overridden->id]);

        // Placeholder verdicts would inflate precision to 80 % if counted.
        $decision('null-agent:1.0');
        $decision('null-agent:1.0');
        $decision('null-agent:1.0');

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('kpis', fn (Assert $reload) => $reload->where('kpis.aiPrecision.value', 50)));
    }

    public function test_ai_precision_is_empty_when_only_placeholder_verdicts_exist(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Decision::factory()->count(3)->create([
            'team_id' => $team->id,
            'decided_at' => now()->subDay(),
            'ai_evaluation_id' => fn () => AIEventEvaluation::factory()->create([
                'team_id' => $team->id,
                'model_used' => 'null-agent:1.0',
            ])->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('kpis', fn (Assert $reload) => $reload->where('kpis.aiPrecision.value', null)));
    }

    private function eventFor(TenantIntegration $integration): NormalizedEvent
    {
        $source = EventSource::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'tenant_integration_id' => $integration->id,
        ]);

        $raw = RawEvent::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'event_source_id' => $source->id,
        ]);

        return NormalizedEvent::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'raw_event_id' => $raw->id,
            'occurred_at' => now()->subHour(),
        ]);
    }

    public function test_integration_cards_count_and_name_each_integration(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create(['name' => 'Samsara']);

        $north = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Flota Norte',
        ]);
        $south = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Flota Sur',
        ]);

        $this->eventFor($north);
        $this->eventFor($north);
        $this->eventFor($south);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload
                ->has('integrations', 2)
                ->where('integrations.0.name', 'Flota Sur')
                ->where('integrations.0.provider', 'Samsara')
                ->where('integrations.0.events24h', 1)
                ->where('integrations.1.name', 'Flota Norte')
                ->where('integrations.1.events24h', 2)));
    }

    public function test_per_integration_counts_never_read_another_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        $integrationA = TenantIntegration::factory()->active()->create([
            'team_id' => $teamA->id,
            'provider_id' => $provider->id,
        ]);
        $this->eventFor($integrationA);

        $counts = $this->assertNoTenantLeak($teamB, fn () => app(DbNormalizedEventStatsQuery::class)
            ->countByIntegrationSince($teamB->id, now()->subDay()));

        $this->assertSame([], $counts);
        $this->assertSame(
            [$integrationA->id => 1],
            app(DbNormalizedEventStatsQuery::class)->countByIntegrationSince($teamA->id, now()->subDay()),
        );
    }
}
