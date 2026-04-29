<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', NotificationChannel::class);

        $channels = NotificationChannel::query()
            ->where(function ($q) use ($current_team) {
                $q->where('team_id', $current_team->id)->orWhereNull('team_id');
            })
            ->orderBy('channel_type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $channels]);
    }

    public function update(Request $request, Team $current_team, NotificationChannel $channel): JsonResponse
    {
        $this->authorize('manage', $channel);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:64'],
            'channel_type' => ['nullable', 'string'],
            'config_json' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'supports_priority' => ['nullable', 'boolean'],
            'supports_template' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['channel_type'])) {
            $type = ChannelType::tryFrom($validated['channel_type']) ?? ChannelType::Email;
            $validated['channel_type'] = $type->value;
        }

        $channel->update($validated);

        return response()->json(['data' => $channel->refresh()]);
    }
}
