<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Lo que el tenant lleva gastado en el mes en curso y a cuánto cerraría si
 * no cambia nada: tracto-días acumulados a hoy (de `usage_events`, no del
 * contador diario, para que sea fresco), unidades vigiladas ahora mismo
 * proyectadas a los días que faltan, IA sobre el uso justo y Twilio a costo
 * + margen. Es una estimación: la factura real la cierra
 * GenerateInvoiceSnapshotJob con los mismos términos.
 */
class EstimatePeriodCharges
{
    public function __construct(
        private ResolveBillingTerms $resolveTerms,
        private ResolveAssetLimit $resolveAssetLimit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $teamId, ?CarbonImmutable $today = null): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $today) {
            $today = ($today ?? CarbonImmutable::now())->startOfDay();
            $periodStart = $today->startOfMonth();
            $periodEnd = $today->endOfMonth()->startOfDay();
            $daysInPeriod = (int) $periodStart->diffInDays($periodEnd) + 1;

            $terms = $this->resolveTerms->execute($teamId);
            $cap = $this->resolveAssetLimit->execute($teamId);

            [$assetDays, $daysRecorded] = $this->assetDaysSoFar($teamId, $periodStart, $today);
            $monitoredNow = Asset::query()->where('team_id', $teamId)->monitored()->count();
            $remainingDays = max(0, $daysInPeriod - $daysRecorded);
            $projectedAssetDays = $assetDays + $monitoredNow * $remainingDays;

            $toDate = AssetDayPricing::assetDayLine($terms, $assetDays, $daysInPeriod, $cap);
            $projected = AssetDayPricing::assetDayLine($terms, $projectedAssetDays, $daysInPeriod, $cap);

            $aiCalls = $this->sum($teamId, AssetDayPricing::AI_METER_CODE, $periodStart, $periodEnd);
            $aiToDate = AssetDayPricing::aiLine($terms, $aiCalls, (float) $toDate['average_assets']);
            $aiProjected = AssetDayPricing::aiLine($terms, $aiCalls, (float) $projected['average_assets']);

            $messagingMicros = $this->messagingMicros($teamId, $periodStart, $periodEnd);
            $messaging = AssetDayPricing::messagingLine(
                $terms,
                (float) $messagingMicros,
                $terms->messagingMarkupPercent ?? CostPlusPricing::defaultMarkup(),
                'messaging_cost_micros',
                'Mensajería y llamadas',
            );

            return [
                'periodStart' => $periodStart->toDateString(),
                'periodEnd' => $periodEnd->toDateString(),
                'daysInPeriod' => $daysInPeriod,
                'daysRecorded' => $daysRecorded,
                'currency' => $terms->currency,
                'unitPrice' => $toDate['unit_price'],
                'dailyRate' => $toDate['daily_rate'],
                'monitoredNow' => $monitoredNow,
                'cap' => $cap,
                'overCap' => $cap !== null && $monitoredNow > $cap,
                'assetDays' => $assetDays,
                'assetDaysExtra' => $toDate['overage'],
                'projectedAssetDays' => $projectedAssetDays,
                'assetsToDate' => $toDate['amount'],
                'assetsProjected' => $projected['amount'],
                'aiCalls' => $aiCalls,
                'aiIncluded' => $aiProjected['included'],
                'aiOverage' => $aiProjected['overage'],
                'aiToDate' => $aiToDate['amount'],
                'aiProjected' => $aiProjected['amount'],
                'messagingToDate' => $messaging['amount'],
                'totalToDate' => round($toDate['amount'] + $aiToDate['amount'] + $messaging['amount'], 2),
                'totalProjected' => round($projected['amount'] + $aiProjected['amount'] + $messaging['amount'], 2),
                'minBillableAssets' => $terms->minBillableAssets,
                'aiFairUsePerAsset' => $terms->aiFairUsePerAsset,
                'aiOverageUnitPrice' => $terms->aiOverageUnitPrice,
            ];
        });
    }

    /**
     * @return array{0: int, 1: int} tracto-días acumulados y días con muestra
     */
    private function assetDaysSoFar(int $teamId, CarbonImmutable $periodStart, CarbonImmutable $today): array
    {
        $meterId = UsageMeter::query()->where('code', AssetDayPricing::METER_CODE)->value('id');

        if ($meterId === null) {
            return [0, 0];
        }

        $row = UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('usage_meter_id', $meterId)
            ->where('occurred_at', '>=', $periodStart)
            ->where('occurred_at', '<=', $today->endOfDay())
            ->selectRaw('COALESCE(SUM(quantity), 0) as days, COUNT(*) as samples')
            ->first();

        return [(int) ($row->days ?? 0), (int) ($row->samples ?? 0)];
    }

    private function sum(int $teamId, string $meterCode, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $meterId = UsageMeter::query()->where('code', $meterCode)->value('id');

        if ($meterId === null) {
            return 0;
        }

        return (int) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('usage_meter_id', $meterId)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to->endOfDay())
            ->sum('quantity');
    }

    private function messagingMicros(int $teamId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $meterIds = UsageMeter::query()->where('unit', CostPlusPricing::MICRO_UNIT)->pluck('id');

        if ($meterIds->isEmpty()) {
            return 0;
        }

        return (int) UsageEvent::query()
            ->where('team_id', $teamId)
            ->whereIn('usage_meter_id', $meterIds)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to->endOfDay())
            ->sum('quantity');
    }
}
