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

        if (! (bool) ($snapshot->asset_snapshot_json['has_camera'] ?? false)) {
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
