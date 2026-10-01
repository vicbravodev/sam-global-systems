<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Context\Actions\RequestDeferredEventMedia;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\MediaRetrievalWindow;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web endpoint (session + CSRF) used by the incident detail page (Roadmap F9)
 * to ask the provider for camera footage of the incident's source event.
 */
class IncidentMediaRequestController extends Controller
{
    public function store(
        Request $request,
        Team $current_team,
        Incident $incident,
        RequestDeferredEventMedia $action,
    ): JsonResponse {
        $this->authorize('view', $incident);
        $this->authorize('request', EventMediaContext::class);

        abort_if($incident->team_id !== $current_team->id, 404);
        abort_if($incident->related_event_id === null, 422, 'El incidente no tiene un evento de origen.');

        $payload = $request->validate([
            'request_type' => ['nullable', 'string'],
        ]);

        $type = MediaRequestType::tryFrom((string) ($payload['request_type'] ?? MediaRequestType::FetchVideoClip->value))
            ?? MediaRequestType::FetchVideoClip;

        $event = NormalizedEvent::query()
            ->where('team_id', $incident->team_id)
            ->findOrFail($incident->related_event_id);

        // Footage past the device retention window is gone: refuse up front
        // instead of queueing a request that is guaranteed to fail.
        $expired = MediaRetrievalWindow::expiredReason($incident->team_id, $event->occurred_at);
        abort_if($expired !== null, 422, $expired ?? '');

        $mediaRequest = $action->execute($event, $type);

        return response()->json(['data' => [
            'id' => $mediaRequest->id,
            'status' => $mediaRequest->status?->value,
            'requestType' => $mediaRequest->request_type?->value,
        ]], 202);
    }
}
