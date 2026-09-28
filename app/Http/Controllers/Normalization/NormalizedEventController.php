<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NormalizedEventController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', NormalizedEvent::class);

        $query = NormalizedEvent::query()
            ->where('team_id', $current_team->id)
            ->with(['eventType', 'eventCategory', 'eventSeverity'])
            ->orderByDesc('occurred_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('event_type_id')) {
            $query->where('event_type_id', $request->input('event_type_id'));
        }

        if ($request->filled('event_category_id')) {
            $query->where('event_category_id', $request->input('event_category_id'));
        }

        if ($request->filled('event_severity_id')) {
            $query->where('event_severity_id', $request->input('event_severity_id'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('occurred_from')) {
            $query->where('occurred_at', '>=', $request->input('occurred_from'));
        }

        if ($request->filled('occurred_until')) {
            $query->where('occurred_at', '<=', $request->input('occurred_until'));
        }

        $events = $query->paginate(PerPage::from($request, 25));

        return response()->json($events);
    }

    public function show(Team $current_team, NormalizedEvent $normalizedEvent): JsonResponse
    {
        $this->authorize('view', $normalizedEvent);

        $normalizedEvent->load([
            'rawEvent',
            'eventType.category',
            'eventCategory',
            'eventSeverity',
            'asset',
            'driver',
            'provider',
        ]);

        return response()->json($normalizedEvent);
    }

    public function unmapped(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', NormalizedEvent::class);

        $events = NormalizedEvent::query()
            ->where('team_id', $current_team->id)
            ->unmapped()
            ->with(['rawEvent', 'provider'])
            ->orderByDesc('occurred_at')
            ->paginate(PerPage::from($request, 25));

        return response()->json($events);
    }
}
