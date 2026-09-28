<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Registra el recurso Twilio recién aceptado para que el callback de estado
 * y el reconciliador puedan resolverlo por SID y cobrar su costo real.
 *
 * Idempotente por SID (índice único). Nunca lanza: el envío ya ocurrió y un
 * problema de registro no debe romper el bucle de destinatarios — queda en
 * el log para investigarlo.
 */
class RecordMessagingCharge
{
    public const FIRST_CHECK_DELAY_MINUTES = 2;

    public function execute(
        int $teamId,
        string $providerSid,
        MessagingResourceType $resourceType,
        MessagingChargeSource $sourceType,
        ?int $sourceId,
        ChannelType $channelType,
        ?string $status = null,
        ?int $segments = null,
    ): ?MessagingCharge {
        if ($providerSid === '') {
            return null;
        }

        try {
            return TenantContext::for($teamId, function () use ($teamId, $providerSid, $resourceType, $sourceType, $sourceId, $channelType, $status, $segments) {
                return MessagingCharge::query()->firstOrCreate(
                    ['provider_sid' => $providerSid],
                    [
                        'team_id' => $teamId,
                        'provider' => 'twilio',
                        'resource_type' => $resourceType,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'channel_type' => $channelType,
                        'status' => $status,
                        'segments' => $segments,
                        'events_json' => $status !== null
                            ? [['status' => $status, 'error_code' => null, 'at' => now()->toIso8601String(), 'source' => 'api']]
                            : [],
                        'next_check_at' => now()->addMinutes(self::FIRST_CHECK_DELAY_MINUTES),
                    ],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to record messaging charge', [
                'team_id' => $teamId,
                'provider_sid' => $providerSid,
                'source_type' => $sourceType->value,
                'source_id' => $sourceId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
