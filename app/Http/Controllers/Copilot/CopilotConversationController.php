<?php

namespace App\Http\Controllers\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotMessagePresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CopilotConversationController extends Controller
{
    public function show(Team $current_team, CopilotConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $messages = CopilotMessage::query()
            ->where('team_id', $current_team->id)
            ->where('copilot_conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'conversation' => CopilotMessagePresenter::conversation($conversation),
            'messages' => $messages->map(fn (CopilotMessage $m) => CopilotMessagePresenter::stored($m))->all(),
        ]);
    }

    public function update(Request $request, Team $current_team, CopilotConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $conversation->fill($data)->save();

        return response()->json(['conversation' => CopilotMessagePresenter::conversation($conversation)]);
    }

    public function destroy(Team $current_team, CopilotConversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }
}
