<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Actions\StreamCopilotTurn;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Copilot\StoreCopilotMessageRequest;
use App\Models\Team;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same request, policy and conversation ownership as messages.store, but
 * the answer streams (Vercel data stream protocol). Policy, 404 and
 * validation errors are plain responses sent before any stream opens.
 */
class CopilotStreamController extends Controller
{
    public function __invoke(
        StoreCopilotMessageRequest $request,
        Team $current_team,
        StreamCopilotTurn $stream,
        AuthorizeAction $authorizeAction,
    ): Response {
        $this->authorize('create', CopilotConversation::class);

        $conversation = null;

        if ($request->filled('conversation_id')) {
            $conversation = CopilotConversation::query()
                ->where('team_id', $current_team->id)
                ->findOrFail($request->integer('conversation_id'));

            $this->authorize('update', $conversation);
        }

        $user = $request->user();

        return $stream->execute(
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
    }
}
