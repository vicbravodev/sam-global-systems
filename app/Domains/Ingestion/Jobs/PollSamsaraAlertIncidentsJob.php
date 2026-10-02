<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Orquestador programado del respaldo de pánicos: despacha un
 * {@see PollAlertIncidentsJob} por cada integración Samsara activa de un
 * tenant vivo que tenga al menos una unidad monitorizada (sin unidades
 * monitorizadas no hay nada que vigilar ni que cobrar, y no se gasta cuota
 * de API). Se apaga con `pipeline.alert_incidents_poll.enabled` o por
 * integración con `config_json.sync.poll_alert_incidents = false`.
 *
 * Recorre todos los tenants a propósito (el scheduler no tiene tenant), igual
 * que PollSamsaraSafetyEventsJob.
 */
class PollSamsaraAlertIncidentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        if (! (bool) config('pipeline.alert_incidents_poll.enabled', true)) {
            SystemLog::skipped('ingestion.alert_incidents.dispatched', reason: 'disabled', calc: ['config' => 'pipeline.alert_incidents_poll.enabled']);

            return;
        }

        // Fan-out de plataforma: sólo conteos en el log, nunca ids de tenants.
        $counts = ['integrations' => 0, 'dispatched' => 0, 'disabled' => 0, 'no_monitored_units' => 0];

        // Closure con referencia (no arrow fn): los conteos deben salir del recorrido.
        TenantContext::withoutTenant(function () use (&$counts): void {
            TenantIntegration::query()
                ->where('status', TenantIntegrationStatus::Active)
                ->ofLiveTeam()
                ->whereHas('provider', fn ($query) => $query->where('code', 'samsara'))
                ->with('provider')
                ->each(function (TenantIntegration $integration) use (&$counts): void {
                    $counts['integrations']++;

                    TenantContext::for($integration->team_id, function () use ($integration, &$counts): void {
                        if (! $this->pollEnabled($integration)) {
                            $counts['disabled']++;

                            return;
                        }

                        $hasMonitoredUnits = Asset::query()
                            ->where('team_id', $integration->team_id)
                            ->monitored()
                            ->exists();

                        if (! $hasMonitoredUnits) {
                            $counts['no_monitored_units']++;

                            return;
                        }

                        PollAlertIncidentsJob::dispatch($integration);
                        $counts['dispatched']++;
                    });
                });
        });

        SystemLog::ok('ingestion.alert_incidents.dispatched', result: [
            'integrations_count' => $counts['integrations'],
            'dispatched_count' => $counts['dispatched'],
            'disabled_count' => $counts['disabled'],
            'no_monitored_units_count' => $counts['no_monitored_units'],
        ], debug: $counts['dispatched'] === 0);
    }

    private function pollEnabled(TenantIntegration $integration): bool
    {
        $sync = $integration->config_json['sync'] ?? [];

        if (($sync['enabled'] ?? true) === false) {
            return false;
        }

        return ($sync['poll_alert_incidents'] ?? true) !== false;
    }
}
