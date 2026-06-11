<?php

namespace App\Console\Commands;

use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Apply the SAM Default Config Pack (Roadmap V2-A5) to existing tenants.
 * Idempotent: only missing config is created, tenant-modified values are
 * never touched.
 */
class ApplyDefaultTenantConfigCommand extends Command
{
    protected $signature = 'tenants:apply-default-config
        {team? : ID o slug del team}
        {--all : Aplicar a todos los teams no personales}';

    protected $description = 'Aplica la configuración recomendada SAM (settings, reglas de pánico, escalación) a tenants existentes';

    public function handle(ApplyDefaultTenantConfig $applyDefaultConfig): int
    {
        $teams = $this->resolveTeams();

        if ($teams === null) {
            return self::FAILURE;
        }

        foreach ($teams as $team) {
            $summary = $applyDefaultConfig->execute($team);

            $this->info(sprintf(
                '[%s] settings: +%d · reglas: +%d · escalación: %s · snapshot: %s',
                $team->slug ?? $team->id,
                $summary['settings_created'],
                $summary['rules_created'],
                $summary['escalation_created'] ? 'creada' : 'ya existía',
                $summary['snapshot_version'] !== null ? "v{$summary['snapshot_version']}" : 'sin cambios',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Team>|null
     */
    private function resolveTeams(): ?iterable
    {
        if ($this->option('all')) {
            return Team::query()->where('is_personal', false)->get();
        }

        $identifier = $this->argument('team');

        if ($identifier === null) {
            $this->error('Indica un team (ID o slug) o usa --all.');

            return null;
        }

        $team = Team::query()
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->whereKey((int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();

        if ($team === null) {
            $this->error("Team [{$identifier}] no encontrado.");

            return null;
        }

        return [$team];
    }
}
