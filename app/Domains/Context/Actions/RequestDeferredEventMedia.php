<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;

class RequestDeferredEventMedia
{
    /**
     * Register an outbound request for deferred media (video clip, snapshot,
     * driver/road camera) and queue a `FetchDeferredEventMediaJob` for the
     * `context` queue. Idempotent: if an in-flight request of the same type
     * already exists for the same normalized event, it is reused instead of
     * creating a duplicate.
     */
    public function execute(
        NormalizedEvent $normalizedEvent,
        MediaRequestType $type,
    ): EventMediaRequest {
        $existing = EventMediaRequest::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEvent->id)
            ->where('request_type', $type)
            ->whereIn('status', [
                MediaRequestStatus::Pending,
                MediaRequestStatus::Sent,
                MediaRequestStatus::Processing,
            ])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $request = EventMediaRequest::withoutGlobalScopes()->create([
            'team_id' => $normalizedEvent->team_id,
            'normalized_event_id' => $normalizedEvent->id,
            'provider_id' => $normalizedEvent->provider_id,
            'request_type' => $type,
            'requested_at' => now(),
            'status' => MediaRequestStatus::Pending,
            'expires_at' => now()->addHours(6),
        ]);

        FetchDeferredEventMediaJob::dispatch($request->id);

        return $request;
    }
}
