<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NormalizedEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NormalizedEvent::query()
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

        $events = $query->paginate($request->integer('per_page', 25));

        return response()->json($events);
    }

    public function show(NormalizedEvent $normalizedEvent): JsonResponse
    {
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

    public function unmapped(Request $request): JsonResponse
    {
        $events = NormalizedEvent::query()
            ->unmapped()
            ->with(['rawEvent', 'provider'])
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($events);
    }
}
