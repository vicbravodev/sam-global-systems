<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * "13 tractos · 43 choferes entran ahora": las MISMAS reglas del sondeo
 * ({@see ResolveHosEnrollment}) con la selección en borrador, sobre la última
 * lectura de relojes del sondeo y los tags cacheados. Cuenta tractos y
 * choferes distintos y por qué quedan fuera los demás.
 */
class PreviewHosEnrollment
{
    public function __construct(
        private readonly HosProviderCache $providerCache,
        private readonly ResolveHosEnrollment $resolveEnrollment,
    ) {}

    /**
     * @return array{trucks: int, drivers: int, skipped: array<string, int>, failed: bool, hasIntegration: bool}
     */
    public function execute(int $teamId, HosMonitoringConfig $draft): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $draft): array {
            $input = ['team_id' => $teamId];
            $empty = ['trucks' => 0, 'drivers' => 0, 'skipped' => []];
            $integrations = $this->providerCache->integrations($teamId);

            if ($integrations->isEmpty()) {
                SystemLog::skipped('hos.preview.computed', reason: 'no_integration', input: $input);

                return $empty + ['failed' => false, 'hasIntegration' => false];
            }

            $assets = [];
            $drivers = [];
            $skipped = [];
            $source = 'cache';

            foreach ($integrations as $integration) {
                try {
                    [$readings, $readFrom] = $this->providerCache->readings($integration);
                    $tags = $draft->tagIds === [] ? [] : $this->providerCache->previewTags($integration);
                } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                    SystemLog::degraded('hos.preview.computed', reason: 'provider_error', input: $input + [
                        'integration_id' => $integration->id,
                    ], error: $e);

                    return $empty + ['failed' => true, 'hasIntegration' => true];
                }

                $source = $readFrom === 'provider' ? 'provider' : $source;
                $enrollment = $this->resolveEnrollment->execute($integration, $draft, $readings, $tags);

                foreach ($enrollment->enrolled as $row) {
                    $assets[$row['asset']->id] = true;
                    $drivers[$row['driver']->id] = true;
                }

                foreach ($enrollment->skippedByReason as $reason => $count) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + $count;
                }
            }

            $result = ['trucks' => count($assets), 'drivers' => count($drivers)];
            $skippedLog = [];

            foreach ($skipped as $reason => $count) {
                $skippedLog["skipped_{$reason}"] = $count;
            }

            SystemLog::ok('hos.preview.computed', input: $input, calc: [
                'source' => $source,
                'tag_ids_count' => count($draft->tagIds),
                'included_count' => count($draft->includedAssetIds),
                'excluded_count' => count($draft->excludedAssetIds),
            ], result: $result + $skippedLog, debug: true);

            return $result + ['skipped' => $skipped, 'failed' => false, 'hasIntegration' => true];
        });
    }
}
