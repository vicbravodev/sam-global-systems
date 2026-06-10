<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\NotificationTemplate;

class NotifyOnIncidentCreated
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $severity = $incident->priority?->code;
        $context = $this->contextSnapshot($incident);

        $this->sendNotification->execute(
            teamId: (int) $incident->team_id,
            notificationType: $this->resolveNotificationType($incident),
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $this->mapPriority($severity),
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:'.$incident->id,
            payload: [
                'incident_id' => $incident->id,
                'incident_type' => $incident->type?->code,
                'severity' => $severity,
                'incident_title' => $incident->title,
                'asset_name' => $incident->asset?->name,
                'driver_name' => $incident->driver?->full_name,
                'location' => $this->location($incident, $context),
                'incident_url' => $this->incidentUrl($incident),
                'has_media' => $this->hasMedia($context),
            ],
            subject: 'New incident created',
            bodyPreview: 'A new incident has been reported on your team.',
        );
    }

    /**
     * Prefer an incident-type-specific notification type (e.g.
     * `incident.panic_emergency.created`) when an active template exists for
     * it — that's how the panic alert gets its rich template — and fall back
     * to the generic `incident.created` otherwise.
     */
    private function resolveNotificationType(Incident $incident): string
    {
        $typeCode = $incident->type?->code;

        if ($typeCode === null) {
            return 'incident.created';
        }

        $specific = "incident.{$typeCode}.created";

        $hasTemplate = NotificationTemplate::withoutGlobalScopes()
            ->where(function ($query) use ($incident) {
                $query->where('team_id', $incident->team_id)
                    ->orWhereNull('team_id');
            })
            ->where('event_type', $specific)
            ->where('is_active', true)
            ->exists();

        return $hasTemplate ? $specific : 'incident.created';
    }

    private function contextSnapshot(Incident $incident): ?EventContextSnapshot
    {
        if ($incident->related_event_id === null) {
            return null;
        }

        return EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $incident->related_event_id)
            ->first();
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, address: string|null}|null
     */
    private function location(Incident $incident, ?EventContextSnapshot $context): ?array
    {
        $snapshot = $context?->location_snapshot_json;

        $latitude = isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null;
        $longitude = isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null;

        $address = $incident->asset?->latestLocation?->formatted_location;

        if ($latitude === null && $longitude === null && $address === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address,
        ];
    }

    private function incidentUrl(Incident $incident): ?string
    {
        $slug = $incident->team?->slug;

        return $slug !== null ? url("/{$slug}/incidents/{$incident->id}") : null;
    }

    private function hasMedia(?EventContextSnapshot $context): bool
    {
        $media = $context?->media_snapshot_json;

        return is_array($media) && ($media['items'] ?? $media) !== [];
    }

    private function mapPriority(?string $severity): NotificationPriority
    {
        return match ($severity) {
            'critical' => NotificationPriority::Critical,
            'high' => NotificationPriority::High,
            'low' => NotificationPriority::Low,
            default => NotificationPriority::Normal,
        };
    }
}
