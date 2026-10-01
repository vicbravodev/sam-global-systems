<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Support\CopilotCatalog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Same boot data as the Copilot page, as JSON, for the floating bubble that
 * lives on every other page.
 */
class CopilotCatalogController extends Controller
{
    public function __invoke(Team $current_team, CopilotCatalog $catalog, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('viewAny', CopilotConversation::class);

        return response()->json($catalog->forUser($current_team, $user));
    }
}
