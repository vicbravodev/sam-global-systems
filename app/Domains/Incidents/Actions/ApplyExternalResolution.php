<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\LoggableCode;
use App\Support\SystemLog;
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
        $logInput = ['incident_id' => $incident->id, 'normalized_event_id' => $event->id];

        if ($incident->external_resolved_at !== null) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.external_resolution.applied', reason: 'already_annotated', input: $logInput));

            return;
        }

        ['at' => $resolvedAt, 'source' => $resolvedAtSource] = $this->resolveExternalResolvedAt($event);

        DB::transaction(function () use ($incident, $event, $resolvedAt) {
            $incident->update(['external_resolved_at' => $resolvedAt]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::ExternallyResolved,
                actorType: TimelineActorType::System,
                title: 'Resuelto en el origen',
                description: 'El proveedor reportó esta alerta como resuelta en el origen.',
                payload: [
                    'normalized_event_id' => $event->id,
                    'external_resolved_at' => $resolvedAt->toIso8601String(),
                ],
                occurredAt: $resolvedAt,
            );
        });

        $wasTerminal = $incident->isTerminal();
        $modeForLog = null;
        $closed = false;

        if ($allowClose && ! $wasTerminal) {
            $mode = $this->tenantConfigResolver->resolve(
                $incident->team_id,
                self::SETTING_KEY,
                self::MODE_ANNOTATE,
            );
            $modeForLog = is_string($mode) ? LoggableCode::guard($mode) : null;

            if ($mode === self::MODE_CLOSE) {
                $this->closeIncident->execute(
                    incident: $incident,
                    resolutionCode: ResolutionCode::ResolvedExternally,
                    summary: 'Automatically resolved: the provider reported the alert as resolved at the source.',
                    resolvedByType: IncidentCreatorType::System,
                );
                $closed = true;
            }
        }

        $appliedLine = [
            'input' => $logInput,
            'calc' => [
                'resolved_at_source' => $resolvedAtSource,
                'allow_close' => $allowClose,
                'was_terminal' => $wasTerminal,
                'mode' => $modeForLog,
            ],
            'result' => ['external_resolved_at' => $resolvedAt->toIso8601String(), 'closed' => $closed],
        ];
        DB::afterCommit(fn () => SystemLog::ok('incidents.external_resolution.applied', ...$appliedLine));
    }

    /**
     * @return array{at: Carbon, source: 'payload'|'event_occurred_at'}
     */
    private function resolveExternalResolvedAt(NormalizedEvent $event): array
    {
        $raw = $event->payload_normalized_json['external_resolved_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return ['at' => Carbon::parse($raw), 'source' => 'payload'];
            } catch (\Exception) {
                // Fall through to the event timestamps below.
            }
        }

        return ['at' => Carbon::instance($event->occurred_at ?? now()), 'source' => 'event_occurred_at'];
    }
}
