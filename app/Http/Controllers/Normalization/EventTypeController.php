<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\EventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EventTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $eventTypes = EventType::query()
            ->active()
            ->with(['category', 'defaultSeverity'])
            ->orderBy('category_id')
            ->orderBy('name')
            ->get();

        return response()->json($eventTypes);
    }
}
