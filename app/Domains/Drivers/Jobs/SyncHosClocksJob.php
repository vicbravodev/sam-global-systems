<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Actions\ResolveHosEnrollment;
use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * One HOS poll of one Samsara integration: clocks (+ tags when the tenant
 * enrolls by tag) → enrollment → state and episodes. A failed provider read
 * discards the WHOLE cycle — a partial listing would read as drivers leaving
 * the set and close their episodes. No retries: the next minute polls again.
 */
class SyncHosClocksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    /** Releases the lock if a worker dies mid-poll. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('telematics');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveHosMonitoringConfig $resolveConfig,
        ResolveHosEnrollment $resolveEnrollment,
        ProcessHosReadings $processReadings,
    ): void {
        $teamId = $this->integration->team_id;
        $input = ['team_id' => $teamId, 'integration_id' => $this->integration->id];

        TenantContext::for($teamId, function () use ($providerAdapter, $resolveConfig, $resolveEnrollment, $processReadings, $teamId, $input): void {
            $config = $resolveConfig->execute($teamId);

            if ($config === null) {
                SystemLog::skipped('hos.poll.skipped', reason: 'feature_disabled', input: $input);

                return;
            }

            try {
                $readings = $providerAdapter->fetchHosClocks($this->integration);
                $tags = $config->tagIds === [] ? [] : Cache::remember(
                    "hos:tags:{$teamId}:{$this->integration->id}",
                    (int) config('hos.tags_cache_seconds', 300),
                    fn () => $providerAdapter->fetchTags($this->integration),
                );
            } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                SystemLog::degraded('hos.poll.failed', reason: match (true) {
                    $e instanceof ProviderUnauthorized => 'unauthorized',
                    $e instanceof ProviderRateLimited => 'rate_limited',
                    default => 'provider_error',
                }, input: $input, error: $e);

                return;
            }

            $enrollment = $resolveEnrollment->execute($this->integration, $config, $readings, $tags);
            $counts = $processReadings->execute($teamId, $config, $enrollment, now()->toImmutable());

            $skipped = [];

            foreach ($enrollment->skippedByReason as $reason => $count) {
                $skipped["skipped_{$reason}"] = $count;
            }

            SystemLog::ok('hos.poll.completed', input: $input, calc: [
                'readings_count' => count($readings),
                'tags_count' => count($tags),
                'tag_ids_count' => count($config->tagIds),
                'included_count' => count($config->includedAssetIds),
                'excluded_count' => count($config->excludedAssetIds),
            ], result: $counts + $skipped);
        });
    }

    public function uniqueId(): string
    {
        return "hos-clocks-{$this->integration->id}";
    }
}
