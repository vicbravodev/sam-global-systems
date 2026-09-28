<?php

namespace App\Domains\Assets\Commands;

use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Operator view of the telematics feeds: per tenant and feed, how far behind
 * the live data is, whether it is paused, and the last error.
 */
class ShowTelematicsStatus extends Command
{
    protected $signature = 'telematics:status {--team= : Only this team id}';

    protected $description = 'Show lag, pauses and errors of every telematics feed';

    public function handle(): int
    {
        // Vista de operador de plataforma: cruza tenants a propósito. Ver §2.1.
        $cursors = TenantContext::withoutTenant(fn () => TelematicsFeedCursor::query()
            ->when($this->option('team'), fn ($query, $team) => $query->where('team_id', (int) $team))
            ->with('integration:id,name')
            ->orderBy('team_id')
            ->orderBy('tenant_integration_id')
            ->orderBy('feed')
            ->get());

        if ($cursors->isEmpty()) {
            $this->info('No telematics feeds yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Team', 'Integration', 'Feed', 'Lag', 'Last poll', 'Last cycle', 'Failures', 'Paused until', 'Last error'],
            $cursors->map(fn (TelematicsFeedCursor $cursor) => [
                $cursor->team_id,
                $cursor->integration?->name ?? $cursor->tenant_integration_id,
                $cursor->feed->value,
                $cursor->lagSeconds() !== null ? $cursor->lagSeconds().' s' : '—',
                $cursor->last_polled_at?->diffForHumans() ?? '—',
                isset($cursor->last_cycle_json['duration_ms'])
                    ? sprintf('%d ms · %d pts', $cursor->last_cycle_json['duration_ms'], ($cursor->last_cycle_json['locations'] ?? 0) + ($cursor->last_cycle_json['readings'] ?? 0))
                    : '—',
                $cursor->consecutive_failures,
                $cursor->isPaused() ? $cursor->paused_until->toDateTimeString() : '—',
                $cursor->last_error !== null ? mb_strimwidth($cursor->last_error, 0, 60, '…') : '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
