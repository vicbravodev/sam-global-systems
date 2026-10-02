<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

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
        $unit = strtoupper($priceUnit ?? 'USD');

        // El medidor es en micro-USD: un precio en otra moneda (cuenta Twilio
        // configurada en MXN, p. ej.) se cobraría mal. Se usa la estimación en
        // USD y queda a la vista.
        if ($unit !== 'USD') {
            SystemLog::degraded('billing.messaging_charge.non_usd_price', reason: 'currency_mismatch', input: ['charge_id' => $charge->id], calc: ['price_unit' => LoggableCode::guard($unit)]);

            $this->withEstimate($charge);

            return;
        }

        $this->finalize($charge, (int) round(abs((float) $price) * 1_000_000), $unit, estimated: false, priceCalc: [
            'price_source' => 'provider',
            'provider_price' => $price,
            'price_unit' => LoggableCode::guard($unit),
            'formula' => 'price_micros = round(abs(provider_price) * 1e6)',
        ]);
    }

    public function withEstimate(MessagingCharge $charge): void
    {
        $estimate = $this->estimateMicros($charge);

        $this->finalize($charge, $estimate['price_micros'], 'USD', estimated: true, priceCalc: ['price_source' => 'estimate', ...$estimate]);
    }

    public function withoutCost(MessagingCharge $charge): void
    {
        $this->finalize($charge, 0, null, estimated: false, priceCalc: ['price_source' => 'free']);
    }

    /**
     * @param  array<string, mixed>  $priceCalc  origen del precio (provider, estimate o free)
     */
    private function finalize(MessagingCharge $charge, int $priceMicros, ?string $priceUnit, bool $estimated, array $priceCalc): void
    {
        $input = [
            'team_id' => $charge->team_id,
            'charge_id' => $charge->id,
            'provider_sid' => LoggableCode::guard($charge->provider_sid),
            'resource_type' => $charge->resource_type->value,
            'channel_type' => $charge->channel_type->value,
        ];

        if ($charge->finalized_at !== null) {
            TenantContext::for($charge->team_id, fn () => SystemLog::skipped('billing.messaging_charge.finalized', reason: 'already_finalized', input: $input));

            return;
        }

        $calc = [...$priceCalc, 'price_micros' => $priceMicros, 'estimated' => $estimated];

        TenantContext::for($charge->team_id, function () use ($charge, $priceMicros, $priceUnit, $estimated, $input, $calc) {
            $charge->fill([
                'price_micros' => $priceMicros,
                'price_unit' => $priceUnit,
                'price_estimated' => $estimated,
                'finalized_at' => now(),
            ])->save();

            if ($priceMicros <= 0) {
                DB::afterCommit(fn () => TenantContext::for($input['team_id'], fn () => SystemLog::ok('billing.messaging_charge.finalized', input: $input, calc: $calc, result: ['metered' => false, 'meter_skipped_reason' => 'zero_cost'])));

                return;
            }

            $eventKey = "twilio_charge:{$charge->provider_sid}";

            $metered = $this->recordUsage->execute(
                teamId: $charge->team_id,
                meterCode: self::METER_CODE,
                quantity: $priceMicros,
                eventKey: $eventKey,
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

                DB::afterCommit(fn () => TenantContext::for($input['team_id'], fn () => SystemLog::ok('billing.messaging_charge.finalized', input: $input, calc: $calc, result: ['metered' => true, 'meter_code' => self::METER_CODE, 'event_key' => $eventKey])));

                return;
            }

            // Queda finalizado sin uso (el reconciliador ya no lo toma); la
            // causa la registra `billing.messaging_usage.not_metered`.
            DB::afterCommit(fn () => TenantContext::for($input['team_id'], fn () => SystemLog::degraded('billing.messaging_charge.finalized', reason: 'not_metered', input: $input, calc: $calc, result: ['metered' => false, 'finalized' => true])));
        });
    }

    /**
     * @return array{price_micros: int, estimate_unit: 'voice_minute'|'whatsapp_message'|'sms_segment', unit_price_usd: float, units: int, formula: string, duration_seconds?: int, segments?: int}
     */
    private function estimateMicros(MessagingCharge $charge): array
    {
        $prices = (array) config('services.twilio.estimated_prices', []);

        if ($charge->resource_type === MessagingResourceType::Call) {
            $durationSeconds = (int) $charge->duration_seconds;
            $unitPrice = (float) ($prices['voice_minute'] ?? 0);
            $units = max(1, (int) ceil($durationSeconds / 60));

            return [
                'price_micros' => (int) round($unitPrice * $units * 1_000_000),
                'estimate_unit' => 'voice_minute',
                'unit_price_usd' => $unitPrice,
                'units' => $units,
                'duration_seconds' => $durationSeconds,
                'formula' => 'units = max(1, ceil(duration_seconds / 60)); price_micros = round(unit_price_usd * units * 1e6)',
            ];
        }

        if ($charge->channel_type === ChannelType::Whatsapp) {
            $unitPrice = (float) ($prices['whatsapp_message'] ?? 0);

            return [
                'price_micros' => (int) round($unitPrice * 1_000_000),
                'estimate_unit' => 'whatsapp_message',
                'unit_price_usd' => $unitPrice,
                'units' => 1,
                'formula' => 'units = 1; price_micros = round(unit_price_usd * units * 1e6)',
            ];
        }

        $segments = (int) $charge->segments;
        $unitPrice = (float) ($prices['sms_segment'] ?? 0);
        $units = max(1, $segments);

        return [
            'price_micros' => (int) round($unitPrice * $units * 1_000_000),
            'estimate_unit' => 'sms_segment',
            'unit_price_usd' => $unitPrice,
            'units' => $units,
            'segments' => $segments,
            'formula' => 'units = max(1, segments); price_micros = round(unit_price_usd * units * 1e6)',
        ];
    }
}
