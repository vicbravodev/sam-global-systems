<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Incidents\Models\IncidentType;

/**
 * Etiqueta humana (es-MX) de un `notification_type` (`incident.created`,
 * `incident.panic_emergency.created`, `automation.send_sms`...). Los tipos
 * son cadenas compuestas que emiten los listeners de Notifications,
 * Incidents y Automation; aquí se traducen para preferencias y políticas.
 */
class NotificationTypeLabels
{
    /** @var array<string, string> */
    private const FIXED = [
        'incident.created' => 'Incidente nuevo (cualquier tipo)',
        'incident.sla_breached' => 'Incidente sin atender a tiempo (SLA vencido)',
        'incident.priority_raised' => 'Incidente elevado a prioridad crítica',
        'incident.assigned' => 'Incidente asignado a ti',
        'incident.assigned.on_call' => 'Incidente asignado a la guardia',
        'incident.status_changed' => 'Cambio de estado de un incidente',
        'driver.risk_deteriorated' => 'Riesgo de conductor en aumento',
    ];

    /** @var array<string, string> */
    private const STATUSES = [
        'open' => 'abierto',
        'in_review' => 'en revisión',
        'escalated' => 'escalado',
        'resolved' => 'resuelto',
        'closed' => 'cerrado',
        'false_positive' => 'marcado como falso positivo',
        'cancelled' => 'cancelado',
    ];

    /** @var array<string, string>|null */
    private ?array $incidentTypes = null;

    public function label(string $type): string
    {
        if (isset(self::FIXED[$type])) {
            return self::FIXED[$type];
        }

        if (preg_match('/^incident\.([a-z0-9_]+)\.created$/', $type, $match) === 1) {
            return 'Incidente nuevo: '.($this->incidentTypes()[$match[1]] ?? str_replace('_', ' ', $match[1]));
        }

        if (preg_match('/^incident\.([a-z0-9_]+)$/', $type, $match) === 1 && isset(self::STATUSES[$match[1]])) {
            return 'Incidente '.self::STATUSES[$match[1]];
        }

        if (preg_match('/^automation\.([a-z0-9_]+)$/', $type, $match) === 1) {
            return 'Automatización: '.(ActionType::tryFrom($match[1])?->label() ?? str_replace('_', ' ', $match[1]));
        }

        return ucfirst(str_replace(['.', '_'], ' ', $type));
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, array{value: string, label: string}>
     */
    public function options(array $types): array
    {
        return array_values(array_map(
            fn (string $type): array => ['value' => $type, 'label' => $this->label($type)],
            $types,
        ));
    }

    /**
     * Catálogo global de tipos de incidente (sin team_id).
     *
     * @return array<string, string>
     */
    private function incidentTypes(): array
    {
        return $this->incidentTypes ??= IncidentType::query()
            ->pluck('name', 'code')
            ->map(fn ($name): string => mb_strtolower((string) $name))
            ->all();
    }
}
