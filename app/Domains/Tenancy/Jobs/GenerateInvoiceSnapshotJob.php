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
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    /**
     * Medidores que ya tienen su propia línea y no se repiten desde el plan.
     */
    private const array DEDICATED_METERS = [
        AssetDayPricing::METER_CODE,
        AssetDayPricing::AI_METER_CODE,
        AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE,
        ResolveAssetLimit::METER_CODE,
    ];

    private const int LOCK_SECONDS = 120;

    private const int LOCK_WAIT_SECONDS = 30;

    /**
     * Fórmulas del log narrativo (`billing.asset_day.calculated`,
     * `billing.invoice_line.calculated`, `billing.invoice.generated`): dicen
     * exactamente cómo `AssetDayPricing` y este job calculan cada importe.
     */
    private const string ASSET_DAY_FORMULA = 'amount = round(billable_days * (unit_price / days_in_period), 2); billable_days = max(consumed, min_billable_assets * days_in_period); included = cap === null ? consumed : min(consumed, cap * days_in_period); overage = consumed - included';

    private const string SURCHARGE_FORMULA = 'overage_unit_price = round(daily_rate * (1 + surcharge_percent / 100), 6); amount = round(consumed * overage_unit_price, 2)';

    private const string FAIR_USE_FORMULA = 'included = floor(fair_use_per_asset * average_assets); overage = max(0, consumed - included); amount = round(overage * overage_unit_price, 2)';

    private const string COST_PLUS_FORMULA = 'provider_cost = round(consumed / 1e6, 6); charged_usd = round(provider_cost * (1 + markup_percent / 100), 4); charged = currency == usd ? round(charged_usd, 4) : round(charged_usd * fx_usd_rate, 4); amount = round(charged, 2)';

    private const string PLAN_METER_FORMULA = 'overage = max(0, consumed - included); amount = round(overage * overage_unit_price, 2)';

    private const string INVOICE_FORMULA = 'subtotal = round(Σ subtotal_terms, 2); overage_total = round(Σ overage_terms, 2); total = round(Σ subtotal_terms + overage_total, 2)';

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

        $periodStart = CarbonImmutable::parse($this->periodStart ?? now()->startOfMonth()->toDateString())->startOfDay();
        $periodEnd = CarbonImmutable::parse($this->periodEnd ?? now()->endOfMonth()->toDateString())->startOfDay();

        // The admin trigger and the scheduled run (or a double click) can
        // generate the same period concurrently: the check-then-create below
        // runs under a per-tenant, per-period lock (the unique index on
        // (team_id, period_start, period_end) is the final guard).
        $lockKey = sprintf('billing:invoice-snapshot:%d:%s:%s', $this->teamId, $periodStart->toDateString(), $periodEnd->toDateString());

        try {
            Cache::lock($lockKey, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn () => $this->generate($resolveTerms, $resolveAssetLimit, $periodStart, $periodEnd));
        } catch (LockTimeoutException $e) {
            SystemLog::degraded(
                'billing.invoice.lock_timeout',
                reason: 'lock_wait_exceeded',
                input: $this->logInput($periodStart, $periodEnd),
                calc: ['lock_seconds' => self::LOCK_SECONDS, 'wait_seconds' => self::LOCK_WAIT_SECONDS],
            );

            throw $e;
        }
    }

    private function generate(ResolveBillingTerms $resolveTerms, ResolveAssetLimit $resolveAssetLimit, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): void
    {
        TenantContext::for($this->teamId, function () use ($resolveTerms, $resolveAssetLimit, $periodStart, $periodEnd) {
            $team = Team::findOrFail($this->teamId);
            $logInput = $this->logInput($periodStart, $periodEnd);

            $existingSnapshot = InvoiceSnapshot::query()
                ->where('team_id', $team->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->exists();

            if ($existingSnapshot) {
                SystemLog::skipped('billing.invoice.already_exists', reason: 'period_already_invoiced', input: [...$logInput, 'stage' => 'invoice']);

                return;
            }

            $explained = $resolveTerms->explain($team->id);
            $terms = $explained['terms'];

            SystemLog::ok('billing.terms.resolved',
                input: [...$logInput, 'stage' => 'invoice'],
                calc: ['values' => $terms->toArray(), 'sources' => $explained['sources']],
            );

            $limit = $resolveAssetLimit->explain($team->id);
            $cap = $limit['cap'];
            $this->logAssetLimit($logInput, $limit);

            $daysInPeriod = (int) $periodStart->diffInDays($periodEnd) + 1;

            $subscription = Subscription::query()
                ->where('team_id', $team->id)
                ->whereIn('status', ['active', 'past_due'])
                ->latest('starts_at')
                ->first();

            $breakdown = [];
            $subtotalTerms = [];
            $overageTerms = [];

            // 1. Tractos vigilados por día: la base de la factura.
            $assetDaysRead = $this->consumed($team, AssetDayPricing::METER_CODE, $periodStart, $logInput);
            $assetDays = $assetDaysRead['consumed'];
            $assetLine = AssetDayPricing::assetDayLine(
                $terms,
                $assetDays,
                $daysInPeriod,
                $cap,
            );
            $breakdown[] = $assetLine;
            $subtotal = (float) $assetLine['amount'];
            $subtotalTerms[] = $subtotal;

            // Mismos días que usó assetDayLine (max(1, días del periodo)).
            $pricedDays = (int) $assetLine['days_in_period'];
            $averageAssets = $assetDays / $pricedDays;

            SystemLog::ok('billing.tier.selected',
                input: $logInput,
                calc: [
                    'asset_days' => $assetLine['consumed'],
                    'days_in_period' => $pricedDays,
                    'average_assets' => $averageAssets,
                    ...$terms->explainUnitPriceFor($averageAssets),
                ],
                result: ['unit_price' => $assetLine['unit_price']],
            );

            SystemLog::ok('billing.asset_day.calculated',
                input: $logInput,
                calc: [
                    ...AssetDayPricing::loggable($assetLine),
                    'counter_found' => $assetDaysRead['counter_found'],
                    'min_billable_assets' => $terms->minBillableAssets,
                    'cap_source' => $limit['source'],
                    'formula' => self::ASSET_DAY_FORMULA,
                ],
                result: ['amount' => $assetLine['amount'], 'currency' => $terms->currency],
            );

            // 1b. Emergencias atendidas en unidades no vigiladas: tracto-día +
            // recargo. Sólo aparece si hubo alguna.
            $unmonitoredRead = $this->consumed($team, AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE, $periodStart, $logInput);
            $unmonitoredDays = $unmonitoredRead['consumed'];

            if ($unmonitoredDays > 0) {
                $emergencyLine = AssetDayPricing::unmonitoredEmergencyLine($unmonitoredDays, (float) $assetLine['daily_rate']);
                $breakdown[] = $emergencyLine;
                $subtotal += (float) $emergencyLine['amount'];
                $subtotalTerms[] = (float) $emergencyLine['amount'];
                $this->logLine($logInput, $emergencyLine, $unmonitoredRead['counter_found'], self::SURCHARGE_FORMULA, 'subtotal');
            }

            // 2. Uso justo de IA sobre el promedio de tractos vigilados.
            $aiRead = $this->consumed($team, AssetDayPricing::AI_METER_CODE, $periodStart, $logInput);
            $aiLine = AssetDayPricing::aiLine(
                $terms,
                $aiRead['consumed'],
                (float) $assetLine['average_assets'],
            );
            $breakdown[] = $aiLine;
            $overageTotal = (float) $aiLine['amount'];
            $overageTerms[] = $overageTotal;
            $this->logLine($logInput, $aiLine, $aiRead['counter_found'], self::FAIR_USE_FORMULA, 'overage_total');

            // 3. Twilio a costo real + margen, en la moneda del tenant.
            $messagingRate = $subscription !== null
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
                $markupSource = match (true) {
                    $terms->messagingMarkupPercent !== null => 'tenant_terms',
                    $messagingRate?->markup_percent !== null => 'plan_rate',
                    default => 'platform_default',
                };

                $messagingRead = $this->consumed($team, $messagingMeter->code, $periodStart, $logInput);
                $messagingLine = AssetDayPricing::messagingLine(
                    $terms,
                    (float) $messagingRead['consumed'],
                    $markup,
                    $messagingMeter->code,
                    $messagingMeter->name,
                );
                $breakdown[] = $messagingLine;
                $overageTotal += (float) $messagingLine['amount'];
                $overageTerms[] = (float) $messagingLine['amount'];
                $this->logLine($logInput, $messagingLine, $messagingRead['counter_found'], self::COST_PLUS_FORMULA, 'overage_total', ['markup_source' => $markupSource]);
            } else {
                SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: [...$logInput, 'meter_unit' => CostPlusPricing::MICRO_UNIT, 'stage' => 'invoice']);
            }

            // 4. Líneas informativas de los demás medidores del plan (topes).
            $planMetersBilled = 0;
            $planMetersSkipped = 0;

            if ($subscription !== null) {
                $billingRates = BillingRate::query()
                    ->with('usageMeter')
                    ->where('plan_id', $subscription->plan_id)
                    ->where('billing_model', '!=', BillingModel::CostPlus)
                    ->get();

                foreach ($billingRates as $rate) {
                    $code = $rate->usageMeter?->code;

                    if ($code === null || in_array($code, self::DEDICATED_METERS, true)) {
                        $planMetersSkipped++;

                        continue;
                    }

                    $planRead = $this->consumed($team, $code, $periodStart, $logInput);
                    $consumed = $planRead['consumed'];
                    $included = $rate->included_quantity;
                    $overage = max(0, $consumed - $included);
                    $overageCost = round($overage * (float) $rate->overage_unit_price, 2);
                    $overageTotal += $overageCost;
                    $overageTerms[] = $overageCost;

                    $planLine = [
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
                    $breakdown[] = $planLine;
                    $planMetersBilled++;
                    $this->logLine($logInput, $planLine, $planRead['counter_found'], self::PLAN_METER_FORMULA, 'overage_total');
                }
            }

            $overageTotal = round($overageTotal, 2);

            $snapshot = InvoiceSnapshot::query()->create([
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

            $generatedInput = [...$logInput, 'subscription_id' => $subscription?->id];
            $generatedCalc = [
                'days_in_period' => $daysInPeriod,
                'currency' => $terms->currency,
                'subtotal_terms' => $subtotalTerms,
                'overage_terms' => $overageTerms,
                'lines_count' => count($breakdown),
                'plan_meters_billed_count' => $planMetersBilled,
                'plan_meters_skipped_count' => $planMetersSkipped,
                'formula' => self::INVOICE_FORMULA,
            ];
            $generatedResult = [
                'invoice_id' => $snapshot->id,
                'subtotal' => $snapshot->subtotal,
                'overage_total' => $snapshot->overage_total,
                'total' => $snapshot->total,
                'status' => $snapshot->status->value,
            ];

            DB::afterCommit(fn () => TenantContext::for($this->teamId, fn () => SystemLog::ok('billing.invoice.generated',
                input: $generatedInput,
                calc: $generatedCalc,
                result: $generatedResult,
            )));
        });
    }

    /**
     * @return array{team_id: int, period_start: string, period_end: string}
     */
    private function logInput(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return [
            'team_id' => $this->teamId,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $logInput
     * @param  array{cap: ?int, source: string, calc: array<string, mixed>}  $limit
     */
    private function logAssetLimit(array $logInput, array $limit): void
    {
        $input = [...$logInput, 'stage' => 'invoice'];
        $calc = ['source' => $limit['source'], ...$limit['calc']];
        $result = ['cap' => $limit['cap']];

        if (($limit['calc']['none_reason'] ?? null) === 'meter_missing') {
            SystemLog::degraded('billing.asset_limit.resolved', reason: 'meter_missing', input: $input, calc: $calc, result: $result);

            return;
        }

        SystemLog::ok('billing.asset_limit.resolved', input: $input, calc: $calc, result: $result);
    }

    /**
     * Una línea de importe añadida a `$breakdown` después del tracto-día: la
     * línea tal cual (sin `meter_name`), su fórmula y a qué total suma.
     *
     * @param  array<string, mixed>  $logInput
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $extra
     */
    private function logLine(array $logInput, array $line, bool $counterFound, string $formula, string $countsTowards, array $extra = []): void
    {
        SystemLog::ok('billing.invoice_line.calculated',
            input: $logInput,
            calc: [
                ...AssetDayPricing::loggable($line),
                'counter_found' => $counterFound,
                ...$extra,
                'formula' => $formula,
            ],
            result: ['amount' => $line['amount'], 'counts_towards' => $countsTowards],
        );
    }

    /**
     * Consumo del periodo desde el contador ya recalculado por AggregateUsageJob.
     * `counter_found = false`: no hay fila del periodo (el agregado no corrió
     * o no hubo uso) y se lee 0.
     *
     * @param  array<string, mixed>  $logInput
     * @return array{consumed: int, counter_found: bool}
     */
    private function consumed(Team $team, string $meterCode, CarbonImmutable $periodStart, array $logInput): array
    {
        $meterId = UsageMeter::query()->where('code', $meterCode)->value('id');

        if ($meterId === null) {
            SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: [...$logInput, 'meter_code' => $meterCode, 'stage' => 'invoice']);

            return ['consumed' => 0, 'counter_found' => false];
        }

        $value = TenantUsageCounter::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meterId)
            ->whereDate('period_start', $periodStart->toDateString())
            ->value('consumed_value');

        return ['consumed' => (int) ($value ?? 0), 'counter_found' => $value !== null];
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
