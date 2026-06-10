<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;

class RequestDeferredEventMedia
{
    public const string USAGE_METER_CODE = 'media_requests';

    public function __construct(
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

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

        $this->recordUsage($request);

        FetchDeferredEventMediaJob::dispatch($request->id);

        return $request;
    }

    private function recordUsage(EventMediaRequest $request): void
    {
        if (! UsageMeter::where('code', self::USAGE_METER_CODE)->exists()) {
            return;
        }

        $this->recordUsageEvent->execute(
            teamId: (int) $request->team_id,
            meterCode: self::USAGE_METER_CODE,
            quantity: 1,
            eventKey: "media_request:{$request->id}",
            metadata: [
                'event_media_request_id' => $request->id,
                'normalized_event_id' => $request->normalized_event_id,
                'request_type' => $request->request_type->value,
            ],
            occurredAt: $request->requested_at,
        );
    }
}
