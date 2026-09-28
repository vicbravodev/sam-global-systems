<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Support\CopilotCatalog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same boot data as the Copilot page, as JSON, for the floating bubble that
 * lives on every other page.
 */
class CopilotCatalogController extends Controller
{
    public function __invoke(Request $request, Team $current_team, CopilotCatalog $catalog): JsonResponse
    {
        $this->authorize('viewAny', CopilotConversation::class);

        return response()->json($catalog->forUser($current_team, $request->user()));
    }
}
