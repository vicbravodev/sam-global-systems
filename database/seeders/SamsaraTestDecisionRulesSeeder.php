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
