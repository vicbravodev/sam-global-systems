<?php

namespace App\Domains\Automation\Support;

use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\IncidentTypeCode;
use App\Support\Conditions\ConditionField;

/**
 * Fields available to the flat-equality trigger-condition editor, keyed by
 * the workflow trigger type. Mirrors the payloads built by the
 * TriggerAutomationOn* listeners — only enumerable keys are exposed.
 * Flat conditions are AND-of-equality, so the operator is always `eq`.
 */
class TriggerConditionCatalog
{
    /**
     * @return array<string, array<int, array{key: string, label: string, type: string, options: array<int, array{value: string, label: string}>, operators: array<int, string>}>>
     */
    public static function all(): array
    {
        $catalog = [];

        foreach (WorkflowTriggerType::cases() as $trigger) {
            $catalog[$trigger->value] = self::fieldsFor($trigger);
        }

        return $catalog;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, array{value: string, label: string}>, operators: array<int, string>}>
     */
    public static function fieldsFor(WorkflowTriggerType $trigger): array
    {
        $fields = match ($trigger) {
            WorkflowTriggerType::DecisionOutcome => [
                new ConditionField(
                    key: 'decision_code',
                    label: 'Código de decisión',
                    type: 'string',
                    operators: ['eq'],
                ),
                new ConditionField(
                    key: 'priority_level',
                    label: 'Nivel de prioridad',
                    type: 'enum',
                    options: self::decisionPriorityOptions(),
                    operators: ['eq'],
                ),
                new ConditionField(
                    key: 'outcome',
                    label: 'Resultado de la decisión',
                    type: 'enum',
                    options: self::decisionOutcomeOptions(),
                    operators: ['eq'],
                ),
            ],
            WorkflowTriggerType::IncidentCreated => self::incidentFields(),
            WorkflowTriggerType::IncidentEscalated => [
                new ConditionField(
                    key: 'previous_status',
                    label: 'Estado anterior',
                    type: 'enum',
                    options: self::incidentStatusOptions(),
                    operators: ['eq'],
                ),
                new ConditionField(
                    key: 'new_status',
                    label: 'Estado nuevo',
                    type: 'enum',
                    options: self::incidentStatusOptions(),
                    operators: ['eq'],
                ),
            ],
            default => [],
        };

        return array_map(fn (ConditionField $field) => $field->toArray(), $fields);
    }

    /**
     * Incident-shaped payload fields, shared by the IncidentCreated trigger
     * and the tenant escalation-config editor (same payload vocabulary).
     *
     * @return array<int, ConditionField>
     */
    public static function incidentFields(): array
    {
        return [
            new ConditionField(
                key: 'incident_type',
                label: 'Tipo de incidente',
                type: 'enum',
                options: self::incidentTypeOptions(),
                operators: ['eq'],
            ),
            new ConditionField(
                key: 'severity',
                label: 'Prioridad del incidente',
                type: 'enum',
                options: self::incidentPriorityOptions(),
                operators: ['eq'],
            ),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, options: array<int, array{value: string, label: string}>, operators: array<int, string>}>
     */
    public static function escalationFields(): array
    {
        return array_map(fn (ConditionField $field) => $field->toArray(), self::incidentFields());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function decisionPriorityOptions(): array
    {
        $labels = [
            DecisionPriority::Low->value => 'Baja',
            DecisionPriority::Normal->value => 'Normal',
            DecisionPriority::High->value => 'Alta',
            DecisionPriority::Urgent->value => 'Urgente',
            DecisionPriority::Critical->value => 'Crítica',
        ];

        return self::optionsFromLabels(DecisionPriority::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function decisionOutcomeOptions(): array
    {
        $labels = [
            DecisionOutcomeCode::Ignore->value => 'Ignorar',
            DecisionOutcomeCode::LogOnly->value => 'Solo registrar',
            DecisionOutcomeCode::Alert->value => 'Alertar',
            DecisionOutcomeCode::Incident->value => 'Crear incidente',
            DecisionOutcomeCode::Escalate->value => 'Escalar',
            DecisionOutcomeCode::RequireHumanReview->value => 'Requiere revisión humana',
        ];

        return self::optionsFromLabels(DecisionOutcomeCode::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function incidentTypeOptions(): array
    {
        $labels = [
            IncidentTypeCode::PanicEmergency->value => 'Pánico / emergencia',
            IncidentTypeCode::Collision->value => 'Colisión',
            IncidentTypeCode::CameraObstructed->value => 'Cámara obstruida',
            IncidentTypeCode::RouteDeviation->value => 'Desviación de ruta',
            IncidentTypeCode::GeofenceBreach->value => 'Salida de geocerca',
            IncidentTypeCode::DriverFatigue->value => 'Fatiga del conductor',
            IncidentTypeCode::SuspiciousStop->value => 'Parada sospechosa',
        ];

        return self::optionsFromLabels(IncidentTypeCode::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function incidentPriorityOptions(): array
    {
        $labels = [
            IncidentPriorityCode::Low->value => 'Baja',
            IncidentPriorityCode::Medium->value => 'Media',
            IncidentPriorityCode::High->value => 'Alta',
            IncidentPriorityCode::Critical->value => 'Crítica',
        ];

        return self::optionsFromLabels(IncidentPriorityCode::cases(), $labels);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function incidentStatusOptions(): array
    {
        $labels = [
            IncidentStatusCode::Open->value => 'Abierto',
            IncidentStatusCode::InReview->value => 'En revisión',
            IncidentStatusCode::Escalated->value => 'Escalado',
            IncidentStatusCode::Resolved->value => 'Resuelto',
            IncidentStatusCode::Closed->value => 'Cerrado',
            IncidentStatusCode::FalsePositive->value => 'Falso positivo',
            IncidentStatusCode::Cancelled->value => 'Cancelado',
        ];

        return self::optionsFromLabels(IncidentStatusCode::cases(), $labels);
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
