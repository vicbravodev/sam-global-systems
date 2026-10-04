<?php

namespace App\Domains\Context\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Listeners\RequestIncidentMediaOnContextBuilt;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * The automatic, quota-free media pull for an event that can open (or did
 * open) an incident: one sweep-only request whose job lists every clip and
 * still the dashcam uploaded around the event, and escalates to a single paid
 * clip retrieval only for an emergency nothing was uploaded for
 * ({@see FetchDeferredEventMediaJob}).
 *
 * Gated per tenant by `media.auto_request_on_critical` (on in the default
 * config pack). Shared by the context-built trigger (before the AI decides)
 * and the incident-created safety net.
 */
class AutoRequestIncidentMedia
{
    public function __construct(
        private readonly TenantConfigResolver $tenantConfigResolver,
        private readonly RequestDeferredEventMedia $requestDeferredEventMedia,
    ) {}

    /**
     * @param  'context_built'|'incident_created'  $trigger
     * @param  array<string, int>  $logInput  Extra ids for the skip lines (e.g. incident_id).
     */
    public function execute(NormalizedEvent $event, string $trigger, array $logInput = []): ?EventMediaRequest
    {
        $input = ['normalized_event_id' => $event->id, 'trigger' => $trigger, ...$logInput];

        if ($event->asset_id === null) {
            SystemLog::skipped('context.media.auto_request_skipped', reason: 'asset_unresolved', input: $input);

            return null;
        }

        $enabled = filter_var(
            $this->tenantConfigResolver->resolve($event->team_id, RequestIncidentMediaOnContextBuilt::SETTING_KEY, false),
            FILTER_VALIDATE_BOOL,
        );

        if (! $enabled) {
            SystemLog::skipped('context.media.auto_request_skipped', reason: 'setting_disabled', input: [
                ...$input,
                'setting_key' => RequestIncidentMediaOnContextBuilt::SETTING_KEY,
            ]);

            return null;
        }

        // One sweep-only request is enough: the sweep lists every clip and still
        // the dashcam uploaded for the event window, regardless of request type.
        return TenantContext::for($event->team_id, fn (): EventMediaRequest => $this->requestDeferredEventMedia->execute(
            $event,
            MediaRequestType::FetchVideoClip,
            sweepOnly: true,
        ));
    }
}
