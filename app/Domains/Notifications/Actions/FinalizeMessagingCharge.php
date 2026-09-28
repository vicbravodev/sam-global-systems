<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Support\TenantContext;

/**
 * Cierra el costo de un recurso Twilio terminal y lo mide en
 * `messaging_cost_micros` (micro-USD), de donde sale la línea cost-plus de
 * la factura. Idempotente: `event_key = twilio_charge:{sid}` y `metered_at`.
 *
 * - `real`: precio que reporta Twilio (viene negativo, p.ej. "-0.00790").
 * - `estimated`: Twilio no reportó precio en 24 h → tabla de
 *   `services.twilio.estimated_prices`, marcado `price_estimated`.
 * - `free`: estados que Twilio no cobra (fallo antes de salir, llamada no
 *   contestada): se cierra con 0 y sin uso.
 */
class FinalizeMessagingCharge
{
    public const METER_CODE = 'messaging_cost_micros';

    public function __construct(
        private readonly RecordMessagingUsage $recordUsage,
    ) {}

    public function withProviderPrice(MessagingCharge $charge, string $price, ?string $priceUnit): void
    {
        $this->finalize($charge, (int) round(abs((float) $price) * 1_000_000), $priceUnit ?? 'USD', estimated: false);
    }

    public function withEstimate(MessagingCharge $charge): void
    {
        $this->finalize($charge, $this->estimateMicros($charge), 'USD', estimated: true);
    }

    public function withoutCost(MessagingCharge $charge): void
    {
        $this->finalize($charge, 0, null, estimated: false);
    }

    private function finalize(MessagingCharge $charge, int $priceMicros, ?string $priceUnit, bool $estimated): void
    {
        if ($charge->finalized_at !== null) {
            return;
        }

        TenantContext::for($charge->team_id, function () use ($charge, $priceMicros, $priceUnit, $estimated) {
            $charge->fill([
                'price_micros' => $priceMicros,
                'price_unit' => $priceUnit,
                'price_estimated' => $estimated,
                'finalized_at' => now(),
            ])->save();

            if ($priceMicros <= 0) {
                return;
            }

            $metered = $this->recordUsage->execute(
                teamId: (int) $charge->team_id,
                meterCode: self::METER_CODE,
                quantity: $priceMicros,
                eventKey: "twilio_charge:{$charge->provider_sid}",
                metadata: [
                    'provider_sid' => $charge->provider_sid,
                    'source_type' => $charge->source_type->value,
                    'channel_type' => $charge->channel_type->value,
                    'price_unit' => $priceUnit,
                    'estimated' => $estimated,
                ],
            );

            if ($metered) {
                $charge->forceFill(['metered_at' => now()])->save();
            }
        });
    }

    private function estimateMicros(MessagingCharge $charge): int
    {
        $prices = (array) config('services.twilio.estimated_prices', []);

        $usd = match (true) {
            $charge->resource_type === MessagingResourceType::Call => (float) ($prices['voice_minute'] ?? 0)
                * max(1, (int) ceil(((int) $charge->duration_seconds) / 60)),
            $charge->channel_type === ChannelType::Whatsapp => (float) ($prices['whatsapp_message'] ?? 0),
            default => (float) ($prices['sms_segment'] ?? 0) * max(1, (int) $charge->segments),
        };

        return (int) round($usd * 1_000_000);
    }
}
