<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\BillingTermsData;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Support\TenantContext;

/**
 * Términos efectivos de un tenant: cada campo explícito en
 * `tenant_billing_terms` gana; lo que esté en null cae al default de
 * plataforma (`config/billing.php`). Sin fila, aplica todo el default.
 */
class ResolveBillingTerms
{
    public function execute(int $teamId): BillingTermsData
    {
        return $this->explain($teamId)['terms'];
    }

    /**
     * La misma lectura, con la fuente de cada campo: `tenant` si la columna
     * de la fila no es null; si no, `config` (default de plataforma) o
     * `unset` para los campos sin default (`included_assets`,
     * `messaging_markup_percent`).
     *
     * @return array{terms: BillingTermsData, sources: array<string, 'tenant'|'config'|'unset'>}
     */
    public function explain(int $teamId): array
    {
        $row = TenantContext::for($teamId, fn () => TenantBillingTerms::query()
            ->where('team_id', $teamId)
            ->first());

        $tiers = $row?->volume_tiers_json ?? config('billing.volume_tiers', []);

        $terms = new BillingTermsData(
            unitPrice: $row?->unit_price !== null ? (float) $row->unit_price : (float) config('billing.unit_price'),
            currency: strtolower((string) ($row?->currency ?? config('billing.currency', 'mxn'))),
            includedAssets: $row?->included_assets,
            minBillableAssets: $row?->min_billable_assets ?? (int) config('billing.min_billable_assets', 0),
            aiFairUsePerAsset: $row?->ai_fair_use_per_asset ?? (int) config('billing.ai_fair_use_per_asset', 0),
            aiOverageUnitPrice: $row?->ai_overage_unit_price !== null ? (float) $row->ai_overage_unit_price : (float) config('billing.ai_overage_unit_price', 0),
            messagingMarkupPercent: $row?->messaging_markup_percent !== null ? (float) $row->messaging_markup_percent : null,
            fxUsdRate: $row?->fx_usd_rate !== null ? (float) $row->fx_usd_rate : (float) config('billing.fx_usd_rate', 1),
            volumeTiers: self::normalizeTiers($tiers),
            explicit: $row !== null,
        );

        return [
            'terms' => $terms,
            'sources' => [
                'unit_price' => self::source($row?->unit_price, 'config'),
                'currency' => self::source($row?->currency, 'config'),
                'included_assets' => self::source($row?->included_assets, 'unset'),
                'min_billable_assets' => self::source($row?->min_billable_assets, 'config'),
                'ai_fair_use_per_asset' => self::source($row?->ai_fair_use_per_asset, 'config'),
                'ai_overage_unit_price' => self::source($row?->ai_overage_unit_price, 'config'),
                'messaging_markup_percent' => self::source($row?->messaging_markup_percent, 'unset'),
                'fx_usd_rate' => self::source($row?->fx_usd_rate, 'config'),
                'volume_tiers' => self::source($row?->volume_tiers_json, 'config'),
            ],
        ];
    }

    /**
     * @param  'config'|'unset'  $fallback
     * @return 'tenant'|'config'|'unset'
     */
    private static function source(mixed $value, string $fallback): string
    {
        return $value !== null ? 'tenant' : $fallback;
    }

    /**
     * Los escalones vienen de JSON (fila del tenant o config): se fijan aquí
     * al shape que promete `BillingTermsData`, con los mismos defaults que
     * antes aplicaba el cálculo del precio (`from` 0, `to` abierto). Un
     * escalón sin `unit_price` numérico se descarta: cobrarlo a 0 en silencio
     * sería peor que caer al escalón siguiente o a la tarifa base.
     *
     * @return list<array{from: int, to: int|null, unit_price: float}>
     */
    private static function normalizeTiers(mixed $tiers): array
    {
        if (! is_array($tiers)) {
            return [];
        }

        $normalized = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_numeric($tier['unit_price'] ?? null)) {
                continue;
            }

            $normalized[] = [
                'from' => (int) ($tier['from'] ?? 0),
                'to' => isset($tier['to']) ? (int) $tier['to'] : null,
                'unit_price' => (float) $tier['unit_price'],
            ];
        }

        return $normalized;
    }
}
