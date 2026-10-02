<?php

use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Jobs\CalculateDailyKPIsJob;
use App\Domains\Analytics\Jobs\ExpireOldReportsJob;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Jobs\DetectUnauthorizedStopJob;
use App\Domains\Assets\Jobs\DispatchTelematicsFeedsJob;
use App\Domains\Assets\Jobs\PollAllDeviceConnectivityJob;
use App\Domains\Assets\Jobs\PurgeOldAssetLocationsJob;
use App\Domains\Assets\Jobs\PurgeOldAssetTelemetryJob;
use App\Domains\Automation\Jobs\ExpireUnconfirmedActionsJob;
use App\Domains\Drivers\Jobs\RecalculateDriverRiskProfilesJob;
use App\Domains\Incidents\Jobs\SweepOverdueEscalationsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PruneDeduplicationKeysJob;
use App\Domains\Ingestion\Jobs\PurgeOldFailedJobsJob;
use App\Domains\Ingestion\Jobs\ReprocessStuckRawEventsJob;
use App\Domains\Integrations\Jobs\CheckIntegrationHealthJob;
use App\Domains\Integrations\Jobs\PurgeOldIntegrationSyncJobsJob;
use App\Domains\Integrations\Jobs\PurgeOldWebhookEventsJob;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Notifications\Jobs\PurgeExpiredReplyTokensJob;
use App\Domains\Notifications\Jobs\ReconcileMessagingChargesJob;
use App\Domains\Notifications\Jobs\SweepStuckDeliveriesJob;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Jobs\GenerateMonthlyInvoicesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();

Schedule::job(new AggregateUsageJob)->dailyAt('02:00')->onOneServer();

// Monthly invoicing: on the 1st, close the previous month's usage counters
// from usage_events and generate each operational tenant's draft invoice.
Schedule::job(new GenerateMonthlyInvoicesJob)->monthlyOn(1, '05:00')->onOneServer();

// Twilio feedback safety net + real provider cost (cost-plus billing): polls
// non-finalized messages/calls whose status callback never landed and meters
// their price into messaging_cost_micros once Twilio reports it.
Schedule::job(new ReconcileMessagingChargesJob)->everyFiveMinutes()->onOneServer();
Schedule::job(new CalculateDailyKPIsJob)->dailyAt('03:00')->onOneServer();

// Deduplication keys expire after 24h but nothing removed the rows: the table
// grew unbounded with every ingested event. Daily purge keeps it bounded.
Schedule::job(new PruneDeduplicationKeysJob)->dailyAt('03:15')->onOneServer();

// ExpireOldReports (spec 15) is a per-tenant Action, not a console command:
// this job fans it out across every team with an active/past-due
// subscription, same pattern as CalculateDailyKPIsJob/BuildAnalyticsSnapshotJob.
// Without this, report retention policy was written but never enforced —
// expired report files never got deleted from storage.
Schedule::job(new ExpireOldReportsJob)->dailyAt('03:30')->onOneServer();

Schedule::job(new BuildAnalyticsSnapshotJob)->dailyAt('04:00')->onOneServer();

// Daily driver risk recalculation (Roadmap V2-D1): aggregates safety events
// into DriverRiskProfile and raises preventive deterioration alerts.
Schedule::job(new RecalculateDriverRiskProfilesJob)->dailyAt('04:30')->onOneServer();

// Background syncing of every active integration. The orchestrators fan out
// per-tenant work and self-gate by interval (configurable per integration via
// config_json.sync), so these ticks are the floor cadence, not the exact rate.
Schedule::job(new SyncDueIntegrationsJob)->everyFifteenMinutes()->onOneServer();
Schedule::job(new PollSamsaraSafetyEventsJob)->everyTwoMinutes()->onOneServer();

// Red de seguridad del pipeline: re-despacha raw events fallidos o atascados
// (emergencias primero) con tope de rescates por evento; al agotarlo alerta a
// super-admins (y al tenant si es emergencia). Umbrales en config/pipeline.php.
Schedule::job(new ReprocessStuckRawEventsJob)->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Live fleet state: positions and diagnostics follow the provider's stats
// feed with a cursor per tenant. This tick only decides which feeds are due
// and queues one job per tenant on `telematics`; the cadence itself
// (TELEMATICS_FEED_INTERVAL, 5 s floor) is enforced per feed. It runs inline
// in the scheduler (a few ms): queueing it too would put it in line behind
// the very cycles it dispatches and stretch a 5 s cadence to 7–10 s.
// Sub-minute tasks keep schedule:run alive for the whole minute;
// `schedule:interrupt` stops it cleanly on deploy.
Schedule::call(fn () => app()->call([new DispatchTelematicsFeedsJob, 'handle']))
    ->name('telematics:dispatch-feeds')
    ->everyFiveSeconds()
    ->onOneServer();

// Gateway heartbeat for the offline watchdog, on the watchdog's own cadence.
Schedule::job(new PollAllDeviceConnectivityJob)->everyFiveMinutes()->onOneServer();

Schedule::job(new PurgeOldAssetTelemetryJob)->dailyAt('03:45')->onOneServer();
Schedule::job(new PurgeOldAssetLocationsJob)->dailyAt('03:50')->onOneServer();

// Retención de datos operativos transitorios (config `pipeline.retention`):
// webhooks ya resueltos, corridas de sync terminadas, tokens de respuesta
// vencidos y jobs fallidos. Recorridos de plataforma en lotes. Lo facturable
// y lo de auditoría no se purga aquí.
Schedule::job(new PurgeOldWebhookEventsJob)->dailyAt('04:10')->onOneServer();
Schedule::job(new PurgeOldIntegrationSyncJobsJob)->dailyAt('04:15')->onOneServer();
Schedule::job(new PurgeExpiredReplyTokensJob)->dailyAt('04:20')->onOneServer();
Schedule::job(new PurgeOldFailedJobsJob)->dailyAt('04:25')->onOneServer();

// Offline-asset watchdog (Roadmap V2-C1): silence beyond the tenant/asset
// threshold raises an internal `device_offline` event through the pipeline.
Schedule::job(new DetectOfflineAssetsJob)->everyFiveMinutes()->onOneServer();

// Salud de la integración: integración en error o callada → aviso al admin.
Schedule::job(new CheckIntegrationHealthJob)->everyFiveMinutes()->onOneServer();

// After-hours movement (Roadmap V2-C2) is no longer swept: the telematics feed
// evaluates it inline on every fresh moving point (RaiseAfterHoursMovement).

// Unauthorized-stop detector (Roadmap V2-C3): a prolonged stop outside every
// known geofence raises an internal `suspicious_stop` event. Reads the motion
// state the feed keeps on each asset, so a minute tick is one indexed query
// per tenant.
Schedule::job(new DetectUnauthorizedStopJob)->everyMinute()->onOneServer();

// Caducidad de confirmaciones (spec 12 §13): las acciones `requires_confirmation`
// que nadie confirmó dentro del TTL del tenant (30 min por defecto) pasan a
// `cancelled`. Cada minuto, para que el plazo se cumpla con ±1 min; el
// endpoint de confirmar ya rechaza las vencidas aunque el barrido no haya pasado.
Schedule::job(new ExpireUnconfirmedActionsJob)->everyMinute()->onOneServer();

// Red de seguridad de la escalera de SLA: re-despacha los pasos vencidos cuyo
// job diferido se perdió o falló (el estado vive en la fila del incidente).
Schedule::job(new SweepOverdueEscalationsJob)->everyMinute()->withoutOverlapping()->onOneServer();

// Entregas atascadas "enviando" sin SID (worker caído a medio envío, timeout
// sin resolver): se adoptan si Twilio sí las creó o fallan para reintento.
Schedule::job(new SweepStuckDeliveriesJob)->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Telescope (sólo local, dependencia de desarrollo): poda diaria de entradas
// de más de 48 h para que la tabla no crezca sin límite.
if (app()->environment('local') && class_exists(Telescope::class)) {
    Schedule::command('telescope:prune --hours=48')->dailyAt('04:45')->onOneServer();
}
