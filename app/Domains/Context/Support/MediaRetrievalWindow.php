<?php

namespace App\Domains\Context\Support;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use Carbon\CarbonInterface;

/**
 * Device footage retention window for on-demand media retrieval.
 *
 * Dashcams overwrite their SD recording within days, so past
 * `media.retrieval_max_age_hours` (default 72 h, the same setting
 * FetchDeferredEventMediaJob enforces) a retrieval is guaranteed to fail.
 * The UI uses this to stop offering "Solicitar media" for those events.
 */
final class MediaRetrievalWindow
{
    public static function maxAgeHours(int $teamId): int
    {
        return max(1, (int) app(TenantConfigResolver::class)->resolve(
            $teamId,
            FetchDeferredEventMediaJob::SETTING_RETRIEVAL_MAX_AGE,
            FetchDeferredEventMediaJob::DEFAULT_RETRIEVAL_MAX_AGE_HOURS,
        ));
    }

    /**
     * Spanish reason when the footage of an event that happened at
     * `$occurredAt` is surely gone; null while it can still be retrieved.
     */
    public static function expiredReason(int $teamId, ?CarbonInterface $occurredAt): ?string
    {
        if ($occurredAt === null) {
            return null;
        }

        $maxAgeHours = self::maxAgeHours($teamId);

        if ($occurredAt->gte(now()->subHours($maxAgeHours))) {
            return null;
        }

        return sprintf(
            'El video del dispositivo ya no está disponible: el evento tiene más de %d h y la cámara sobrescribe su grabación.',
            $maxAgeHours,
        );
    }
}
