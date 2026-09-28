<?php

namespace App\Console\Commands;

use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Replays real Samsara panic-button (AlertIncident) webhook payloads through
 * the full ingestion pipeline for manual end-to-end validation.
 *
 * Events live in database/fixtures/samsara-*.json (regenerate them from the
 * tenant's latest real events with `samsara:export-fixtures`). The `raw_payload`
 * of a webhook fixture is the exact body Samsara POSTs; `kind: safety_event`
 * fixtures are `/safety-events/stream` records and go through IngestSafetyEvent
 * (the polling path) instead. Each is HMAC-signed with the tenant's
 * webhook secret and handed to HandleWebhook, so it exercises the same path as a
 * live webhook: WebhookEvent → ProcessWebhookEventJob (signature) → RawEvent →
 * NormalizedEvent → Context → AI → Decision → Incident.
 *
 * Requires Horizon (or a queue worker) running to process the async chain.
 *
 * Usage: php artisan samsara:replay --team=serviexpress-jc
 *        php artisan samsara:replay --file=samsara-safety-events.json --types=MaxSpeed,MobileUsage --limit=5
 */
class SamsaraReplayCommand extends Command
{
    protected $signature = 'samsara:replay
        {--team=serviexpress-jc : Team slug that owns the Samsara integration}
        {--file=samsara-panic-events.json : Fixture under database/fixtures/ (or an absolute path)}
        {--types= : Comma-separated event types / behavior labels to keep (e.g. AlertIncident,MaxSpeed)}
        {--limit=0 : Replay at most N events (0 = all)}';

    protected $description = 'Replay real Samsara events (panic webhooks or safety-event feed) through the pipeline for testing.';

    public function handle(HandleWebhook $handleWebhook, IngestSafetyEvent $ingestSafetyEvent): int
    {
        $file = (string) $this->option('file');
        $path = str_starts_with($file, '/') ? $file : database_path('fixtures/'.$file);

        if (! is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $events = json_decode((string) file_get_contents($path), true);

        if (! is_array($events)) {
            $this->error('Fixture is not valid JSON.');

            return self::FAILURE;
        }

        $types = array_filter(array_map('trim', explode(',', (string) $this->option('types'))));

        if ($types !== []) {
            $events = array_values(array_filter($events, fn ($event) => in_array($event['event_type'] ?? null, $types, true)));
        }

        if ((int) $this->option('limit') > 0) {
            $events = array_slice($events, 0, (int) $this->option('limit'));
        }

        $team = Team::query()->where('slug', $this->option('team'))->first();

        if (! $team) {
            $this->error("Team [{$this->option('team')}] not found. Seed it first (SamsaraTestSeeder).");

            return self::FAILURE;
        }

        $endpoint = WebhookEndpoint::query()
            ->where('status', 'active')
            ->whereHas('tenantIntegration', function ($q) use ($team) {
                $q->where('team_id', $team->id)
                    ->whereHas('provider', fn ($p) => $p->where('code', 'samsara'));
            })
            ->first();

        if (! $endpoint) {
            $this->error("No active Samsara webhook endpoint for team [{$team->slug}]. Connect the integration in the UI first.");

            return self::FAILURE;
        }

        $this->info('Replaying '.count($events)." event(s) to endpoint {$endpoint->url} (team {$team->slug})...");

        $sent = 0;

        foreach ($events as $i => $event) {
            $body = $event['raw_payload'] ?? null;

            if (! is_array($body)) {
                $this->warn("  [{$i}] skipped: no raw_payload");

                continue;
            }

            if (($event['kind'] ?? 'webhook') === 'safety_event') {
                $ingestSafetyEvent->execute($endpoint->tenantIntegration, $body);
                $this->line('  ['.$i.'] '.($body['id'] ?? '?').'  '.($event['event_type'] ?? 'SafetyEvent'));
                $sent++;

                continue;
            }

            // Sign exactly how Samsara does: HMAC-SHA256 over the signed message
            // "v1:{timestamp}:{rawBody}", delivered via the X-Samsara-Signature
            // ("v1=<hmac>") and X-Samsara-Timestamp headers.
            $rawPayload = (string) json_encode($body);
            $timestamp = (string) now()->getTimestampMs();
            $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

            $handleWebhook->execute(
                $endpoint,
                $body['eventType'] ?? 'unknown',
                $body,
                $rawPayload,
                $signature,
                $timestamp,
            );

            $vehicle = $body['data']['conditions'][0]['details']['panicButton']['vehicle']['name'] ?? '?';
            $this->line("  [{$i}] {$body['eventId']}  {$vehicle}");
            $sent++;
        }

        $this->info("Dispatched {$sent} event(s). Horizon will process the chain; check incidents/normalized_events.");

        return self::SUCCESS;
    }
}
