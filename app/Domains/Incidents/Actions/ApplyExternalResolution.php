<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApplyExternalResolution
{
    public const string SETTING_KEY = 'panic.auto_close_on_external_resolution';

    public const string MODE_ANNOTATE = 'annotate';

    public const string MODE_CLOSE = 'close';

    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly CloseIncident $closeIncident,
        private readonly TenantConfigResolver $tenantConfigResolver,
    ) {}

    /**
     * Annotate an incident as resolved at the provider (e.g. a Samsara panic
     * alert marked `isResolved`). Always records the signal; closing the
     * incident is opt-in per tenant — a cancelled panic can be coercion, so
     * the default never closes anything.
     *
     * @param  bool  $allowClose  Pass false on incident creation: an event that
     *                            arrives already resolved still opens its incident
     *                            (annotated), regardless of the tenant setting.
     */
    public function execute(Incident $incident, NormalizedEvent $event, bool $allowClose = true): void
    {
        if ($incident->external_resolved_at !== null) {
            return;
        }

        $resolvedAt = $this->resolveExternalResolvedAt($event);

        DB::transaction(function () use ($incident, $event, $resolvedAt) {
            $incident->update(['external_resolved_at' => $resolvedAt]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::ExternallyResolved,
                actorType: TimelineActorType::System,
                title: 'Resolved at source',
                description: 'The provider reported this alert as resolved at the source.',
                payload: [
                    'normalized_event_id' => $event->id,
                    'external_resolved_at' => $resolvedAt->toIso8601String(),
                ],
                occurredAt: $resolvedAt,
            );
        });

        if (! $allowClose || $incident->isTerminal()) {
            return;
        }

        $mode = $this->tenantConfigResolver->resolve(
            (int) $incident->team_id,
            self::SETTING_KEY,
            self::MODE_ANNOTATE,
        );

        if ($mode !== self::MODE_CLOSE) {
            return;
        }

        $this->closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::ResolvedExternally,
            summary: 'Automatically resolved: the provider reported the alert as resolved at the source.',
            resolvedByType: IncidentCreatorType::System,
        );
    }

    private function resolveExternalResolvedAt(NormalizedEvent $event): Carbon
    {
        $raw = $event->payload_normalized_json['external_resolved_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Exception) {
                // Fall through to the event timestamps below.
            }
        }

        return Carbon::instance($event->occurred_at ?? now());
    }
}
