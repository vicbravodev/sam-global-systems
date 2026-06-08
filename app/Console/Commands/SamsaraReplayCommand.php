<?php

namespace App\Console\Commands;

use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Replays real Samsara panic-button (AlertIncident) webhook payloads through
 * the full ingestion pipeline for manual end-to-end validation.
 *
 * Events live in database/fixtures/samsara-panic-events.json — the `raw_payload`
 * of each is the exact body Samsara POSTs. Each is HMAC-signed with the tenant's
 * webhook secret and handed to HandleWebhook, so it exercises the same path as a
 * live webhook: WebhookEvent → ProcessWebhookEventJob (signature) → RawEvent →
 * NormalizedEvent → Context → AI → Decision → Incident.
 *
 * Requires Horizon (or a queue worker) running to process the async chain.
 *
 * Usage: php artisan samsara:replay --team=samsara-test
 */
class SamsaraReplayCommand extends Command
{
    protected $signature = 'samsara:replay {--team=samsara-test : Team slug that owns the Samsara integration}';

    protected $description = 'Replay real Samsara panic-button events through the webhook pipeline for testing.';

    public function handle(HandleWebhook $handleWebhook): int
    {
        $path = database_path('fixtures/samsara-panic-events.json');

        if (! is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $events = json_decode((string) file_get_contents($path), true);

        if (! is_array($events)) {
            $this->error('Fixture is not valid JSON.');

            return self::FAILURE;
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
