<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Drivers\Support\HosTagOptions;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Etiquetas de Samsara del tenant para el selector de la sección HOS, de la
 * caché que comparte con el sondeo. Si Samsara falla, la sección sigue
 * usable (lo ya elegido se conserva) y se marca `failed`.
 */
class ListHosTags
{
    public function __construct(
        private readonly HosProviderCache $providerCache,
    ) {}

    /**
     * @return array{tags: list<array<string, mixed>>, failed: bool, hasIntegration: bool}
     */
    public function execute(int $teamId): array
    {
        return TenantContext::for($teamId, function () use ($teamId): array {
            $input = ['team_id' => $teamId];
            $integrations = $this->providerCache->integrations($teamId);

            if ($integrations->isEmpty()) {
                SystemLog::skipped('hos.tags.listed', reason: 'no_integration', input: $input);

                return ['tags' => [], 'failed' => false, 'hasIntegration' => false];
            }

            $tags = [];

            foreach ($integrations as $integration) {
                try {
                    foreach ($this->providerCache->tags($integration) as $tag) {
                        $tags[$tag['id']] ??= $tag;
                    }
                } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                    SystemLog::degraded('hos.tags.listed', reason: 'provider_error', input: $input + [
                        'integration_id' => $integration->id,
                    ], error: $e);

                    return ['tags' => [], 'failed' => true, 'hasIntegration' => true];
                }
            }

            $options = HosTagOptions::present(array_values($tags));

            SystemLog::ok('hos.tags.listed', input: $input, calc: [
                'integrations_count' => $integrations->count(),
            ], result: ['tags_count' => count($options)], debug: true);

            return ['tags' => $options, 'failed' => false, 'hasIntegration' => true];
        });
    }
}
