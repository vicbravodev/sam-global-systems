<?php

namespace Database\Seeders;

use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Applies the SAM Default Config Pack (Roadmap V2-A5) to the ServiExpress JC
 * test tenant so panic-button events deterministically create incidents. The
 * pack is the single source of truth for the default rules — this seeder no
 * longer duplicates their definitions. Pairs with SamsaraTestSeeder +
 * SamsaraReplayCommand.
 *
 * Run: php artisan db:seed --class=SamsaraTestDecisionRulesSeeder
 */
class SamsaraTestDecisionRulesSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::query()->where('slug', 'serviexpress-jc')->first();

        if (! $team) {
            $this->command?->warn('Team [serviexpress-jc] not found; seed SamsaraTestSeeder first.');

            return;
        }

        $summary = app(ApplyDefaultTenantConfig::class)->execute($team);

        if ($summary['rules_created'] === 0 && $summary['settings_created'] === 0) {
            $this->command?->info("Team [{$team->slug}] already configured; nothing to apply.");

            return;
        }

        $this->command?->info(sprintf(
            'SAM default pack applied to [%s]: +%d settings, +%d rules, escalation %s.',
            $team->slug,
            $summary['settings_created'],
            $summary['rules_created'],
            $summary['escalation_created'] ? 'created' : 'kept',
        ));
    }
}
