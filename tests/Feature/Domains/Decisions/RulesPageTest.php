<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F11: the rules page (decision rules + mapping rules + tenant
 * overrides) and its web mutations reusing the API controllers.
 */
class RulesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(DecisionOutcomeSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_page_renders_the_three_rule_sets(): void
    {
        $ruleset = RuleSet::factory()->create([
            'team_id' => $this->team->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        DecisionRule::factory()->create([
            'team_id' => $this->team->id,
            'ruleset_id' => $ruleset->id,
            'code' => 'panic-button-always-incident',
        ]);

        // Global seed rule must be visible too.
        DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => $ruleset->id,
            'code' => 'global-noise-filter',
        ]);

        EventMappingRule::factory()->create();
        TenantRuleOverride::factory()->create(['team_id' => $this->team->id]);

        $response = $this->actingAs($this->user)->get(
            route('rules.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('rules/index')
                ->has('decisionRules', 2)
                ->has('rulesets', 1)
                ->has('outcomes', 6)
                ->has('mappingRules', 1)
                ->has('mappingOptions.providers')
                ->has('overrides', 1)
                ->has('overrideTypes')
                ->where('canManageDecisionRules', true)
                ->where('canManageOverrides', true),
        );
    }

    public function test_page_hides_other_tenant_rules_and_overrides(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        $foreignRuleset = RuleSet::factory()->create(['team_id' => $otherTeam->id]);

        DecisionRule::factory()->create([
            'team_id' => $otherTeam->id,
            'ruleset_id' => $foreignRuleset->id,
        ]);
        TenantRuleOverride::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->actingAs($this->user)->get(
            route('rules.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('decisionRules', 0)
                ->has('overrides', 0),
        );
    }

    public function test_decision_rule_can_be_created_via_web_route(): void
    {
        $ruleset = RuleSet::factory()->create([
            'team_id' => $this->team->id,
            'is_default' => true,
        ]);
        $outcome = DecisionOutcome::firstWhere('code', 'INCIDENT');

        $response = $this->actingAs($this->user)->postJson(
            route('rules.decision.store', ['current_team' => $this->team->slug]),
            [
                'ruleset_id' => $ruleset->id,
                'code' => 'vip-asset-incident',
                'name' => 'Activos VIP → incidente',
                'scope' => 'tenant',
                'priority' => 120,
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5],
                    ],
                ],
                'outcome_override' => $outcome->id,
                'stop_processing' => true,
                'is_active' => true,
            ],
        );

        $response->assertCreated();

        $this->assertSame(1, DecisionRule::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('code', 'vip-asset-incident')
            ->count());
    }

    public function test_page_exposes_the_condition_field_catalog(): void
    {
        $response = $this->actingAs($this->user)->get(
            route('rules.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('conditionFields', 16)
                ->where('conditionFields.0.key', 'classification')
                ->has('conditionFields.0.options')
                ->has('conditionFields.0.operators'),
        );
    }

    public function test_decision_rule_with_invalid_condition_tree_is_rejected(): void
    {
        $ruleset = RuleSet::factory()->create([
            'team_id' => $this->team->id,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.decision.store', ['current_team' => $this->team->slug]),
            [
                'ruleset_id' => $ruleset->id,
                'code' => 'invalid-conditions',
                'name' => 'Regla con condiciones rotas',
                'scope' => 'tenant',
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'between', 'value' => 0.5],
                    ],
                ],
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['conditions_json']);
    }

    public function test_mapping_rule_with_invalid_flat_conditions_is_rejected(): void
    {
        $existing = EventMappingRule::factory()->create();

        $response = $this->actingAs($this->user)->postJson(
            route('rules.mapping.store', ['current_team' => $this->team->slug]),
            [
                'provider_id' => $existing->provider_id,
                'external_event_type' => 'EdgePanicButton',
                'mapped_event_type_id' => $existing->mapped_event_type_id,
                'external_conditions_json' => ['data.alert.type' => ['nested' => 'array']],
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['external_conditions_json']);
    }

    public function test_decision_rule_can_be_deactivated_via_web_route(): void
    {
        $ruleset = RuleSet::factory()->create(['team_id' => $this->team->id]);
        $rule = DecisionRule::factory()->create([
            'team_id' => $this->team->id,
            'ruleset_id' => $ruleset->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->putJson(
            route('rules.decision.update', [
                'current_team' => $this->team->slug,
                'rule' => $rule->id,
            ]),
            ['is_active' => false],
        );

        $response->assertOk();
        $this->assertFalse((bool) $rule->fresh()->is_active);
    }

    public function test_mapping_rule_can_be_created_via_web_route(): void
    {
        $existing = EventMappingRule::factory()->create();

        $response = $this->actingAs($this->user)->postJson(
            route('rules.mapping.store', ['current_team' => $this->team->slug]),
            [
                'provider_id' => $existing->provider_id,
                'external_event_type' => 'EdgeRailroadCrossingViolation',
                'mapped_event_type_id' => $existing->mapped_event_type_id,
                'priority' => 90,
                'is_active' => true,
            ],
        );

        $response->assertCreated();

        $this->assertSame(1, EventMappingRule::query()
            ->where('external_event_type', 'EdgeRailroadCrossingViolation')
            ->count());
    }

    public function test_override_can_be_created_and_deleted_via_web_routes(): void
    {
        $store = $this->actingAs($this->user)->postJson(
            route('rules.overrides.store', ['current_team' => $this->team->slug]),
            [
                'base_rule_code' => 'panic-button-always-incident',
                'override_type' => 'force_human_review',
                'override_config' => ['note' => 'pilot'],
                'reason' => 'Piloto de revisión humana',
            ],
        );

        $store->assertCreated();

        $override = TenantRuleOverride::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($override);

        $this->actingAs($this->user)->deleteJson(
            route('rules.overrides.destroy', [
                'current_team' => $this->team->slug,
                'override' => $override->id,
            ]),
        )->assertNoContent();

        $this->assertSame(0, TenantRuleOverride::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->count());
    }
}
