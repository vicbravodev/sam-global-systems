<?php

namespace App\Domains\Tenancy\Jobs;

use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\ResolveBillingTerms;
use App\Domains\Tenancy\Data\BillingTermsData;
use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Models\Team;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cierra un periodo en una factura (decisión 2026-09-28, cobro por
 * tracto-día): la línea principal es Σ tracto-días × tarifa diaria según los
 * términos del tenant; después el uso justo de IA, la mensajería Twilio a
 * costo + margen en la moneda del tenant, y las líneas informativas de los
 * demás medidores del plan (incluido vs consumido). El plan ya no aporta
 * precio base: es sólo plantilla de topes.
 */
class GenerateInvoiceSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    /**
     * Medidores que ya tienen su propia línea y no se repiten desde el plan.
     */
    private const array DEDICATED_METERS = [
        AssetDayPricing::METER_CODE,
        AssetDayPricing::AI_METER_CODE,
        ResolveAssetLimit::METER_CODE,
    ];

    public function __construct(
        public int $teamId,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
    ) {
        $this->onQueue('billing');
    }

    public function handle(?ResolveBillingTerms $resolveTerms = null, ?ResolveAssetLimit $resolveAssetLimit = null): void
    {
        $resolveTerms ??= app(ResolveBillingTerms::class);
        $resolveAssetLimit ??= app(ResolveAssetLimit::class);

        TenantContext::for($this->teamId, function () use ($resolveTerms, $resolveAssetLimit) {
            $team = Team::findOrFail($this->teamId);
            $periodStart = CarbonImmutable::parse($this->periodStart ?? now()->startOfMonth()->toDateString())->startOfDay();
            $periodEnd = CarbonImmutable::parse($this->periodEnd ?? now()->endOfMonth()->toDateString())->startOfDay();

            $existingSnapshot = InvoiceSnapshot::query()
                ->where('team_id', $team->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->first();

            if ($existingSnapshot) {
                return;
            }

            $terms = $resolveTerms->execute((int) $team->id);
            $cap = $resolveAssetLimit->execute((int) $team->id);
            $daysInPeriod = (int) $periodStart->diffInDays($periodEnd) + 1;

            $subscription = Subscription::query()
                ->where('team_id', $team->id)
                ->whereIn('status', ['active', 'past_due'])
                ->latest('starts_at')
                ->first();

            $breakdown = [];

            // 1. Tractos vigilados por día: la base de la factura.
            $assetLine = AssetDayPricing::assetDayLine(
                $terms,
                $this->consumed($team, AssetDayPricing::METER_CODE, $periodStart),
                $daysInPeriod,
                $cap,
            );
            $breakdown[] = $assetLine;
            $subtotal = (float) $assetLine['amount'];

            // 2. Uso justo de IA sobre el promedio de tractos vigilados.
            $aiLine = AssetDayPricing::aiLine(
                $terms,
                $this->consumed($team, AssetDayPricing::AI_METER_CODE, $periodStart),
                (float) $assetLine['average_assets'],
            );
            $breakdown[] = $aiLine;
            $overageTotal = (float) $aiLine['amount'];

            // 3. Twilio a costo real + margen, en la moneda del tenant.
            $messagingRate = $subscription
                ? BillingRate::query()
                    ->with('usageMeter')
                    ->where('plan_id', $subscription->plan_id)
                    ->where('billing_model', BillingModel::CostPlus)
                    ->first()
                : null;
            $messagingMeter = $messagingRate?->usageMeter
                ?? UsageMeter::query()->where('unit', CostPlusPricing::MICRO_UNIT)->first();

            if ($messagingMeter !== null) {
                $markup = $terms->messagingMarkupPercent
                    ?? ($messagingRate?->markup_percent !== null ? (float) $messagingRate->markup_percent : CostPlusPricing::defaultMarkup());

                $messagingLine = AssetDayPricing::messagingLine(
                    $terms,
                    (float) $this->consumed($team, (string) $messagingMeter->code, $periodStart),
                    $markup,
                    (string) $messagingMeter->code,
                    (string) $messagingMeter->name,
                );
                $breakdown[] = $messagingLine;
                $overageTotal += (float) $messagingLine['amount'];
            }

            // 4. Líneas informativas de los demás medidores del plan (topes).
            if ($subscription) {
                $billingRates = BillingRate::query()
                    ->with('usageMeter')
                    ->where('plan_id', $subscription->plan_id)
                    ->where('billing_model', '!=', BillingModel::CostPlus)
                    ->get();

                foreach ($billingRates as $rate) {
                    $code = $rate->usageMeter?->code;

                    if ($code === null || in_array($code, self::DEDICATED_METERS, true)) {
                        continue;
                    }

                    $consumed = $this->consumed($team, $code, $periodStart);
                    $included = (int) $rate->included_quantity;
                    $overage = max(0, $consumed - $included);
                    $overageCost = round($overage * (float) $rate->overage_unit_price, 2);
                    $overageTotal += $overageCost;

                    $breakdown[] = [
                        'meter_code' => $code,
                        'meter_name' => $rate->usageMeter->name,
                        'billing_model' => $rate->billing_model->value,
                        'consumed' => $consumed,
                        'included' => $included,
                        'overage' => $overage,
                        'overage_unit_price' => (float) $rate->overage_unit_price,
                        'overage_cost' => $overageCost,
                        'amount' => $overageCost,
                    ];
                }
            }

            $overageTotal = round($overageTotal, 2);

            InvoiceSnapshot::query()->create([
                'team_id' => $team->id,
                'subscription_id' => $subscription?->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'subtotal' => round($subtotal, 2),
                'overage_total' => $overageTotal,
                'total' => round($subtotal + $overageTotal, 2),
                'currency' => $terms->currency,
                'status' => InvoiceStatus::Finalized,
                'breakdown_json' => [
                    ...$breakdown,
                    $this->termsLine($terms),
                ],
                'generated_at' => now(),
            ]);
        });
    }

    /**
     * Consumo del periodo desde el contador ya recalculado por AggregateUsageJob.
     */
    private function consumed(Team $team, string $meterCode, CarbonImmutable $periodStart): int
    {
        $meterId = UsageMeter::query()->where('code', $meterCode)->value('id');

        if ($meterId === null) {
            return 0;
        }

        return (int) (TenantUsageCounter::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meterId)
            ->whereDate('period_start', $periodStart->toDateString())
            ->value('consumed_value') ?? 0);
    }

    /**
     * Los términos vigentes al cerrar quedan en la propia factura: si el
     * precio cambia después, la factura sigue explicándose sola.
     *
     * @return array<string, mixed>
     */
    private function termsLine(BillingTermsData $terms): array
    {
        return [
            'meter_code' => '_terms',
            'meter_name' => 'Términos aplicados',
            'billing_model' => 'terms',
            'consumed' => 0,
            'included' => 0,
            'overage' => 0,
            'overage_unit_price' => 0.0,
            'overage_cost' => 0.0,
            'amount' => 0.0,
            'terms' => $terms->toArray(),
        ];
    }
}
