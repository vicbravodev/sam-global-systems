<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', NotificationPreference::class);

        $preferences = NotificationPreference::query()
            ->where('team_id', $current_team->id)
            ->where('user_id', $request->user()?->id)
            ->get();

        return response()->json(['data' => $preferences]);
    }

    public function update(Request $request, Team $current_team): JsonResponse
    {
        $validated = $request->validate([
            'notification_type' => ['required', 'string', 'max:128'],
            'allowed_channels' => ['required', 'array'],
            'allowed_channels.*' => ['string'],
            'muted' => ['nullable', 'boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'escalation_fallback' => ['nullable', 'array'],
        ]);

        $userId = $request->user()?->id;

        $preference = NotificationPreference::query()
            ->where('team_id', $current_team->id)
            ->where('user_id', $userId)
            ->where('notification_type', $validated['notification_type'])
            ->first();

        $this->authorize('update', $preference);

        $payload = [
            'team_id' => $current_team->id,
            'user_id' => $userId,
            'notification_type' => $validated['notification_type'],
            'allowed_channels_json' => $validated['allowed_channels'],
            'muted' => $validated['muted'] ?? false,
            'quiet_hours_json' => $validated['quiet_hours'] ?? null,
            'escalation_fallback_json' => $validated['escalation_fallback'] ?? null,
        ];

        if ($preference) {
            $preference->update($payload);
        } else {
            $preference = NotificationPreference::query()->create($payload);
        }

        return response()->json(['data' => $preference]);
    }
}
