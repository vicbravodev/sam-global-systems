<?php

namespace Database\Seeders\Showcase;

use App\Domains\Ingestion\Models\RawEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevelopmentOnly;
use Database\Seeders\Showcase\Support\ShowcaseContext;
use Database\Seeders\Showcase\Support\ShowcaseSandbox;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Showcase: puebla TODAS las pantallas de SAM con una simulación coherente
 * de `--days` días de operación, montada sobre los datos reales del tenant
 * cuando existen (activos, conductores y eventos de Samsara) y con datos
 * sintéticos cuando no (DB recién migrada).
 *
 * Reglas (ver cada paso para su marcador de idempotencia):
 *  - ADITIVO: nunca borra ni modifica filas que no creó el showcase. Donde un
 *    registro real ya tiene hijos (trazas, telemetría, evaluación de IA…) no
 *    se le añaden más.
 *  - RE-EJECUTABLE: la segunda corrida no duplica; si pasó un día, sólo
 *    añade ese día.
 *  - AISLADO POR TENANT: cada paso corre dentro de `TenantContext::for()` del
 *    team que siembra y escribe `team_id` explícito (CLAUDE.md §2.1).
 *  - SIN EFECTOS EXTERNOS: todo corre dentro de {@see ShowcaseSandbox}.
 *  - NUNCA en producción.
 *
 * Uso: `php artisan sam:showcase` (ver SamShowcaseCommand) o
 * `(new ShowcaseSeeder)->configure(...)->run()` desde tests.
 */
class ShowcaseSeeder extends Seeder
{
    use DevelopmentOnly;

    /**
     * Orden = dependencias: cada paso usa lo que dejaron los anteriores.
     *
     * @var array<int, class-string<ShowcaseStep>>
     */
    public const STEPS = [
        TeamShowcaseSeeder::class,
        IntegrationsShowcaseSeeder::class,
        FleetShowcaseSeeder::class,
        TenantConfigShowcaseSeeder::class,
        EventsShowcaseSeeder::class,
        ContextShowcaseSeeder::class,
        AIShowcaseSeeder::class,
        DecisionsShowcaseSeeder::class,
        IncidentsShowcaseSeeder::class,
        DriverRiskShowcaseSeeder::class,
        AutomationShowcaseSeeder::class,
        NotificationsShowcaseSeeder::class,
        CopilotShowcaseSeeder::class,
        AuditShowcaseSeeder::class,
        BillingShowcaseSeeder::class,
        AnalyticsShowcaseSeeder::class,
    ];

    public const EXTRA_TENANTS = [
        ['slug' => 'transportes-del-norte', 'name' => 'Transportes del Norte', 'plan' => 'enterprise', 'status' => 'active'],
        ['slug' => 'logistica-bajio', 'name' => 'Logística Bajío', 'plan' => 'starter', 'status' => 'trialing'],
        ['slug' => 'fletes-express-sur', 'name' => 'Fletes Express del Sur', 'plan' => 'pro', 'status' => 'past_due'],
    ];

    private string $teamSlug = 'serviexpress-jc';

    private int $days = 90;

    private bool $extraTenants = false;

    /** @var array<string, array<string, int>> slug => tabla => filas creadas */
    private array $summary = [];

    private ?CarbonImmutable $now = null;

    public function configure(string $teamSlug = 'serviexpress-jc', int $days = 90, bool $extraTenants = false, ?CarbonImmutable $now = null): static
    {
        $this->teamSlug = $teamSlug;
        $this->days = max(1, min($days, 365));
        $this->extraTenants = $extraTenants;
        $this->now = $now;

        return $this;
    }

    public function run(): void
    {
        if ($this->skipInProduction()) {
            return;
        }

        ShowcaseSandbox::enter();

        Model::unguarded(function () {
            (new ShowcaseCatalogs)->ensure($this->command);

            $this->seedTenant($this->resolveTeam($this->teamSlug), $this->days, light: false);

            if ($this->extraTenants) {
                foreach (self::EXTRA_TENANTS as $extra) {
                    $team = $this->resolveTeam($extra['slug'], $extra['name']);
                    $this->seedTenant($team, min($this->days, 45), light: true, subscription: $extra);
                }

                (new AdminShowcaseSeeder)->run($this->command);
            }
        });
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    private function resolveTeam(string $slug, ?string $name = null): Team
    {
        $team = Team::query()->withTrashed()->where('slug', $slug)->first();

        if ($team !== null) {
            return $team;
        }

        $team = Team::query()->create([
            'name' => $name ?? str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'is_personal' => false,
            'timezone' => 'America/Monterrey',
            'country' => 'MX',
            'currency' => 'usd',
        ]);
        $this->command?->info("Tenant creado: {$team->name} [{$slug}]");

        return $team;
    }

    /**
     * @param  array{slug: string, name: string, plan: string, status: string}|null  $subscription
     */
    private function seedTenant(Team $team, int $days, bool $light, ?array $subscription = null): void
    {
        $this->command?->info("Showcase → {$team->name} [{$team->slug}] · {$days} días".($light ? ' (ligero)' : ''));

        TenantContext::for($team->id, function () use ($team, $days, $light, $subscription) {
            $ctx = new ShowcaseContext($team, $days, $light, $this->command instanceof Command ? $this->command : null, $this->now);
            $ctx->hasRealData = RawEvent::query()
                ->where('team_id', $team->id)
                ->where(fn ($q) => $q->whereNull('external_event_id')->orWhere('external_event_id', 'not like', 'showcase-%'))
                ->exists();
            $ctx->subscriptionPlan = $subscription['plan'] ?? 'pro';
            $ctx->subscriptionStatus = $subscription['status'] ?? 'active';

            foreach (self::STEPS as $step) {
                DB::transaction(fn () => (new $step($ctx))->run());
            }

            $this->summary[$team->slug] = $ctx->createdCounts();
        });
    }
}
