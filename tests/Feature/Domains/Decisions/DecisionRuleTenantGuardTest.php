<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\ApplyTenantRuleSet;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Las reglas globales (team_id null) y los rulesets globales son de
 * plataforma: un tenant no puede editarlos, y las reglas que un tenant añade
 * a un ruleset global sólo se aplican a SUS eventos.
 */
class DecisionRuleTenantGuardTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(DecisionOutcomeSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
    }

    public function test_tenant_cannot_update_a_global_rule(): void
    {
        $rule = DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => RuleSet::factory()->global()->create()->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)->putJson(
            route('rules.decision.update', ['current_team' => $this->team->slug, 'rule' => $rule->id]),
            ['is_active' => false],
        )->assertForbidden();

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/decisions/rules/{$rule->id}",
            ['conditions_json' => ['all' => [['field' => 'risk_score', 'operator' => 'gte', 'value' => 2]]]],
        )->assertForbidden();

        $this->assertTrue((bool) $rule->fresh()->is_active);
    }

    public function test_tenant_cannot_delete_a_global_rule(): void
    {
        $rule = DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => RuleSet::factory()->global()->create()->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)->deleteJson(
            route('rules.decision.destroy', ['current_team' => $this->team->slug, 'rule' => $rule->id]),
        )->assertForbidden();

        $this->assertTrue((bool) $rule->fresh()->is_active);
    }

    public function test_tenant_cannot_update_another_tenants_rule(): void
    {
        $other = Team::factory()->create();
        $rule = DecisionRule::factory()->create([
            'team_id' => $other->id,
            'ruleset_id' => RuleSet::factory()->create(['team_id' => $other->id])->id,
        ]);

        $this->actingAs($this->owner)->putJson(
            route('rules.decision.update', ['current_team' => $this->team->slug, 'rule' => $rule->id]),
            ['is_active' => false],
        )->assertForbidden();
    }

    public function test_super_admin_can_update_a_global_rule(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $rule = DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => RuleSet::factory()->global()->create()->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson(
            route('rules.decision.update', ['current_team' => $this->team->slug, 'rule' => $rule->id]),
            ['is_active' => false],
        )->assertOk();

        $this->assertFalse((bool) $rule->fresh()->is_active);
    }

    public function test_rule_created_in_a_global_ruleset_belongs_to_the_tenant(): void
    {
        $global = RuleSet::factory()->global()->create(['is_default' => true]);

        $this->actingAs($this->owner)->postJson(
            route('rules.decision.store', ['current_team' => $this->team->slug]),
            [
                'ruleset_id' => $global->id,
                'code' => 'tenant-rule-in-global-set',
                'name' => 'Regla del tenant',
                'scope' => 'tenant',
                'conditions_json' => ['all' => [['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5]]],
            ],
        )->assertCreated();

        $this->assertSame(
            $this->team->id,
            DecisionRule::query()->where('code', 'tenant-rule-in-global-set')->value('team_id'),
        );
    }

    public function test_escalation_policy_of_another_tenant_is_rejected(): void
    {
        $other = Team::factory()->create();
        $foreignPolicy = EscalationPolicy::factory()->create(['team_id' => $other->id]);
        $ruleset = RuleSet::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)->postJson(
            route('rules.decision.store', ['current_team' => $this->team->slug]),
            [
                'ruleset_id' => $ruleset->id,
                'code' => 'foreign-escalation',
                'name' => 'Escala con política ajena',
                'scope' => 'tenant',
                'conditions_json' => ['all' => [['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5]]],
                'escalation_policy_id' => $foreignPolicy->id,
            ],
        )->assertUnprocessable()->assertJsonValidationErrors(['escalation_policy_id']);

        $rule = DecisionRule::factory()->create([
            'team_id' => $this->team->id,
            'ruleset_id' => $ruleset->id,
        ]);

        $this->actingAs($this->owner)->putJson(
            route('rules.decision.update', ['current_team' => $this->team->slug, 'rule' => $rule->id]),
            ['escalation_policy_id' => $foreignPolicy->id],
        )->assertUnprocessable()->assertJsonValidationErrors(['escalation_policy_id']);
    }

    public function test_another_tenants_rule_in_a_global_ruleset_does_not_run_on_my_events(): void
    {
        $global = RuleSet::factory()->global()->create(['code' => 'default', 'is_default' => true]);
        $attacker = Team::factory()->create();

        $globalRule = DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => $global->id,
            'code' => 'global-rule',
            'priority' => 10,
        ]);
        DecisionRule::factory()->create([
            'team_id' => $attacker->id,
            'ruleset_id' => $global->id,
            'code' => 'attacker-rule',
            'priority' => 200,
            'stop_processing' => true,
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->team->id,
            'classification' => EventClassification::RealEvent,
        ]);

        // Devuelve ids (la regla global tiene team_id null y el helper sólo
        // admite modelos del propio tenant); la fuga de escritura se sigue
        // comprobando.
        $matched = $this->assertNoTenantLeak(
            $this->team,
            fn () => app(ApplyTenantRuleSet::class)->execute($this->team->id, $eval)['matchedRules']->pluck('id')->all(),
        );

        $this->assertSame([$globalRule->id], $matched);
    }

    public function test_api_index_does_not_leak_other_tenants_rules_in_global_rulesets(): void
    {
        $global = RuleSet::factory()->global()->create();
        $attacker = Team::factory()->create();

        DecisionRule::factory()->create(['team_id' => null, 'ruleset_id' => $global->id, 'code' => 'global-rule']);
        DecisionRule::factory()->create(['team_id' => $this->team->id, 'ruleset_id' => $global->id, 'code' => 'mine']);
        DecisionRule::factory()->create(['team_id' => $attacker->id, 'ruleset_id' => $global->id, 'code' => 'secret-of-b']);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/{$this->team->slug}/decisions/rules")
            ->assertOk();

        $codes = collect($response->json('data'))->flatMap(fn ($set) => collect($set['rules'])->pluck('code'))->sort()->values()->all();

        $this->assertSame(['global-rule', 'mine'], $codes);
    }
}
