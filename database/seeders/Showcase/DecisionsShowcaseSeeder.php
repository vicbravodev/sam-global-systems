<?php

namespace Database\Seeders\Showcase;

use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Domains\Decisions\Models\RuleSet;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseEvents;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisiones del motor para cada evento evaluado por IA, con su traza paso
 * a paso (IA → regla del tenant → política) y ~7 % de correcciones humanas
 * (el monitorista cambia la decisión y deja el motivo). Alimenta el KPI de
 * precisión de IA del dashboard y la tasa de revisión humana de Analítica.
 *
 * Idempotencia: sólo eventos evaluados SIN decisión.
 */
class DecisionsShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, int> */
    private array $outcomes = [];

    private ?RuleSet $ruleSet = null;

    /** @var array<int, string> */
    private array $ruleCodes = [];

    /** @var array<string, int> */
    private array $policies = [];

    public function run(): void
    {
        $this->outcomes = DecisionOutcome::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->ruleSet = RuleSet::query()
            ->where(fn ($q) => $q->where('team_id', $this->ctx->team->id)->orWhereNull('team_id'))
            ->where('is_active', true)
            ->orderByRaw('team_id is null')
            ->first();
        $this->ruleCodes = $this->ruleSet !== null
            ? DecisionRule::query()->where('ruleset_id', $this->ruleSet->id)->pluck('code')->all()
            : [];
        $this->seedEscalationPolicies();

        $events = new ShowcaseEvents($this->ctx);
        $query = $events->query()
            ->whereHas('eventCategory', fn ($q) => $q->whereNotIn('code', $events->skipCategories()))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ai_event_evaluations')->whereColumn('ai_event_evaluations.normalized_event_id', 'normalized_events.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('decisions')->whereColumn('decisions.normalized_event_id', 'normalized_events.id'));

        $events->chunk($query, function (Collection $chunk) use ($events) {
            $evaluations = DB::table('ai_event_evaluations')
                ->whereIn('normalized_event_id', $chunk->pluck('id'))
                ->orderBy('evaluation_version')
                ->get()
                ->keyBy('normalized_event_id');
            $contexts = DB::table('event_context_snapshots')
                ->whereIn('normalized_event_id', $chunk->pluck('id'))
                ->pluck('id', 'normalized_event_id');
            $traces = [];
            $overrides = [];

            foreach ($chunk as $event) {
                $evaluation = $evaluations->get($event->id);

                if ($evaluation === null) {
                    continue;
                }

                $s = $events->scenario($event);
                $random = ShowcaseRandom::forKey('decision:'.$event->id);
                $decidedAt = CarbonImmutable::parse($evaluation->evaluated_at)->addSeconds(1);
                $ruleCode = $this->ruleCodes === [] ? null : $random->pick($this->ruleCodes);

                $decision = Decision::query()->create([
                    'normalized_event_id' => $event->id,
                    'team_id' => $this->ctx->team->id,
                    'ai_evaluation_id' => $evaluation->id,
                    'ruleset_id' => $this->ruleSet?->id,
                    'decision_code' => $s->decisionCode,
                    'decision_reason' => $this->reason($s->decisionCode, $s->classification, $ruleCode),
                    'priority_level' => $s->decisionPriority,
                    'requires_human_review' => $s->requiresHumanReview,
                    'is_automated' => ! $s->requiresHumanReview,
                    'escalation_policy_id' => $s->decisionCode === 'ESCALATE' ? ($this->policies['critical-emergency'] ?? null) : null,
                    'outcome_id' => $this->outcomes[$s->decisionCode] ?? reset($this->outcomes),
                    'context_snapshot_id' => $contexts[$event->id] ?? null,
                    'decided_at' => $decidedAt,
                    'created_at' => $decidedAt,
                    'updated_at' => $decidedAt,
                ]);
                $this->ctx->count('decisions');

                $steps = [
                    ['ai', null, ['classification' => $s->classification, 'confidence' => $s->confidence], ['suggested' => $s->recommendedAction], 'Clasificación de IA: '.$s->classification.' ('.round($s->confidence * 100).' %).'],
                    ['rule', $ruleCode, ['event_type_code' => $s->typeCode, 'risk_score' => $s->riskScore], ['outcome' => $s->decisionCode], $ruleCode !== null && $ruleCode !== '' ? "La regla {$ruleCode} fija el resultado {$s->decisionCode}." : 'Ninguna regla del tenant aplica; se usa la recomendación de IA.'],
                    ['tenant_policy', null, ['automation_level' => 'assisted'], ['requires_human_review' => $s->requiresHumanReview], $s->requiresHumanReview ? 'Política del tenant: revisión humana obligatoria.' : 'Política del tenant: ejecución automática permitida.'],
                ];

                if ($s->decisionCode === 'ESCALATE') {
                    $steps[] = ['escalation_policy', 'critical-emergency', ['priority' => $s->decisionPriority], ['policy' => 'critical-emergency'], 'Se activa la política de escalamiento de emergencia crítica.'];
                }

                foreach ($steps as $i => [$source, $rule, $input, $output, $explanation]) {
                    $traces[] = [
                        'decision_id' => $decision->id,
                        'rule_code' => $rule,
                        'source_type' => $source,
                        'source_reference_id' => $source === 'ai' ? $evaluation->id : null,
                        'step_order' => $i + 1,
                        'input_fragment_json' => $input,
                        'output_fragment_json' => $output,
                        'explanation' => $explanation,
                        'created_at' => $decidedAt,
                        'updated_at' => $decidedAt,
                    ];
                }

                if ($s->humanOverride && ($reviewer = $this->ctx->users['supervisor'] ?? $this->ctx->users['admin'] ?? null) !== null) {
                    [$new, $why] = match ($s->decisionCode) {
                        'ESCALATE' => ['INCIDENT', 'El conductor respondió al teléfono y confirmó que está a salvo; se atiende como incidente normal.'],
                        'INCIDENT' => [$s->isReal() ? 'ESCALATE' : 'IGNORE', $s->isReal() ? 'La unidad lleva carga de alto valor: se escala al protocolo completo.' : 'Falsa alarma confirmada con el conductor.'],
                        'REQUIRE_HUMAN_REVIEW' => ['INCIDENT', 'Revisado el video: sí requiere atención.'],
                        'ALERT' => ['INCIDENT', 'Tercera alerta del turno para el mismo conductor.'],
                        default => ['ALERT', 'El cliente pidió visibilidad de estos eventos en su ruta.'],
                    };
                    $overrides[] = [
                        'decision_id' => $decision->id,
                        'overridden_by_user_id' => $reviewer->id,
                        'previous_outcome' => $s->decisionCode,
                        'new_outcome' => $new,
                        'reason' => $why,
                        'created_at' => $decidedAt->addMinutes($random->int(2, 25)),
                        'updated_at' => $decidedAt->addMinutes($random->int(2, 25)),
                    ];
                }
            }

            $this->bulkInsert('decision_traces', $traces, timestamps: false);
            $this->bulkInsert('decision_overrides', $overrides, timestamps: false);
        });
    }

    private function seedEscalationPolicies(): void
    {
        $definitions = [
            'critical-emergency' => ['Emergencia crítica', 'Pánico y colisiones: monitorista inmediato, supervisor a los 5 min, gerencia a los 15.', 300, [
                ['order' => 1, 'target' => 'monitorista', 'channels' => ['web', 'voice'], 'delay_seconds' => 0],
                ['order' => 2, 'target' => 'supervisor', 'channels' => ['sms', 'voice'], 'delay_seconds' => 300],
                ['order' => 3, 'target' => 'tenant_admin', 'channels' => ['voice', 'email'], 'delay_seconds' => 900],
            ]],
            'compliance-review' => ['Revisión de cumplimiento', 'Manipulación y cámara obstruida: supervisor en horario hábil.', 3_600, [
                ['order' => 1, 'target' => 'supervisor', 'channels' => ['web', 'email'], 'delay_seconds' => 0],
            ]],
        ];

        foreach ($definitions as $code => [$name, $description, $wait, $steps]) {
            $policy = EscalationPolicy::query()
                ->where('team_id', $this->ctx->team->id)
                ->where('code', $code)
                ->first()
                ?? EscalationPolicy::query()->create([
                    'team_id' => $this->ctx->team->id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'trigger_conditions_json' => ['priority_level' => $code === 'critical-emergency' ? 'critical' : 'high'],
                    'escalation_steps_json' => $steps,
                    'max_wait_seconds' => $wait,
                    'requires_acknowledgement' => true,
                    // Con datos reales se deja inactiva: no debe disparar escalamientos en vivo.
                    'is_active' => ! $this->ctx->hasRealData,
                ]);

            $this->policies[$code] = $policy->id;
        }
    }

    private function reason(string $code, string $classification, ?string $rule): string
    {
        $base = match ($code) {
            'ESCALATE' => 'Emergencia confirmada: se escala al protocolo crítico.',
            'INCIDENT' => 'Evento que requiere atención operativa: se abre incidente.',
            'REQUIRE_HUMAN_REVIEW' => 'Evidencia insuficiente: un monitorista debe revisar antes de actuar.',
            'ALERT' => 'Aviso al supervisor sin abrir incidente.',
            'LOG_ONLY' => 'Se registra para trazabilidad; sin acción.',
            default => 'Evento descartado como ruido.',
        };

        // Códigos de decision_rules (nunca '0'): basta con descartar null y vacío.
        return $base.($rule !== null && $rule !== '' ? " (regla {$rule}, IA: {$classification})" : " (IA: {$classification})");
    }
}
