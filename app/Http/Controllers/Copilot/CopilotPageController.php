<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Queries\CopilotUsageQuery;
use App\Domains\Copilot\Support\CopilotCatalog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CopilotPageController extends Controller
{
    public function index(Request $request, Team $current_team, CopilotCatalog $catalog, #[CurrentUser] User $user): Response
    {
        $this->authorize('viewAny', CopilotConversation::class);

        return Inertia::render('copilot/index', [
            ...$catalog->forUser($current_team, $user),
            'initialConversationId' => $request->integer('c') ?: null,
        ]);
    }

    public function usage(Request $request, Team $current_team, CopilotUsageQuery $usage): Response
    {
        $this->authorize('viewUsage', CopilotConversation::class);

        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;

        return Inertia::render('copilot/usage', [
            'usage' => $usage->forTeam($current_team->id, $days),
        ]);
    }
}
