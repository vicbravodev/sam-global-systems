<?php

namespace App\Http\Controllers\Ingestion;

use App\Domains\Ingestion\Models\RawEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RawEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RawEvent::query()
            ->with('eventSource')
            ->orderByDesc('received_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('event_source_id')) {
            $query->where('event_source_id', $request->input('event_source_id'));
        }

        if ($request->filled('received_from')) {
            $query->where('received_at', '>=', $request->input('received_from'));
        }

        if ($request->filled('received_until')) {
            $query->where('received_at', '<=', $request->input('received_until'));
        }

        $rawEvents = $query->paginate($request->integer('per_page', 25));

        return response()->json($rawEvents);
    }
}
