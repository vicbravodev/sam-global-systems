<?php

namespace Database\Seeders;

use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Tenant decision rules for the Samsara test fleet so panic-button events
 * deterministically create an incident (instead of relying on the engine's
 * default critical path). Pairs with SamsaraTestSeeder + SamsaraReplayCommand.
 *
 * Run: php artisan db:seed --class=SamsaraTestDecisionRulesSeeder
 */
class SamsaraTestDecisionRulesSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::query()->where('slug', 'samsara-test')->first();

        if (! $team) {
            $this->command?->warn('Team [samsara-test] not found; seed SamsaraTestSeeder first.');

            return;
        }

        $incident = DecisionOutcome::query()->where('code', 'INCIDENT')->first();

        if (! $incident) {
            $this->command?->warn('DecisionOutcome [INCIDENT] not found; seed DecisionOutcomeSeeder first.');

            return;
        }

        $ruleSet = RuleSet::query()->updateOrCreate(
            ['team_id' => $team->id, 'code' => 'samsara-test-default'],
            [
                'name' => 'Samsara Test — Default Ruleset',
                'description' => 'Tenant default ruleset for manual Samsara pipeline testing.',
                'version' => 1,
                'is_default' => true,
                'is_active' => true,
                'applies_to_json' => null,
            ],
        );

        $review = DecisionOutcome::query()->where('code', 'REQUIRE_HUMAN_REVIEW')->first();

        // Opt-in false-alarm degradation (Roadmap B6-P7): ships INACTIVE. When
        // a tenant activates it, a panic that the provider already resolved
        // AND that comes from a unit parked at its own base degrades to
        // human review instead of an automatic urgent incident. It never
        // degrades below REVIEW, and the hard rule below stays the default.
        if ($review !== null) {
            DecisionRule::query()->updateOrCreate(
                ['ruleset_id' => $ruleSet->id, 'code' => 'panic-false-alarm-review'],
                [
                    'team_id' => $team->id,
                    'name' => 'Panic resuelto + en base → revisión humana',
                    'description' => 'Opt-in: panic externally resolved while parked at base goes to human review instead of an automatic incident. A cancelled panic on the road never degrades (possible coercion).',
                    'scope' => RuleScope::EventType,
                    'priority' => 110,
                    'conditions_json' => [
                        'all' => [
                            ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                            ['field' => 'external_resolved', 'operator' => 'eq', 'value' => true],
                            ['field' => 'parked_at_base', 'operator' => 'eq', 'value' => true],
                        ],
                    ],
                    'outcome_override' => $review->id,
                    'stop_processing' => true,
                    'is_active' => false,
                ],
            );
        }

        DecisionRule::query()->updateOrCreate(
            ['ruleset_id' => $ruleSet->id, 'code' => 'panic-button-always-incident'],
            [
                'team_id' => $team->id,
                'name' => 'Panic Button → Incident',
                'description' => 'Any panic_button event always opens an incident (hard safety rule).',
                'scope' => RuleScope::EventType,
                'priority' => 100,
                'conditions_json' => [
                    'all' => [
                        ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                    ],
                ],
                'outcome_override' => $incident->id,
                'stop_processing' => true,
                'is_active' => true,
            ],
        );

        $this->command?->info("Seeded default ruleset + panic_button→incident rule for team [{$team->slug}].");
    }
}
