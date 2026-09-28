<?php

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Subscription;
use App\Support\TenantContext;

/**
 * Precio cost-plus de los meters que acumulan costo de proveedor en
 * micro-unidades (`usd_micros`, p.ej. Twilio): lo cobrado es
 * costo × (1 + margen / 100). El margen sale de la tarifa del plan vigente
 * del tenant y, si no la hay, del default de plataforma.
 */
final class CostPlusPricing
{
    public const MICRO_UNIT = 'usd_micros';

    public static function providerCost(float $micros): float
    {
        return round($micros / 1_000_000, 6);
    }

    public static function charged(float $micros, float $markupPercent): float
    {
        return round(self::providerCost($micros) * (1 + $markupPercent / 100), 4);
    }

    public static function defaultMarkup(): float
    {
        return (float) config('services.twilio.markup_percent', 30);
    }

    /**
     * Margen aplicable al tenant para un meter cost-plus.
     */
    public static function markupFor(int $teamId, int $usageMeterId): float
    {
        $planId = TenantContext::for($teamId, fn () => Subscription::query()
            ->where('team_id', $teamId)
            ->orderByDesc('id')
            ->value('plan_id'));

        if ($planId === null) {
            return self::defaultMarkup();
        }

        $markup = BillingRate::query()
            ->where('plan_id', $planId)
            ->where('usage_meter_id', $usageMeterId)
            ->where('billing_model', BillingModel::CostPlus)
            ->value('markup_percent');

        return $markup !== null ? (float) $markup : self::defaultMarkup();
    }
}
