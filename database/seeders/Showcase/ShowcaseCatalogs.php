<?php

namespace Database\Seeders\Showcase;

use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\CostPlusPricing;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Database\Seeders\AnalyticsMeterSeeder;
use Database\Seeders\AssetMeterSeeder;
use Database\Seeders\AssetTypeSeeder;
use Database\Seeders\AutomationMeterSeeder;
use Database\Seeders\ContextMeterSeeder;
use Database\Seeders\DecisionOutcomeSeeder;
use Database\Seeders\IncidentsMeterSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\IngestionMeterSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\NormalizationSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\OtpMeterSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformChannelSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Catálogos de plataforma que el showcase necesita. Los que ya existen NO se
 * re-siembran (un `updateOrCreate` pisaría, por ejemplo, precios de plan
 * ajustados desde la consola): sólo se llama al seeder de un catálogo
 * vacío. Los catálogos nuevos de este PR (medidores de automatización y
 * reportes, métricas y reportes globales) son upserts inocuos y siempre corren.
 */
class ShowcaseCatalogs
{
    public function ensure(?Command $command = null): void
    {
        $when = [
            AccessSeeder::class => fn () => Role::query()->exists(),
            AssetTypeSeeder::class => fn () => AssetType::query()->exists(),
            IncidentsSeeder::class => fn () => IncidentStatus::query()->exists(),
            DecisionOutcomeSeeder::class => fn () => DecisionOutcome::query()->exists(),
            NormalizationSeeder::class => fn () => EventType::query()->exists(),
            NotificationTemplateSeeder::class => fn () => NotificationTemplate::query()->exists(),
            PlatformChannelSeeder::class => fn () => NotificationChannel::query()->exists(),
        ];

        foreach ($when as $seeder => $alreadySeeded) {
            if (! $alreadySeeded()) {
                $this->call($seeder, $command);
            }
        }

        foreach ([
            AIMeterSeeder::class, AssetMeterSeeder::class, IngestionMeterSeeder::class, ContextMeterSeeder::class,
            IncidentsMeterSeeder::class, OtpMeterSeeder::class, NotificationMeterSeeder::class,
            AutomationMeterSeeder::class, AnalyticsMeterSeeder::class,
            MetricDefinitionSeeder::class, ReportDefinitionSeeder::class,
        ] as $seeder) {
            $this->call($seeder, $command);
        }

        if (! Plan::query()->exists()) {
            $this->call(PlanSeeder::class, $command);
        }

        $this->ensureCostPlusRates();

    }

    /**
     * @param  class-string<Seeder>  $seeder
     */
    private function call(string $seeder, ?Command $command): void
    {
        $instance = app($seeder);
        $instance->setContainer(app());

        if ($command !== null) {
            $instance->setCommand($command);
        }

        $instance->__invoke();
    }

    /**
     * Tarifa cost-plus de mensajería Twilio en cada plan (la crea PlanSeeder
     * en DBs nuevas). Sólo se añade donde falta: nunca se re-ejecuta
     * PlanSeeder sobre planes existentes porque pisaría precios ajustados.
     */
    private function ensureCostPlusRates(): void
    {
        $meterId = UsageMeter::query()->where('code', FinalizeMessagingCharge::METER_CODE)->value('id');

        if ($meterId === null) {
            return;
        }

        foreach (Plan::query()->pluck('id') as $planId) {
            BillingRate::query()->firstOrCreate(
                ['plan_id' => $planId, 'usage_meter_id' => $meterId],
                [
                    'included_quantity' => 0,
                    'overage_unit_price' => 0,
                    'billing_model' => BillingModel::CostPlus,
                    'markup_percent' => CostPlusPricing::defaultMarkup(),
                ],
            );
        }
    }
}
