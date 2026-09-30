<?php

namespace App\Domains\Tenancy\Data;

/**
 * Términos efectivos de facturación de un tenant: lo explícito de
 * `tenant_billing_terms` sobre los defaults de `config/billing.php`.
 */
final readonly class BillingTermsData
{
    /**
     * @param  list<array{from: int, to: int|null, unit_price: float}>  $volumeTiers
     */
    public function __construct(
        public float $unitPrice,
        public string $currency,
        public ?int $includedAssets,
        public int $minBillableAssets,
        public int $aiFairUsePerAsset,
        public float $aiOverageUnitPrice,
        public ?float $messagingMarkupPercent,
        public float $fxUsdRate,
        public array $volumeTiers,
        public bool $explicit,
    ) {}

    /**
     * Precio mensual por tracto para un promedio de tractos vigilados: el
     * escalón que lo contiene, o el precio plano si no hay escalones.
     */
    public function unitPriceFor(float $averageAssets): float
    {
        return $this->explainUnitPriceFor($averageAssets)['unit_price'];
    }

    /**
     * Mismo bucle que decide el precio, con el escalón que lo decidió (para
     * el log narrativo de la factura y la estimación).
     *
     * @return array{unit_price: float, source: 'volume_tier'|'flat_unit_price', tier_assets: int, tier_index: ?int, tier_from: ?int, tier_to: ?int, tiers_count: int}
     */
    public function explainUnitPriceFor(float $averageAssets): array
    {
        $assets = (int) ceil(max(0, $averageAssets));

        foreach ($this->volumeTiers as $index => $tier) {
            $from = (int) ($tier['from'] ?? 0);
            $to = $tier['to'] ?? null;

            if ($assets >= $from && ($to === null || $assets <= (int) $to)) {
                return [
                    'unit_price' => (float) $tier['unit_price'],
                    'source' => 'volume_tier',
                    'tier_assets' => $assets,
                    'tier_index' => $index,
                    'tier_from' => $from,
                    'tier_to' => $to === null ? null : (int) $to,
                    'tiers_count' => count($this->volumeTiers),
                ];
            }
        }

        return [
            'unit_price' => $this->unitPrice,
            'source' => 'flat_unit_price',
            'tier_assets' => $assets,
            'tier_index' => null,
            'tier_from' => null,
            'tier_to' => null,
            'tiers_count' => count($this->volumeTiers),
        ];
    }

    /**
     * Convierte un importe en USD (costo real de Twilio) a la moneda del tenant.
     */
    public function fromUsd(float $usd): float
    {
        if ($this->currency === 'usd') {
            return round($usd, 4);
        }

        return round($usd * $this->fxUsdRate, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'currency' => $this->currency,
            'included_assets' => $this->includedAssets,
            'min_billable_assets' => $this->minBillableAssets,
            'ai_fair_use_per_asset' => $this->aiFairUsePerAsset,
            'ai_overage_unit_price' => $this->aiOverageUnitPrice,
            'messaging_markup_percent' => $this->messagingMarkupPercent,
            'fx_usd_rate' => $this->fxUsdRate,
            'volume_tiers' => $this->volumeTiers,
            'explicit' => $this->explicit,
        ];
    }
}
