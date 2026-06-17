<?php

namespace App\Domains\Context\Listeners;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Actions\RequestDeferredEventMedia;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Auto-pull camera footage for critical events (Roadmap B6-P3). Opt-in per
 * tenant via the `media.auto_request_on_critical` setting (default off), and
 * the underlying action is idempotent, so a context rebuild never re-requests.
 *
 * Panic/safety footage is auto-uploaded by the dashcam and surfaced only via
 * the quota-free uploaded-media listing — never a webhook — so this opens a
 * single *sweep-only* request: {@see FetchDeferredEventMediaJob} polls the
 * uploaded media (clips and stills the camera already pushed) and NEVER places
 * a paid on-demand retrieval. On-demand retrievals stay reserved for the manual
 * "request media" action, which is what media-retrieval is actually for.
 */
class RequestPanicMediaOnContextBuilt
{
    public const string SETTING_KEY = 'media.auto_request_on_critical';

    public function __construct(
        private readonly TenantConfigResolver $tenantConfigResolver,
        private readonly RequestDeferredEventMedia $requestDeferredEventMedia,
    ) {}

    public function handle(EventContextBuilt $event): void
    {
        $snapshot = $event->snapshot;

        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()
            ->with('eventSeverity')
            ->find($snapshot->normalized_event_id);

        if ($normalizedEvent === null || $normalizedEvent->eventSeverity?->code !== 'critical') {
            return;
        }

        $enabled = filter_var(
            $this->tenantConfigResolver->resolve(
                (int) $normalizedEvent->team_id,
                self::SETTING_KEY,
                false,
            ),
            FILTER_VALIDATE_BOOL,
        );

        if (! $enabled) {
            return;
        }

        // One sweep-only request is enough: the sweep lists every clip and still
        // the dashcam uploaded for the event window, regardless of request type.
        $this->requestDeferredEventMedia->execute(
            $normalizedEvent,
            MediaRequestType::FetchVideoClip,
            sweepOnly: true,
        );
    }
}
