<?php

namespace App\Domains\Context\Listeners;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Actions\RequestDeferredEventMedia;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Auto-request camera footage for critical events on camera-equipped assets
 * (Roadmap B6-P3). Opt-in per tenant via the `media.auto_request_on_critical`
 * setting (default off — retrievals consume provider quota and cost), and the
 * underlying action is idempotent, so a context rebuild never double-requests.
 *
 * Roadmap V2-A1: alongside the clip, still images spread across the tenant's
 * wider `media.still_window_minutes` window are requested too (skipped when
 * `media.still_count` is 0), so the AI sees the minutes around the event.
 *
 * Assets that report no paired dashcam still get a single clip request: the
 * provider's camera flag can be stale, and the request drives the quota-free
 * uploaded-media sweep — {@see FetchDeferredEventMediaJob} skips the actual
 * retrievals for those assets, so no rejected calls are placed.
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

        $this->requestDeferredEventMedia->execute($normalizedEvent, MediaRequestType::FetchVideoClip);

        // Without a camera the stills retrievals would never be placed: the
        // single clip request above already drives the uploaded-media sweep.
        if (! (bool) ($snapshot->asset_snapshot_json['has_camera'] ?? false)) {
            return;
        }

        $stillCount = (int) $this->tenantConfigResolver->resolve(
            (int) $normalizedEvent->team_id,
            FetchDeferredEventMediaJob::SETTING_STILL_COUNT,
            FetchDeferredEventMediaJob::DEFAULT_STILL_COUNT,
        );

        if ($stillCount > 0) {
            $this->requestDeferredEventMedia->execute($normalizedEvent, MediaRequestType::FetchSnapshot);
        }
    }
}
