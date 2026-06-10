<?php

namespace App\Http\Controllers\Context;

use App\Domains\Context\Actions\RequestDeferredEventMedia;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventMediaController extends Controller
{
    public function index(Team $current_team, NormalizedEvent $normalizedEvent): JsonResponse
    {
        $this->authorize('viewAny', EventMediaContext::class);

        if ($normalizedEvent->team_id !== $current_team->id) {
            throw new NotFoundHttpException('Event not found.');
        }

        $media = EventMediaContext::where('normalized_event_id', $normalizedEvent->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $media]);
    }

    public function requestMedia(
        Request $request,
        Team $current_team,
        NormalizedEvent $normalizedEvent,
        RequestDeferredEventMedia $action,
    ): JsonResponse {
        $this->authorize('request', EventMediaContext::class);

        if ($normalizedEvent->team_id !== $current_team->id) {
            throw new NotFoundHttpException('Event not found.');
        }

        $payload = $request->validate([
            'request_type' => ['required', 'string'],
        ]);

        $type = MediaRequestType::tryFrom($payload['request_type']);

        if ($type === null) {
            return response()->json([
                'message' => 'Tipo de solicitud no soportado.',
                'supported' => array_map(fn (MediaRequestType $t) => $t->value, MediaRequestType::cases()),
            ], 422);
        }

        $mediaRequest = $action->execute($normalizedEvent, $type);

        return response()->json(['data' => $mediaRequest], 202);
    }
}
