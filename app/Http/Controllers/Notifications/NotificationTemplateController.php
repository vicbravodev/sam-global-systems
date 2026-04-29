<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        $templates = NotificationTemplate::query()
            ->where(function ($q) use ($current_team) {
                $q->where('team_id', $current_team->id)->orWhereNull('team_id');
            })
            ->orderBy('channel_type')
            ->orderBy('event_type')
            ->paginate($request->integer('per_page', 25));

        return response()->json($templates);
    }

    public function store(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('manage', NotificationTemplate::class);

        $validated = $this->validatePayload($request);

        $template = NotificationTemplate::query()->create([
            'team_id' => $current_team->id,
            ...$validated,
        ]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, Team $current_team, NotificationTemplate $template): JsonResponse
    {
        $this->authorize('manage', $template);

        $validated = $this->validatePayload($request);

        $template->update($validated);

        return response()->json(['data' => $template->refresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'channel_type' => ['required', 'string'],
            'event_type' => ['nullable', 'string', 'max:128'],
            'priority' => ['nullable', 'string', 'max:32'],
            'subject_template' => ['nullable', 'string', 'max:255'],
            'body_template' => ['required', 'string'],
            'variables_schema_json' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type = ChannelType::tryFrom($validated['channel_type']) ?? ChannelType::Email;
        $validated['channel_type'] = $type->value;
        $validated['is_active'] = $validated['is_active'] ?? true;

        return $validated;
    }
}
