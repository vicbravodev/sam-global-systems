<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Actions\SendCopilotMessage;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Queries\CopilotQuotaQuery;
use App\Domains\Copilot\Support\CopilotMessagePresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Copilot\StoreCopilotMessageRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CopilotMessageController extends Controller
{
    public function store(
        StoreCopilotMessageRequest $request,
        Team $current_team,
        SendCopilotMessage $send,
        AuthorizeAction $authorizeAction,
        CopilotQuotaQuery $quota,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->authorize('create', CopilotConversation::class);

        $conversation = null;

        if ($request->filled('conversation_id')) {
            $conversation = CopilotConversation::query()
                ->where('team_id', $current_team->id)
                ->findOrFail($request->integer('conversation_id'));

            $this->authorize('update', $conversation);
        }

        $result = $send->execute(
            team: $current_team,
            user: $user,
            permissions: array_values($authorizeAction->resolvePermissions($user, $current_team)),
            content: trim((string) $request->string('content')),
            conversation: $conversation,
            hints: [
                'asset_id' => $request->filled('asset_id') ? $request->integer('asset_id') : null,
                'intent' => $request->input('intent'),
            ],
            channel: (string) ($request->input('channel') ?? 'page'),
        );

        return response()->json([
            'conversation' => CopilotMessagePresenter::conversation($result['conversation']),
            'question' => CopilotMessagePresenter::message($result['question']),
            'answer' => CopilotMessagePresenter::message($result['answer']),
            'quota' => $quota->forTeam($current_team->id),
        ], 201);
    }

    public function feedback(Request $request, Team $current_team, CopilotMessage $message): JsonResponse
    {
        abort_if($message->team_id !== $current_team->id || $message->role !== CopilotMessageRole::Assistant, 404);

        $this->authorize('update', $message->conversation);

        $data = $request->validate([
            'rating' => ['present', 'nullable', 'integer', 'in:-1,1'],
        ]);

        $message->forceFill(['feedback' => $data['rating']])->save();

        return response()->json(['feedback' => $message->feedback]);
    }
}
