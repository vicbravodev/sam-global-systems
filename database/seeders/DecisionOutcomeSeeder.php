<?php

namespace Database\Seeders;

use App\Domains\Decisions\Models\DecisionOutcome;
use Illuminate\Database\Seeder;

class DecisionOutcomeSeeder extends Seeder
{
    public function run(): void
    {
        $outcomes = [
            ['code' => 'IGNORE', 'name' => 'Ignore', 'description' => 'Discard the event entirely.', 'is_terminal' => true],
            ['code' => 'LOG_ONLY', 'name' => 'Log Only', 'description' => 'Record the event without further action.', 'is_terminal' => true],
            ['code' => 'ALERT', 'name' => 'Alert', 'description' => 'Surface as a soft alert without creating an incident.', 'is_terminal' => false],
            ['code' => 'INCIDENT', 'name' => 'Create Incident', 'description' => 'Open an incident for operational response.', 'is_terminal' => false],
            ['code' => 'ESCALATE', 'name' => 'Escalate', 'description' => 'Trigger an escalation policy.', 'is_terminal' => false],
            ['code' => 'REQUIRE_HUMAN_REVIEW', 'name' => 'Require Human Review', 'description' => 'Hold for manual review before any further action.', 'is_terminal' => false],
        ];

        foreach ($outcomes as $outcome) {
            DecisionOutcome::updateOrCreate(
                ['code' => $outcome['code']],
                [
                    'name' => $outcome['name'],
                    'description' => $outcome['description'],
                    'is_terminal' => $outcome['is_terminal'],
                ],
            );
        }
    }
}
