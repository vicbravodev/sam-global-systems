<?php

namespace Tests\Feature\Architecture;

use App\Concerns\BelongsToTenant;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Models\UserPreference;
use App\Domains\AI\Models\AIDecisionSignal;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Models\AIModelVersion;
use App\Domains\AI\Models\AIRecommendedAction;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\ChangeHistory;
use App\Domains\Audit\Models\DomainEventLog;
use App\Domains\Audit\Models\SystemTrace;
use App\Domains\Audit\Models\TraceLink;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\EscalationStep;
use App\Domains\Context\Models\EventRecentHistorySnapshot;
use App\Domains\Context\Models\GeofenceMatch;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\DecisionTrace;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Drivers\Models\DriverStatusLog;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentComment;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentResolution;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Domains\Ingestion\Models\EventReceipt;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guard arquitectural de la regla CLAUDE.md §2.1 (tenant scope obligatorio).
 *
 * SAM es multi-tenant y el aislamiento no puede depender de que alguien se
 * acuerde de escribirlo. Este test recorre TODOS los modelos Eloquent del
 * proyecto, mira la tabla real tras migrar, y exige que cada uno esté
 * clasificado de forma explícita en una de estas categorías:
 *
 *   1. team_id NOT NULL  -> usa el trait BelongsToTenant (o está exento con razón).
 *   2. team_id nullable  -> declarado como tenant-scoped o como "global o de tenant".
 *   3. sin team_id       -> catálogo de plataforma, o dato de tenant que hereda el
 *                           aislamiento de un padre declarado.
 *
 * Un modelo nuevo que no encaje falla el test. Eso es intencional: meter una
 * tabla sin tenant tiene que ser una decisión escrita, no un olvido.
 */
class TenantScopeConventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * team_id NOT NULL pero SIN el trait. Sólo para modelos que definen la
     * pertenencia al tenant: scoparlos por tenant sería circular.
     *
     * @var array<class-string<Model>, string>
     */
    private const TENANT_SCOPE_EXEMPT = [
        Membership::class => 'Define la membresía usuario↔team; el scope por team se mordería la cola.',
        TeamInvitation::class => 'Se resuelve por token público antes de que exista contexto de tenant.',
    ];

    /**
     * team_id nullable Y con el trait: las filas globales (team_id = null)
     * quedan invisibles cuando hay tenant activo, y eso es lo que se quiere.
     *
     * @var array<class-string<Model>, string>
     */
    private const TENANT_SCOPED_NULLABLE = [
        AuditLog::class => 'Filas de sistema (team_id null) no deben aparecer en la auditoría de un tenant.',
        ChangeHistory::class => 'Ídem AuditLog.',
        DomainEventLog::class => 'Ídem AuditLog.',
        SystemTrace::class => 'Ídem AuditLog.',
        TraceLink::class => 'Ídem AuditLog.',
        EventSource::class => 'Una fuente sin team es de plataforma y no debe verse desde un tenant.',
        RawEvent::class => 'Un evento crudo sin team aún no está atribuido; no debe verse desde un tenant.',
    ];

    /**
     * team_id nullable y SIN el trait. Cada consulta debe filtrar a mano con el
     * idiom `where('team_id', $id)` + fallback `whereNull('team_id')`.
     * Plantilla: App\Domains\Automation\Actions\ResolveActionTemplate.
     *
     * @var array<class-string<Model>, string>
     */
    private const NULLABLE_TEAM_ID_UNSCOPED = [
        UserPreference::class => 'Preferencia global del usuario (team_id null) o específica de un team.',
        ReportDefinition::class => 'Reportes de plataforma + definiciones propias del tenant.',
        ActionTemplate::class => 'Plantilla de acción de plataforma, overrideable por tenant.',
        AutomationWorkflow::class => 'Workflow default de SAM, overrideable por tenant.',
        DecisionRule::class => 'Regla default de SAM, overrideable por tenant.',
        RuleSet::class => 'Ruleset default de SAM, overrideable por tenant.',
        EventDeduplicationKey::class => 'El aislamiento efectivo viene de event_source_id; team_id se copia del RawEvent.',
        NotificationChannel::class => 'Canal gestionado por SAM (team_id null) que el tenant sólo activa/desactiva.',
        NotificationTemplate::class => 'Plantilla de plataforma, overrideable por tenant.',
    ];

    /**
     * Catálogos de plataforma: sin team_id, los mismos datos para todos los
     * tenants. Añadir aquí sólo si el contenido NO es de ningún cliente.
     *
     * @var list<class-string<Model>>
     */
    private const PLATFORM_CATALOG = [
        AIModelVersion::class,
        Permission::class,
        Role::class,
        MetricDefinition::class,
        AssetType::class,
        DecisionOutcome::class,
        IncidentPriority::class,
        IncidentStatus::class,
        IncidentType::class,
        IntegrationProvider::class,
        EventCategory::class,
        EventMappingRule::class,
        EventSeverity::class,
        EventType::class,
        BillingRate::class,
        Plan::class,
        UsageMeter::class,
        Team::class,
        User::class,
    ];

    /**
     * Datos de tenant SIN team_id propio: heredan el aislamiento del padre.
     * El valor es el nombre de la relación BelongsTo que lo conecta.
     *
     * OJO: un `find($id)` directo sobre cualquiera de estos modelos cruza
     * tenants, porque no hay scope que lo pare. Siempre se llega a ellos
     * a través del padre, o filtrando por el padre en la query.
     *
     * @var array<class-string<Model>, string>
     */
    private const TENANT_CHILD = [
        AIDecisionSignal::class => 'evaluation',
        AIExplanation::class => 'evaluation',
        AIInferenceLog::class => 'evaluation',
        AIMediaAssessment::class => 'evaluation',
        AIRecommendedAction::class => 'evaluation',
        AIReevaluationRequest::class => 'normalizedEvent',
        AssetDevice::class => 'asset',
        AssetExternalReference::class => 'asset',
        AssetLocationSnapshot::class => 'asset',
        AssetTelemetrySnapshot::class => 'asset',
        ActionExecutionLog::class => 'actionExecution',
        EscalationStep::class => 'automationWorkflow',
        EventRecentHistorySnapshot::class => 'normalizedEvent',
        GeofenceMatch::class => 'normalizedEvent',
        DecisionOverride::class => 'decision',
        DecisionTrace::class => 'decision',
        DriverContact::class => 'driver',
        DriverDocument::class => 'driver',
        DriverExternalReference::class => 'driver',
        DriverRiskProfile::class => 'driver',
        DriverStatusLog::class => 'driver',
        IncidentAssignment::class => 'incident',
        IncidentComment::class => 'incident',
        IncidentEventLink::class => 'incident',
        IncidentEvidence::class => 'incident',
        IncidentResolution::class => 'incident',
        IncidentTimeline::class => 'incident',
        EventReceipt::class => 'rawEvent',
        RawEventAttachment::class => 'rawEvent',
        IntegrationCredential::class => 'tenantIntegration',
        IntegrationSyncJob::class => 'tenantIntegration',
        WebhookEndpoint::class => 'tenantIntegration',
    ];

    public function test_models_on_required_team_id_tables_use_the_tenant_trait(): void
    {
        $offenders = [];

        foreach ($this->modelsByBucket()['required'] as $class) {
            if ($this->usesTenantTrait($class) || isset(self::TENANT_SCOPE_EXEMPT[$class])) {
                continue;
            }

            $offenders[] = $class;
        }

        $this->assertSame([], $offenders, $this->message(
            'Modelos sobre tablas con team_id NOT NULL que no usan BelongsToTenant',
            $offenders,
            'Añade `use App\Concerns\BelongsToTenant;` al modelo. Si de verdad no debe scoparse, '.
            'declara el modelo en TENANT_SCOPE_EXEMPT con la razón.',
        ));
    }

    public function test_models_with_nullable_team_id_are_explicitly_classified(): void
    {
        $offenders = [];

        foreach ($this->modelsByBucket()['nullable'] as $class) {
            if (isset(self::TENANT_SCOPED_NULLABLE[$class]) || isset(self::NULLABLE_TEAM_ID_UNSCOPED[$class])) {
                continue;
            }

            $offenders[] = $class;
        }

        $this->assertSame([], $offenders, $this->message(
            'Modelos con team_id nullable sin clasificar',
            $offenders,
            'Decide y declara: TENANT_SCOPED_NULLABLE (lleva el trait, las filas globales se ocultan) '.
            'o NULLABLE_TEAM_ID_UNSCOPED (sin trait, cada query usa where(team_id) + whereNull(team_id)). '.
            'Si el registro es siempre de un tenant, mejor haz la columna NOT NULL.',
        ));
    }

    public function test_models_without_team_id_are_explicitly_classified(): void
    {
        $offenders = [];

        foreach ($this->modelsByBucket()['none'] as $class) {
            if (in_array($class, self::PLATFORM_CATALOG, true) || isset(self::TENANT_CHILD[$class])) {
                continue;
            }

            $offenders[] = $class;
        }

        $this->assertSame([], $offenders, $this->message(
            'Modelos sin columna team_id sin clasificar',
            $offenders,
            'Si la tabla guarda datos de un cliente, lo correcto es añadirle team_id + BelongsToTenant. '.
            'Si hereda el aislamiento de un padre, decláralo en TENANT_CHILD con el nombre de la relación. '.
            'Si es un catálogo igual para todos los tenants, decláralo en PLATFORM_CATALOG.',
        ));
    }

    public function test_tenant_child_models_reach_a_tenant_scoped_parent(): void
    {
        $offenders = [];

        foreach (self::TENANT_CHILD as $class => $relation) {
            $model = new $class;

            if (! method_exists($model, $relation)) {
                $offenders[] = "{$class}: no existe la relación `{$relation}()`";

                continue;
            }

            $related = $model->{$relation}();

            if (! $related instanceof BelongsTo) {
                $offenders[] = "{$class}::{$relation}() no es un BelongsTo";

                continue;
            }

            if (! $this->reachesTenantScope($related->getRelated()::class)) {
                $offenders[] = "{$class}::{$relation}() lleva a ".$related->getRelated()::class.', que tampoco está scopeado';
            }
        }

        $this->assertSame([], $offenders, $this->message(
            'Modelos hijo cuya cadena de padres no llega a un modelo con tenant',
            $offenders,
            'La relación declarada en TENANT_CHILD tiene que terminar en un modelo con BelongsToTenant '.
            'o en uno de NULLABLE_TEAM_ID_UNSCOPED.',
        ));
    }

    public function test_classification_lists_have_no_stale_entries(): void
    {
        $buckets = $this->modelsByBucket();
        $offenders = [];

        $expectations = [
            'TENANT_SCOPE_EXEMPT' => [array_keys(self::TENANT_SCOPE_EXEMPT), 'required'],
            'TENANT_SCOPED_NULLABLE' => [array_keys(self::TENANT_SCOPED_NULLABLE), 'nullable'],
            'NULLABLE_TEAM_ID_UNSCOPED' => [array_keys(self::NULLABLE_TEAM_ID_UNSCOPED), 'nullable'],
            'PLATFORM_CATALOG' => [self::PLATFORM_CATALOG, 'none'],
            'TENANT_CHILD' => [array_keys(self::TENANT_CHILD), 'none'],
        ];

        foreach ($expectations as $list => [$classes, $bucket]) {
            foreach ($classes as $class) {
                if (! in_array($class, $buckets[$bucket], true)) {
                    $offenders[] = "{$list}: {$class} ya no corresponde al bucket `{$bucket}`";
                }
            }
        }

        foreach (self::TENANT_SCOPED_NULLABLE as $class => $reason) {
            if (! $this->usesTenantTrait($class)) {
                $offenders[] = "TENANT_SCOPED_NULLABLE: {$class} perdió el trait BelongsToTenant";
            }
        }

        foreach (self::NULLABLE_TEAM_ID_UNSCOPED as $class => $reason) {
            if ($this->usesTenantTrait($class)) {
                $offenders[] = "NULLABLE_TEAM_ID_UNSCOPED: {$class} ahora usa el trait; muévelo a TENANT_SCOPED_NULLABLE";
            }
        }

        $this->assertSame([], $offenders, $this->message(
            'Entradas obsoletas en las listas de clasificación',
            $offenders,
            'La tabla o el modelo cambiaron. Actualiza la lista para que refleje la realidad.',
        ));
    }

    /**
     * Clasifica cada modelo del proyecto según la columna team_id de su tabla real.
     *
     * @return array{required: list<class-string<Model>>, nullable: list<class-string<Model>>, none: list<class-string<Model>>}
     */
    private function modelsByBucket(): array
    {
        $buckets = ['required' => [], 'nullable' => [], 'none' => []];

        foreach ($this->modelClasses() as $class) {
            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $teamId = collect(Schema::getColumns($table))->firstWhere('name', 'team_id');

            $buckets[match (true) {
                $teamId === null => 'none',
                (bool) $teamId['nullable'] => 'nullable',
                default => 'required',
            }][] = $class;
        }

        return $buckets;
    }

    /**
     * ¿La cadena de este modelo termina en algo scopeado por tenant?
     *
     * @param  list<class-string<Model>>  $seen
     */
    private function reachesTenantScope(string $class, array $seen = []): bool
    {
        if (in_array($class, $seen, true)) {
            return false;
        }

        if ($this->usesTenantTrait($class) || isset(self::NULLABLE_TEAM_ID_UNSCOPED[$class])) {
            return true;
        }

        if (! isset(self::TENANT_CHILD[$class])) {
            return false;
        }

        $relation = (new $class)->{self::TENANT_CHILD[$class]}();

        return $this->reachesTenantScope($relation->getRelated()::class, [...$seen, $class]);
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function usesTenantTrait(string $class): bool
    {
        return in_array(BelongsToTenant::class, class_uses_recursive($class), true);
    }

    /**
     * @param  list<string>  $offenders
     */
    private function message(string $title, array $offenders, string $fix): string
    {
        return "\n{$title}:\n  - ".implode("\n  - ", $offenders)."\n\nCómo arreglarlo: {$fix}\n".
            "Regla completa: CLAUDE.md §2.1.\n";
    }

    /**
     * Todos los modelos Eloquent concretos del proyecto.
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach ([app_path('Domains'), app_path('Models')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR);

                if ($root === app_path('Domains') && ! Str::contains($relative, DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $class = 'App\\'.Str::of($relative)->beforeLast('.php')->replace(DIRECTORY_SEPARATOR, '\\')->toString();

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
