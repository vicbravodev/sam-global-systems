<?php

namespace App\Http\Controllers\Automation;

use App\Domains\Automation\Models\ActionTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreActionTemplateRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionTemplateController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', ActionTemplate::class);

        $query = ActionTemplate::query()->availableToTeam($current_team->id);

        if ($request->filled('action_type')) {
            $query->where('action_type', (string) $request->input('action_type'));
        }

        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }

        $templates = $query->orderBy('id')->paginate($request->integer('per_page', 25));

        return response()->json($templates);
    }

    public function store(StoreActionTemplateRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', ActionTemplate::class);

        $payload = $request->validated();
        $payload['team_id'] = $current_team->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $template = ActionTemplate::create($payload);

        return response()->json(['data' => $template], 201);
    }
}
