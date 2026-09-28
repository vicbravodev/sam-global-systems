<?php

namespace App\Http\Controllers\Decisions;

use App\Domains\Decisions\Actions\ApplyTenantRuleSet;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Decisions\Support\DecisionConditionCatalog;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rules page (Roadmap F11): decision rules (incl. the seeded panic / false
 * alarm rules with their state), normalization mapping rules, and tenant rule
 * overrides — the mutations reuse the existing API controllers as web routes.
 */
class RulesPageController extends Controller
{
    public function show(Team $current_team, ApplyTenantRuleSet $ruleSets): Response
    {
        $this->authorize('viewAny', DecisionRule::class);

        $rules = DecisionRule::query()
            ->where(fn (Builder $q) => $q
                ->whereNull('team_id')
                ->orWhere('team_id', $current_team->id))
            ->with(['outcomeOverride', 'ruleset'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $effectiveRulesetId = $ruleSets->effectiveRuleSet($current_team->id)?->id;
        $order = $this->evaluationOrder($rules, $effectiveRulesetId);

        return Inertia::render('rules/index', [
            'decisionRules' => fn () => $rules
                ->map(fn (DecisionRule $rule): array => [
                    'id' => (int) $rule->id,
                    'code' => $rule->code,
                    'name' => $rule->name,
                    'description' => $rule->description,
                    'scope' => $rule->scope?->value,
                    'priority' => (int) $rule->priority,
                    'conditions' => $rule->conditions_json,
                    'outcomeCode' => $rule->outcomeOverride?->code,
                    'outcomeLabel' => $rule->outcomeOverride?->code !== null
                        ? (DecisionOutcomeCode::tryFrom($rule->outcomeOverride->code)?->label() ?? $rule->outcomeOverride->code)
                        : null,
                    'outcomeId' => $rule->outcome_override !== null ? (int) $rule->outcome_override : null,
                    'stopProcessing' => (bool) $rule->stop_processing,
                    'isActive' => (bool) $rule->is_active,
                    'isGlobal' => $rule->team_id === null,
                    'rulesetId' => (int) $rule->ruleset_id,
                    'rulesetCode' => $rule->ruleset?->code,
                    // Posición real en la que el motor revisa la regla (1 =
                    // primera). Null si está apagada o si pertenece a un
                    // conjunto de reglas que el motor no usa para este equipo.
                    'evaluationOrder' => $order[$rule->id] ?? null,
                    'inEffectiveRuleset' => $effectiveRulesetId !== null
                        && (int) $rule->ruleset_id === (int) $effectiveRulesetId,
                ])
                ->values()
                ->all(),
            'decisionSummary' => fn (): array => $this->decisionSummary($rules),
            'rulesets' => fn () => RuleSet::query()
                ->where(fn (Builder $q) => $q
                    ->whereNull('team_id')
                    ->orWhere('team_id', $current_team->id))
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'code', 'name', 'is_default', 'team_id'])
                ->map(fn (RuleSet $set): array => [
                    'id' => (int) $set->id,
                    'code' => $set->code,
                    'name' => $set->name,
                    'isDefault' => (bool) $set->is_default,
                    'isGlobal' => $set->team_id === null,
                ])
                ->all(),
            'outcomes' => fn () => DecisionOutcome::query()
                ->orderBy('id')
                ->get(['id', 'code', 'name'])
                ->map(fn (DecisionOutcome $outcome): array => [
                    'id' => (int) $outcome->id,
                    'code' => $outcome->code,
                    'name' => $outcome->name,
                    'label' => DecisionOutcomeCode::tryFrom($outcome->code)?->label() ?? $outcome->name,
                ])
                ->all(),
            'scopes' => fn () => array_map(
                fn (RuleScope $scope) => ['value' => $scope->value, 'label' => $scope->label()],
                RuleScope::cases(),
            ),
            'conditionFields' => fn () => DecisionConditionCatalog::fields(),
            'mappingRules' => fn () => EventMappingRule::query()
                ->with(['provider', 'mappedEventType.defaultSeverity', 'mappedSeverity'])
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(fn (EventMappingRule $rule): array => [
                    'id' => (int) $rule->id,
                    'providerId' => (int) $rule->provider_id,
                    'provider' => $rule->provider?->name,
                    'externalEventType' => $rule->external_event_type,
                    'hasConditions' => ! empty($rule->external_conditions_json),
                    'conditions' => empty($rule->external_conditions_json) ? null : $rule->external_conditions_json,
                    'mappedEventTypeId' => (int) $rule->mapped_event_type_id,
                    'mappedEventType' => $rule->mappedEventType?->name,
                    'mappedSeverity' => $rule->mappedSeverity?->label ?? $rule->mappedSeverity?->code,
                    // Gravedad con la que el evento entra de verdad: la de la
                    // regla o, si no fija ninguna, la por defecto del tipo.
                    'effectiveSeverityCode' => $rule->mappedSeverity?->code
                        ?? $rule->mappedEventType?->defaultSeverity?->code,
                    'severityFromType' => $rule->mapped_severity_id === null,
                    'mappedSeverityId' => $rule->mapped_severity_id !== null ? (int) $rule->mapped_severity_id : null,
                    'priority' => (int) $rule->priority,
                    'isActive' => (bool) $rule->is_active,
                ])
                ->all(),
            'mappingSummary' => fn (): array => [
                'total' => EventMappingRule::query()->count(),
                'active' => EventMappingRule::query()->where('is_active', true)->count(),
                'conditional' => EventMappingRule::query()->whereNotNull('external_conditions_json')->count(),
            ],
            'mappingOptions' => fn (): array => [
                'providers' => DB::table('integration_providers')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($provider) => ['value' => (string) $provider->id, 'label' => (string) $provider->name])
                    ->all(),
                'eventTypes' => EventType::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (EventType $type) => ['value' => (string) $type->id, 'label' => (string) $type->name])
                    ->all(),
                'severities' => EventSeverity::query()
                    ->orderBy('level')
                    ->get(['id', 'code', 'label'])
                    ->map(fn (EventSeverity $severity) => ['value' => (string) $severity->id, 'label' => (string) ($severity->label ?? $severity->code)])
                    ->all(),
                'categories' => EventCategory::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (EventCategory $category) => ['value' => (string) $category->id, 'label' => (string) $category->name])
                    ->all(),
            ],
            'overrides' => fn () => TenantRuleOverride::query()
                ->where('team_id', $current_team->id)
                ->orderBy('base_rule_code')
                ->get()
                ->map(fn (TenantRuleOverride $override): array => [
                    'id' => (int) $override->id,
                    'baseRuleCode' => $override->base_rule_code,
                    'overrideType' => $override->override_type?->value,
                    'config' => $override->override_config_json,
                    'reason' => $override->reason,
                    'isActive' => (bool) $override->is_active,
                ])
                ->all(),
            'overrideTypes' => fn () => array_map(fn (RuleOverrideType $type) => $type->value, RuleOverrideType::cases()),
            'canManageDecisionRules' => fn () => (bool) request()->user()?->can('create', DecisionRule::class),
            // Las reglas de mapeo son globales: sólo el super-admin las edita.
            'canManageMappingRules' => fn () => (bool) request()->user()?->can('create', EventMappingRule::class),
            'canManageOverrides' => fn () => (bool) request()->user()?->can('create', TenantRuleOverride::class),
        ]);
    }

    /**
     * Posición (1-based) de cada regla activa dentro del conjunto que el
     * motor evalúa, con el mismo orden que `ApplyTenantRuleSet`.
     *
     * @param  Collection<int, DecisionRule>  $rules
     * @return array<int, int>
     */
    private function evaluationOrder(Collection $rules, ?int $effectiveRulesetId): array
    {
        if ($effectiveRulesetId === null) {
            return [];
        }

        return $rules
            ->filter(fn (DecisionRule $rule) => $rule->is_active
                && (int) $rule->ruleset_id === $effectiveRulesetId)
            ->values()
            ->mapWithKeys(fn (DecisionRule $rule, int $index) => [(int) $rule->id => $index + 1])
            ->all();
    }

    /**
     * Conteos para la franja de resumen: activas y qué hacen las activas.
     *
     * @param  Collection<int, DecisionRule>  $rules
     * @return array{total: int, active: int, own: int, platform: int, byOutcome: array<string, int>}
     */
    private function decisionSummary(Collection $rules): array
    {
        $active = $rules->filter(fn (DecisionRule $rule) => (bool) $rule->is_active);

        $byOutcome = [];

        foreach (DecisionOutcomeCode::cases() as $code) {
            $byOutcome[$code->value] = 0;
        }

        $byOutcome['NONE'] = 0;

        foreach ($active as $rule) {
            $key = $rule->outcomeOverride?->code ?? 'NONE';
            $byOutcome[$key] = ($byOutcome[$key] ?? 0) + 1;
        }

        return [
            'total' => $rules->count(),
            'active' => $active->count(),
            'own' => $rules->whereNotNull('team_id')->count(),
            'platform' => $rules->whereNull('team_id')->count(),
            'byOutcome' => $byOutcome,
        ];
    }
}
