<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Str;

/**
 * Acción recomendada al operador (una oración en español) que se persiste en
 * `ai_event_evaluations.recommended_action`.
 *
 * La propone el agente de IA; cuando no la hay (reglas automáticas, cuota
 * agotada, agente caído o respuesta sin el campo) se usa una recomendación
 * determinista coherente con la clasificación, la prioridad y el tipo de
 * evento. Así toda evaluación deja una instrucción concreta y accionable.
 */
final class RecommendedActionText
{
    /**
     * Largo de la columna `recommended_action` (varchar 255).
     */
    public const int MAX_LENGTH = 255;

    public const string SOURCE_AGENT = 'agent';

    public const string SOURCE_DETERMINISTIC = 'deterministic';

    /**
     * Recomendación por tipo de evento para evaluaciones accionables.
     *
     * @var array<string, string>
     */
    private const array BY_EVENT_TYPE = [
        'panic_button' => 'Llamar al operador de inmediato para confirmar su estado y despachar apoyo a la última ubicación conocida si no responde o confirma la emergencia.',
        'collision' => 'Llamar al operador para confirmar su estado y el de la unidad, y despachar asistencia médica o vial a la última ubicación conocida si hay lesionados o daños.',
        'rollover_protection' => 'Llamar al operador para confirmar su estado y la estabilidad de la unidad, y despachar asistencia a la última ubicación conocida si no responde.',
        'tampering' => 'Contactar al operador para verificar la manipulación del equipo y revisar la ubicación de la unidad; escalar a seguridad si no hay justificación.',
        'camera_obstructed' => 'Contactar al operador para que despeje la cámara y verificar la ubicación de la unidad; escalar a seguridad si no hay justificación.',
    ];

    /**
     * Elige la recomendación final: la del agente si trae texto, si no la
     * determinista.
     *
     * @return array{text: string, source: string}
     */
    public static function resolve(
        ?string $fromAgent,
        NormalizedEvent $event,
        EventClassification $classification,
        EvaluationPriority $priority,
    ): array {
        $agentText = Str::squish((string) $fromAgent);

        if ($agentText !== '') {
            return ['text' => Str::limit($agentText, self::MAX_LENGTH - 3), 'source' => self::SOURCE_AGENT];
        }

        return [
            'text' => self::deterministic($event, $classification, $priority),
            'source' => self::SOURCE_DETERMINISTIC,
        ];
    }

    /**
     * Recomendación sin IA, derivada solo de la clasificación, la prioridad
     * y el tipo/categoría del evento.
     */
    public static function deterministic(
        NormalizedEvent $event,
        EventClassification $classification,
        EvaluationPriority $priority,
    ): string {
        return match ($classification) {
            EventClassification::FalsePositive => 'Descartar como falsa alarma y documentar el motivo; no se requiere despacho.',
            EventClassification::Noise => 'Sin acción operativa: es ruido del dispositivo; revisar el equipo si la señal se repite.',
            EventClassification::Duplicate => 'Sin acción adicional: es un evento duplicado; dar seguimiento al evento o incidente original.',
            EventClassification::PendingEvidence => 'Esperar la evidencia multimedia y revisarla antes de decidir; contactar al operador si tarda.',
            EventClassification::RealEvent, EventClassification::Unclear => self::forActionable($event, $classification, $priority),
        };
    }

    private static function forActionable(NormalizedEvent $event, EventClassification $classification, EvaluationPriority $priority): string
    {
        $event->loadMissing(['eventType', 'eventCategory']);

        $byType = self::BY_EVENT_TYPE[(string) $event->eventType?->code] ?? null;

        if ($byType !== null) {
            return $byType;
        }

        if ($event->eventCategory?->code === 'emergency' || $priority === EvaluationPriority::Urgent) {
            return 'Llamar al operador de inmediato y despachar apoyo a la última ubicación conocida si no responde o confirma la emergencia.';
        }

        if ($classification === EventClassification::Unclear) {
            return 'Revisar la evidencia disponible y contactar al operador para confirmar lo ocurrido antes de cerrar el evento.';
        }

        return match ($priority) {
            EvaluationPriority::High => 'Contactar al operador para confirmar lo ocurrido y notificar al supervisor para seguimiento.',
            EvaluationPriority::Normal => 'Revisar el evento con el operador y notificar al supervisor para seguimiento.',
            default => 'Registrar el evento y revisarlo con el operador en la revisión de rutina.',
        };
    }
}
