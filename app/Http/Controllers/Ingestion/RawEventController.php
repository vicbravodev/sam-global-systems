<?php

namespace App\Http\Controllers\Ingestion;

use App\Domains\Ingestion\Models\RawEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RawEventController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', RawEvent::class);

        $query = RawEvent::query()
            ->where('team_id', $current_team->id)
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

        $rawEvents = $query->paginate(PerPage::from($request, 25));

        return response()->json($rawEvents);
    }
}
