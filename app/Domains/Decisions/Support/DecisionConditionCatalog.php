<?php

namespace App\Domains\Decisions\Support;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Normalization\Models\EventType;
use App\Support\Conditions\ConditionField;

/**
 * Public facts a tenant can reference from the visual decision-rule builder.
 * Keys must exist in DecisionFactsBuilder::build(); internal plumbing facts
 * (`event_type` id, `team_id`) are deliberately excluded.
 */
class DecisionConditionCatalog
{
    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, array{value: string, label: string}>, operators: array<int, string>}>
     */
    public static function fields(): array
    {
        $fields = [
            new ConditionField(
                key: 'classification',
                label: 'Clasificación de IA',
                type: 'enum',
                options: self::classificationOptions(),
            ),
            new ConditionField(
                key: 'risk_score',
                label: 'Puntuación de riesgo (0–1)',
                type: 'number',
            ),
            new ConditionField(
                key: 'confidence_score',
                label: 'Confianza de la IA (0–1)',
                type: 'number',
            ),
            new ConditionField(
                key: 'priority_level',
                label: 'Nivel de prioridad',
                type: 'enum',
                options: self::priorityOptions(),
            ),
            new ConditionField(
                key: 'is_real_event',
                label: '¿Es un evento real?',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'requires_action',
                label: '¿Requiere acción?',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'event_type_code',
                label: 'Tipo de evento',
                type: 'enum',
                options: self::eventTypeOptions(),
            ),
            new ConditionField(
                key: 'media_assessment',
                label: 'Evaluación del video',
                type: 'enum',
                options: self::mediaAssessmentOptions(),
            ),
            new ConditionField(
                key: 'media_passenger_detected',
                label: 'Cámara: pasajero detectado',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'media_visible_threat',
                label: 'Cámara: amenaza visible',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'media_persons_visible_count',
                label: 'Cámara: personas visibles',
                type: 'number',
            ),
            new ConditionField(
                key: 'media_cabin_appears_normal',
                label: 'Cámara: cabina se ve normal',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'operator_call_outcome',
                label: 'Verificación telefónica del operador',
                type: 'enum',
                options: self::callOutcomeOptions(),
            ),
            new ConditionField(
                key: 'external_resolved',
                label: 'Resuelto externamente',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'parked_at_base',
                label: 'Estacionado en base',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'repeated_panic_count_24h',
                label: 'Pánicos repetidos en 24 h',
                type: 'number',
            ),
            new ConditionField(
                key: 'harsh_driving_near_event',
                label: 'Manejo brusco cerca del evento',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'outside_operating_hours',
                label: 'Fuera del horario operativo',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'gps_lost_in_motion',
                label: 'GPS perdido en movimiento (posible jamming)',
                type: 'boolean',
            ),
            new ConditionField(
                key: 'nearby_safety_events_count',
                label: 'Safety events alrededor del evento',
                type: 'number',
            ),
            new ConditionField(
                key: 'has_context_snapshot',
                label: 'Tiene contexto operacional',
                type: 'boolean',
            ),
        ];

        return array_map(fn (ConditionField $field) => $field->toArray(), $fields);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function classificationOptions(): array
    {
        $labels = [
            EventClassification::RealEvent->value => 'Evento real',
            EventClassification::FalsePositive->value => 'Falso positivo',
            EventClassification::Noise->value => 'Ruido',
            EventClassification::Duplicate->value => 'Duplicado',
            EventClassification::Unclear->value => 'No concluyente',
            EventClassification::PendingEvidence->value => 'Pendiente de evidencia',
        ];

        return self::optionsFromLabels(EventClassification::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function priorityOptions(): array
    {
        $labels = [
            EvaluationPriority::Low->value => 'Baja',
            EvaluationPriority::Normal->value => 'Normal',
            EvaluationPriority::High->value => 'Alta',
            EvaluationPriority::Urgent->value => 'Urgente',
        ];

        return self::optionsFromLabels(EvaluationPriority::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function mediaAssessmentOptions(): array
    {
        $labels = [
            MediaAssessmentResult::ConfirmsEvent->value => 'Confirma el evento',
            MediaAssessmentResult::ContradictsEvent->value => 'Contradice el evento',
            MediaAssessmentResult::Inconclusive->value => 'No concluyente',
            MediaAssessmentResult::LowQuality->value => 'Calidad baja',
            MediaAssessmentResult::Unavailable->value => 'No disponible',
        ];

        return self::optionsFromLabels(MediaAssessmentResult::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function callOutcomeOptions(): array
    {
        $labels = [
            CallVerificationOutcome::ConfirmedReal->value => 'Confirmó emergencia real',
            CallVerificationOutcome::ConfirmedFalse->value => 'Confirmó falsa alarma',
            CallVerificationOutcome::NoAnswer->value => 'Sin respuesta',
        ];

        return self::optionsFromLabels(CallVerificationOutcome::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function eventTypeOptions(): array
    {
        return EventType::query()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (EventType $type) => [
                'value' => (string) $type->code,
                'label' => (string) ($type->name ?? $type->code),
            ])
            ->all();
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @param  array<string, string>  $labels
     * @return array<int, array{value: string, label: string}>
     */
    private static function optionsFromLabels(array $cases, array $labels): array
    {
        return array_map(fn (\BackedEnum $case) => [
            'value' => (string) $case->value,
            'label' => $labels[$case->value] ?? (string) $case->value,
        ], $cases);
    }
}
