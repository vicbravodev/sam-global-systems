<?php

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Data\BillingTermsData;
use Carbon\CarbonImmutable;

/**
 * Cobro por tracto-día (decisión 2026-09-28): cada noche se cuenta cuántas
 * unidades estuvieron vigiladas; el mes se cobra como
 * Σ tracto-días × (precio mensual ÷ días del periodo). Altas y bajas a mitad
 * de mes se prorratean solas. El tope contratado es SUAVE: lo que pasa del
 * tope se cobra igual, sólo se desglosa como extra.
 */
final class AssetDayPricing
{
    public const string METER_CODE = 'monitored_asset_days';

    public const string AI_METER_CODE = 'ai_calls';

    public const string UNMONITORED_EMERGENCY_METER_CODE = 'unmonitored_emergency_asset_days';

    /**
     * Fecha local (zona de facturación) de un instante: el "día" que se cobra
     * es el día del cliente, no el día UTC.
     */
    public static function localDate(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)
            ->setTimezone(self::timezone())
            ->toDateString();
    }

    /**
     * Mediodía local de una fecha facturable, como instante: un uso fechado
     * ahí cae en el mismo día y mes tanto en hora local como en UTC, así que
     * los agregados por `DATE(occurred_at)` y por mes no se corren de día.
     */
    public static function localNoon(string $localDate): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate.' 12:00:00', self::timezone());
    }

    public static function timezone(): string
    {
        return (string) config('billing.timezone', 'America/Mexico_City');
    }

    public static function unmonitoredEmergencySurchargePercent(): float
    {
        return max(0.0, (float) config('billing.unmonitored_emergency_surcharge_percent', 10));
    }

    /**
     * Emergencias atendidas en unidades no vigiladas: cada unidad-día se cobra
     * a la tarifa diaria del tracto más el recargo (decisión 2026-09-28).
     *
     * @return array<string, mixed>
     */
    public static function unmonitoredEmergencyLine(int $assetDays, float $dailyRate): array
    {
        $surcharge = self::unmonitoredEmergencySurchargePercent();
        $unitPrice = round($dailyRate * (1 + $surcharge / 100), 6);

        return [
            'meter_code' => self::UNMONITORED_EMERGENCY_METER_CODE,
            'meter_name' => 'Emergencias en unidades no vigiladas (por día)',
            'billing_model' => 'asset_day_surcharge',
            'consumed' => $assetDays,
            'included' => 0,
            'overage' => $assetDays,
            'daily_rate' => round($dailyRate, 6),
            'surcharge_percent' => $surcharge,
            'overage_unit_price' => $unitPrice,
            'overage_cost' => round($assetDays * $unitPrice, 2),
            'amount' => round($assetDays * $unitPrice, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assetDayLine(BillingTermsData $terms, int $assetDays, int $daysInPeriod, ?int $cap): array
    {
        $daysInPeriod = max(1, $daysInPeriod);
        $averageAssets = $assetDays / $daysInPeriod;
        $unitPrice = $terms->unitPriceFor($averageAssets);
        $dailyRate = $unitPrice / $daysInPeriod;

        $minimumDays = $terms->minBillableAssets * $daysInPeriod;
        $billableDays = max($assetDays, $minimumDays);

        $includedDays = $cap === null ? $assetDays : min($assetDays, $cap * $daysInPeriod);
        $extraDays = $assetDays - $includedDays;

        return [
            'meter_code' => self::METER_CODE,
            'meter_name' => 'Tractos vigilados (por día)',
            'billing_model' => 'asset_day',
            'consumed' => $assetDays,
            'included' => $includedDays,
            'overage' => $extraDays,
            'days_in_period' => $daysInPeriod,
            'average_assets' => round($averageAssets, 2),
            'cap' => $cap,
            'minimum_billable_days' => $minimumDays,
            'billable_days' => $billableDays,
            'unit_price' => $unitPrice,
            'daily_rate' => round($dailyRate, 6),
            'overage_unit_price' => round($dailyRate, 6),
            'included_cost' => round(min($billableDays, $includedDays) * $dailyRate, 2),
            'overage_cost' => round($extraDays * $dailyRate, 2),
            'amount' => round($billableDays * $dailyRate, 2),
        ];
    }

    /**
     * Uso justo de IA: evaluaciones incluidas = fair use × promedio de
     * tractos vigilados (agrupadas por tenant); el excedente se cobra por
     * evaluación.
     *
     * @return array<string, mixed>
     */
    public static function aiLine(BillingTermsData $terms, int $aiCalls, float $averageAssets): array
    {
        $included = (int) floor($terms->aiFairUsePerAsset * $averageAssets);
        $overage = max(0, $aiCalls - $included);

        return [
            'meter_code' => self::AI_METER_CODE,
            'meter_name' => 'Evaluaciones de IA',
            'billing_model' => 'fair_use',
            'consumed' => $aiCalls,
            'included' => $included,
            'overage' => $overage,
            'fair_use_per_asset' => $terms->aiFairUsePerAsset,
            'average_assets' => round($averageAssets, 2),
            'overage_unit_price' => $terms->aiOverageUnitPrice,
            'overage_cost' => round($overage * $terms->aiOverageUnitPrice, 2),
            'amount' => round($overage * $terms->aiOverageUnitPrice, 2),
        ];
    }

    /**
     * Costo real de Twilio (micro-USD) con margen, trasladado a la moneda del tenant.
     *
     * @return array<string, mixed>
     */
    public static function messagingLine(BillingTermsData $terms, float $micros, float $markupPercent, string $meterCode, string $meterName): array
    {
        $chargedUsd = CostPlusPricing::charged($micros, $markupPercent);
        $charged = $terms->fromUsd($chargedUsd);

        return [
            'meter_code' => $meterCode,
            'meter_name' => $meterName,
            'billing_model' => 'cost_plus',
            'consumed' => $micros,
            'included' => 0,
            'overage' => $micros,
            'overage_unit_price' => 0.0,
            'provider_cost' => CostPlusPricing::providerCost($micros),
            'markup_percent' => $markupPercent,
            'charged_usd' => $chargedUsd,
            'fx_usd_rate' => $terms->currency === 'usd' ? 1.0 : $terms->fxUsdRate,
            'overage_cost' => round($charged, 2),
            'amount' => round($charged, 2),
        ];
    }
}
