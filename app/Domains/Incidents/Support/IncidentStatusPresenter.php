<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;

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
     * UI status for a loaded incident. "Asignado" means a PERSON owns it: an
     * active assignment to a user, or an operator who took it ("Tomar"). A
     * queue/role assignment is routing, not ownership (UI audit P0-2).
     */
    public static function forIncident(Incident $incident): string
    {
        return self::uiStatus($incident->status?->code, self::hasPersonOwner($incident));
    }

    public static function labelForIncident(Incident $incident): string
    {
        return self::UI_LABELS[self::forIncident($incident)];
    }

    public static function hasPersonOwner(Incident $incident): bool
    {
        if ($incident->claimed_by_user_id !== null) {
            return true;
        }

        $assignment = $incident->relationLoaded('currentAssignment')
            ? $incident->currentAssignment
            : $incident->currentAssignment()->first();

        return $assignment !== null && $assignment->assigned_to_type === AssigneeType::User;
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
