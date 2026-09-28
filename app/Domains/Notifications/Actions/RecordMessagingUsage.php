<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Support\Facades\Log;

/**
 * Medición de uso para la capa de entrega que NUNCA rompe un envío ya hecho.
 *
 * `RecordUsageEvent` lanza si el meter no existe; aquí eso ocurre después de
 * que el proveedor ya envió el mensaje, así que una excepción cortaba el
 * bucle de destinatarios y, en jobs con reintentos de cola, provocaba
 * reenvíos cobrados. La falta de un meter se registra como warning (los
 * meters de mensajería también los crea una migración) y el flujo sigue.
 */
class RecordMessagingUsage
{
    public function __construct(
        private readonly RecordUsageEvent $recordUsage,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(int $teamId, string $meterCode, int $quantity, string $eventKey, ?array $metadata = null): bool
    {
        try {
            $this->recordUsage->execute(
                teamId: $teamId,
                meterCode: $meterCode,
                quantity: $quantity,
                eventKey: $eventKey,
                metadata: $metadata,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('Messaging usage could not be metered', [
                'team_id' => $teamId,
                'meter_code' => $meterCode,
                'event_key' => $eventKey,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
