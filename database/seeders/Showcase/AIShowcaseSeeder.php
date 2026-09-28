<?php

namespace Database\Seeders\Showcase;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\EventScenario;
use Database\Seeders\Showcase\Support\ShowcaseEvents;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluaciones de IA de los eventos que el pipeline manda a IA (las
 * categorías de `ai.skip_evaluation_categories` se saltan, igual que en
 * producción): clasificación, confianza, riesgo, explicación con pasos de
 * razonamiento, señales, acciones recomendadas, log de inferencia con
 * tokens/costo/latencia y veredictos sobre la media.
 *
 * ~1 de cada 4 eventos con media pasa por el flujo diferido real: v1
 * `deferred_pending_media` → llega la media → solicitud de re-evaluación →
 * v2 `multimodal`. Un ~2 % de inferencias falla (timeout/error) y la
 * evaluación cae a `rules_only`.
 *
 * Idempotencia: sólo eventos SIN ninguna evaluación. Nunca toca las reales.
 */
class AIShowcaseSeeder extends ShowcaseStep
{
    public function run(): void
    {
        $events = new ShowcaseEvents($this->ctx);
        $query = $events->query()
            ->whereHas('eventCategory', fn ($q) => $q->whereNotIn('code', $events->skipCategories()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('ai_event_evaluations')
                ->whereColumn('ai_event_evaluations.normalized_event_id', 'normalized_events.id'));

        $events->chunk($query, function (Collection $chunk) use ($events) {
            $children = ['ai_explanations' => [], 'ai_decision_signals' => [], 'ai_recommended_actions' => [], 'ai_inference_logs' => [], 'ai_media_assessments' => [], 'ai_reevaluation_requests' => []];
            $media = DB::table('event_media_contexts')
                ->whereIn('normalized_event_id', $chunk->pluck('id'))
                ->where('media_type', 'snapshot')
                ->get()
                ->groupBy('normalized_event_id');

            foreach ($chunk as $event) {
                $scenario = $events->scenario($event);
                $random = ShowcaseRandom::forKey('ai:'.$event->id);
                $at = CarbonImmutable::parse($event->processed_at ?? $event->occurred_at);
                $eventMedia = $media->get($event->id, collect());
                $failed = $random->chance(0.02);
                $deferred = ! $failed && $eventMedia->isNotEmpty() && $random->chance(0.25);

                if ($deferred) {
                    $pending = $this->evaluation($event, $scenario, 1, 'deferred_pending_media', $at->addSeconds(4), $random, pending: true);
                    $children['ai_inference_logs'][] = $this->inferenceLog($pending, $random, 'success', 0);
                    $children['ai_reevaluation_requests'][] = [
                        'normalized_event_id' => $event->id,
                        'trigger_type' => 'media_arrived',
                        'trigger_reference_id' => $eventMedia->first()->id,
                        'reason' => 'Llegaron las fotos de cabina y camino del evento.',
                        'status' => 'completed',
                        'requested_at' => $at->addMinutes(3),
                        'processed_at' => $at->addMinutes(4),
                        'created_at' => $at->addMinutes(3),
                        'updated_at' => $at->addMinutes(4),
                    ];
                }

                $mode = match (true) {
                    $failed => 'rules_only',
                    $deferred => 'multimodal',
                    $eventMedia->isNotEmpty() => 'hybrid',
                    default => 'ai_text',
                };
                $final = $this->evaluation($event, $scenario, $deferred ? 2 : 1, $mode, $at->addSeconds($deferred ? 250 : $random->int(3, 18)), $random);

                $children['ai_inference_logs'][] = $this->inferenceLog($final, $random, $failed ? $random->pick(['error', 'timeout']) : 'success', $eventMedia->count());
                $children['ai_explanations'][] = $this->explanation($final, $event, $scenario);

                foreach ($this->signals($event, $scenario, $random) as $signal) {
                    $children['ai_decision_signals'][] = ['evaluation_id' => $final->id, ...$signal, 'created_at' => $final->evaluated_at, 'updated_at' => $final->evaluated_at];
                }

                $children['ai_recommended_actions'][] = [
                    'evaluation_id' => $final->id,
                    'action_type' => $scenario->recommendedAction,
                    'priority' => 1,
                    'parameters_json' => ['reason' => $final->explanation_text],
                    'requires_confirmation' => in_array($scenario->recommendedAction, ['trigger_emergency_protocol', 'call_driver'], true),
                    'created_at' => $final->evaluated_at,
                    'updated_at' => $final->evaluated_at,
                ];

                if ($scenario->decisionCode === 'ESCALATE') {
                    $children['ai_recommended_actions'][] = [
                        'evaluation_id' => $final->id,
                        'action_type' => 'notify_supervisor',
                        'priority' => 2,
                        'parameters_json' => ['role' => 'supervisor'],
                        'requires_confirmation' => false,
                        'created_at' => $final->evaluated_at,
                        'updated_at' => $final->evaluated_at,
                    ];
                }

                if (! $failed) {
                    foreach ($eventMedia as $item) {
                        $children['ai_media_assessments'][] = $this->mediaAssessment($final, $item, $scenario, $random);
                    }
                }
            }

            foreach ($children as $table => $rows) {
                $this->bulkInsert($table, $rows, timestamps: false);
            }
        });
    }

    private function evaluation(NormalizedEvent $event, EventScenario $s, int $version, string $mode, CarbonImmutable $at, ShowcaseRandom $random, bool $pending = false): AIEventEvaluation
    {
        $latency = $mode === 'rules_only' ? $random->int(40, 180) : $random->int(900, 6_500);
        $steps = $pending ? [
            'Evento de '.($event->eventType?->name ?? 'tipo desconocido').' recibido; la cámara aún no entrega evidencia.',
            'Se difiere la clasificación hasta recibir las imágenes solicitadas.',
        ] : $this->reasoning($event, $s, $mode);

        $evaluation = AIEventEvaluation::query()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->ctx->team->id,
            'evaluation_version' => $version,
            'evaluation_mode' => $mode,
            'classification' => $pending ? 'pending_evidence' : $s->classification,
            'confidence_score' => $pending ? 0.4 : ($mode === 'rules_only' ? min($s->confidence, 0.7) : $s->confidence),
            'risk_score' => $pending ? 0.5 : round($s->riskScore / 100, 2),
            'priority_level' => $pending ? 'high' : $s->aiPriority,
            'is_real_event' => ! $pending && $s->isReal(),
            'requires_action' => ! $pending && in_array($s->decisionCode, ['INCIDENT', 'ESCALATE', 'REQUIRE_HUMAN_REVIEW', 'ALERT'], true),
            'recommended_action' => $pending ? 'wait_for_media' : $s->recommendedAction,
            'explanation_text' => $pending ? 'Esperando evidencia visual para clasificar.' : $this->summary($event, $s),
            'signals_json' => ['reasoning_steps' => $steps, 'latency_ms' => $latency, 'showcase' => true],
            'evidence_summary_json' => ['latency_ms' => $latency, 'media_count' => $mode === 'multimodal' || $mode === 'hybrid' ? 2 : 0],
            'model_used' => match ($mode) {
                'rules_only' => 'sam-rules',
                'multimodal', 'hybrid' => 'gpt-5.4-vision',
                default => 'gpt-5.4',
            },
            'evaluated_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->ctx->count('ai_event_evaluations');

        return $evaluation;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferenceLog(AIEventEvaluation $evaluation, ShowcaseRandom $random, string $status, int $mediaCount): array
    {
        $in = $status === 'success' ? $random->int(1_800, 4_200) + $mediaCount * 1_100 : $random->int(1_800, 2_600);
        $out = $status === 'success' ? $random->int(180, 520) : 0;

        return [
            'evaluation_id' => $evaluation->id,
            'input_snapshot_json' => ['normalized_event_id' => $evaluation->normalized_event_id, 'mode' => $evaluation->evaluation_mode?->value],
            'output_json' => $status === 'success'
                ? ['classification' => $evaluation->classification?->value, 'confidence' => (float) $evaluation->confidence_score]
                : ['error' => $status === 'timeout' ? 'El proveedor no respondió en 30 s.' : 'HTTP 503 del proveedor de IA.'],
            'latency_ms' => $status === 'timeout' ? 30_000 : $random->int(900, 6_500),
            'tokens_used' => $in + $out,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'media_assets_count' => $mediaCount,
            'cost_estimate' => round($in * 0.00000125 + $out * 0.00001, 6),
            'status' => $status,
            'created_at' => $evaluation->evaluated_at,
            'updated_at' => $evaluation->evaluated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function explanation(AIEventEvaluation $evaluation, NormalizedEvent $event, EventScenario $s): array
    {
        return [
            'evaluation_id' => $evaluation->id,
            'summary' => $this->summary($event, $s),
            'reasoning_steps_json' => $this->reasoning($event, $s, (string) $evaluation->evaluation_mode?->value),
            'key_factors_json' => array_values(array_filter([
                $s->withMedia ? 'Evidencia visual disponible' : null,
                $s->isReal() ? 'Patrón consistente con un evento real' : 'Patrón típico de activación accidental',
                $s->riskScore > 70 ? 'Riesgo alto por contexto de ruta' : null,
            ])),
            'confidence_breakdown_json' => [
                'context' => round($s->confidence * 0.9, 2),
                'media' => $s->withMedia ? round(min(1, $s->confidence + 0.03), 2) : null,
                'history' => round($s->confidence * 0.8, 2),
            ],
            'evidence_used_json' => ['context_snapshot', 'recent_history', ...($s->withMedia ? ['road_facing', 'driver_facing'] : [])],
            'created_at' => $evaluation->evaluated_at,
            'updated_at' => $evaluation->evaluated_at,
        ];
    }

    /**
     * @return array<int, array{signal_code: string, signal_value: string, weight: float, description: string}>
     */
    private function signals(NormalizedEvent $event, EventScenario $s, ShowcaseRandom $random): array
    {
        return [
            ['signal_code' => 'event_type', 'signal_value' => (string) $event->eventType?->code, 'weight' => 0.35, 'description' => 'Tipo de evento reportado por el proveedor.'],
            ['signal_code' => 'speed_kph', 'signal_value' => (string) ($event->payload_normalized_json['speed_kph'] ?? 0), 'weight' => 0.15, 'description' => 'Velocidad al momento del evento.'],
            ['signal_code' => 'visual_evidence', 'signal_value' => $s->withMedia ? 'available' : 'none', 'weight' => $s->withMedia ? 0.3 : 0.05, 'description' => 'Disponibilidad de fotos/clip del evento.'],
            ['signal_code' => 'recent_recurrence', 'signal_value' => (string) $random->int(0, 3), 'weight' => 0.2, 'description' => 'Eventos del mismo tipo en la última hora.'],
        ];
    }

    /**
     * @param  object{id: int, media_role: string}  $media
     * @return array<string, mixed>
     */
    private function mediaAssessment(AIEventEvaluation $evaluation, object $media, EventScenario $s, ShowcaseRandom $random): array
    {
        $result = match (true) {
            $random->chance(0.08) => 'low_quality',
            $s->classification === 'real_event' => $random->chance(0.85) ? 'confirms_event' : 'inconclusive',
            $s->classification === 'unclear' => 'inconclusive',
            default => $random->chance(0.7) ? 'contradicts_event' : 'inconclusive',
        };
        $driverFacing = $media->media_role === 'driver_facing';

        return [
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
            'media_type' => 'snapshot',
            'assessment_type' => $driverFacing ? 'behavioral_check' : 'visual_validation',
            'result' => $result,
            'confidence_score' => $random->float(0.55, 0.96),
            'extracted_signals_json' => $driverFacing
                ? ['persons_visible_count' => $random->int(1, 2), 'cabin_appears_normal' => $result !== 'confirms_event', 'passenger_detected' => $random->chance(0.15)]
                : ['road_visible' => $result !== 'low_quality', 'vehicle_stopped' => $random->chance(0.4)],
            'summary_text' => match ($result) {
                'confirms_event' => $driverFacing ? 'Conductor con postura de alerta; la cabina muestra señales consistentes con el evento.' : 'La vista de camino confirma la situación reportada.',
                'contradicts_event' => $driverFacing ? 'Cabina tranquila, conductor sin signos de riesgo: probable activación accidental.' : 'Tráfico normal, sin obstáculos ni incidentes visibles.',
                'low_quality' => 'Imagen oscura o desenfocada: no se puede concluir.',
                default => 'La imagen no permite confirmar ni descartar el evento.',
            },
            'latency_ms' => $random->int(1_200, 4_800),
            'input_tokens' => $random->int(1_000, 1_600),
            'output_tokens' => $random->int(90, 240),
            'cost_estimate' => round($random->float(0.002, 0.006, 6), 6),
            'model_used' => 'gpt-5.4-vision',
            'assessed_at' => $evaluation->evaluated_at,
            'created_at' => $evaluation->evaluated_at,
            'updated_at' => $evaluation->evaluated_at,
        ];
    }

    private function summary(NormalizedEvent $event, EventScenario $s): string
    {
        $type = $event->eventType?->name ?? 'Evento';
        $asset = $event->asset?->name ?? 'la unidad';

        return match ($s->classification) {
            'real_event' => "{$type} real en {$asset}: el contexto de ruta y la evidencia disponible lo confirman. ".match ($s->decisionCode) {
                'ESCALATE' => 'Se recomienda activar el protocolo de emergencia.',
                'INCIDENT' => 'Se recomienda abrir incidente y contactar al conductor.',
                'ALERT' => 'Basta con alertar al supervisor de turno.',
                default => 'Se registra sin acción adicional.',
            },
            'false_positive' => "{$type} en {$asset} con patrón de activación accidental: unidad en marcha normal y cabina sin señales de riesgo.",
            'noise' => "{$type} rutinario en {$asset}: ruido operativo sin riesgo, se registra sólo para trazabilidad.",
            'unclear' => "{$type} en {$asset} sin evidencia suficiente: se requiere revisión humana antes de actuar.",
            default => "{$type} en {$asset}.",
        };
    }

    /**
     * @return array<int, string>
     */
    private function reasoning(NormalizedEvent $event, EventScenario $s, string $mode): array
    {
        $steps = [
            'Tipo de evento: '.($event->eventType?->name ?? 'desconocido').' (severidad '.($event->eventSeverity?->code ?? 'n/d').').',
            sprintf('Velocidad registrada: %s km/h.', $event->payload_normalized_json['speed_kph'] ?? 0),
        ];

        if ($mode === 'rules_only') {
            $steps[] = 'El proveedor de IA no respondió; se aplicaron únicamente las reglas del tenant.';
        } elseif ($s->withMedia) {
            $steps[] = $s->isReal()
                ? 'Las imágenes de cabina y camino son consistentes con el evento.'
                : 'Las imágenes no muestran signos de riesgo en cabina ni en el camino.';
        }

        $steps[] = match ($s->classification) {
            'real_event' => 'Conclusión: evento real, requiere '.strtolower(str_replace('_', ' ', $s->decisionCode)).'.',
            'false_positive' => 'Conclusión: falso positivo probable.',
            'noise' => 'Conclusión: ruido operativo.',
            default => 'Conclusión: evidencia insuficiente, revisión humana.',
        };

        return $steps;
    }
}
