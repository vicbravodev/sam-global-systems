<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Enums\IncidentStatusCode;

/**
 * Single source of truth for how an incident status is shown to operators.
 *
 * Every surface that renders an incident status string (inbox rows, incident
 * detail, command palette, asset detail) MUST derive it from here so the same
 * incident never shows different states depending on the screen (C1-b).
 */
class IncidentStatusPresenter
{
    /**
     * Spanish labels per UI status key. Must stay in sync with the
     * `StatusPill` VARIANTS in resources/js/components/sam/status-pill.tsx.
     *
     * @var array<string, string>
     */
    public const UI_LABELS = [
        'new' => 'Nuevo',
        'triaging' => 'Triage',
        'assigned' => 'Asignado',
        'escalated' => 'Escalado',
        'in-progress' => 'En curso',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
        'discarded' => 'Descartado',
    ];

    /**
     * Map a canonical status code (incident_statuses.code) to the UI status
     * key the frontend styles. An open/triaging incident with an active owner
     * surfaces as "assigned", which the UI styles distinctly.
     */
    public static function uiStatus(?string $code, bool $hasActiveAssignment = false): string
    {
        $base = match ($code) {
            IncidentStatusCode::Open->value => 'new',
            IncidentStatusCode::InReview->value => 'triaging',
            IncidentStatusCode::Escalated->value => 'escalated',
            IncidentStatusCode::Resolved->value => 'resolved',
            IncidentStatusCode::Closed->value => 'closed',
            IncidentStatusCode::FalsePositive->value,
            IncidentStatusCode::Cancelled->value => 'discarded',
            default => 'new',
        };

        if ($hasActiveAssignment && in_array($base, ['new', 'triaging'], true)) {
            return 'assigned';
        }

        return $base;
    }

    /**
     * The exact Spanish string the operator sees for this status.
     */
    public static function label(?string $code, bool $hasActiveAssignment = false): string
    {
        return self::UI_LABELS[self::uiStatus($code, $hasActiveAssignment)];
    }

    /**
     * Label for the inbox status filter dropdown. Mirrors what the rows show
     * (B5) and disambiguates the two codes that both render as "Descartado".
     * Unknown tenant-specific codes fall back to the catalog name.
     */
    public static function filterLabel(string $code, ?string $fallbackName = null): string
    {
        return match ($code) {
            IncidentStatusCode::FalsePositive->value => 'Descartado (falso positivo)',
            IncidentStatusCode::Cancelled->value => 'Descartado (cancelado)',
            IncidentStatusCode::Open->value,
            IncidentStatusCode::InReview->value,
            IncidentStatusCode::Escalated->value,
            IncidentStatusCode::Resolved->value,
            IncidentStatusCode::Closed->value => self::label($code),
            default => $fallbackName ?? $code,
        };
    }
}
