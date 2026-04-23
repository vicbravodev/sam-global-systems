<?php

namespace App\Http\Controllers\Context;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventContextController extends Controller
{
    public function show(Team $current_team, NormalizedEvent $normalizedEvent): JsonResponse
    {
        $snapshot = EventContextSnapshot::where('normalized_event_id', $normalizedEvent->id)->first();

        if ($snapshot === null) {
            throw new NotFoundHttpException('Event context snapshot not found.');
        }

        $this->authorize('view', $snapshot);

        $snapshot->load(['profile', 'geofenceMatches.geofence', 'recentHistory']);

        return response()->json(['data' => $snapshot]);
    }
}
