<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    /**
     * Base catalogue of notification types every tenant emits. Merged with
     * the types actually seen in the team's notification log so users can
     * also tune custom per-incident-type notifications.
     */
    private const BASE_TYPES = [
        'incident.created',
        'incident.sla_breached',
        'incident.assigned.on_call',
    ];

    /**
     * Show the user's notification preferences for their current team.
     */
    public function edit(Request $request): Response
    {
        $this->authorize('viewAny', NotificationPreference::class);

        $user = $request->user();
        $team = currentTeam();

        $preferences = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->orderBy('notification_type')
            ->get();

        return Inertia::render('settings/notifications', [
            'preferences' => $preferences
                ->map(fn (NotificationPreference $preference) => [
                    'id' => (int) $preference->id,
                    'notificationType' => (string) $preference->notification_type,
                    'allowedChannels' => array_values(array_filter(
                        $preference->allowed_channels_json ?? [],
                        fn ($value) => is_string($value),
                    )),
                    'muted' => (bool) $preference->muted,
                ])
                ->all(),
            'knownTypes' => $this->knownTypes($preferences->pluck('notification_type')->all()),
            'channelOptions' => array_map(
                fn (ChannelType $type) => [
                    'value' => $type->value,
                    'label' => ucfirst($type->value),
                ],
                ChannelType::cases(),
            ),
            'teamName' => $team?->name,
        ]);
    }

    /**
     * Upsert one preference (per notification type) for the authenticated
     * user in their current team. Idempotent: repeating the same payload
     * keeps a single row per (user, type).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notification_type' => ['required', 'string', 'max:128'],
            'allowed_channels' => ['required', 'array'],
            'allowed_channels.*' => ['string', Rule::enum(ChannelType::class)],
            'muted' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('notification_type', $validated['notification_type'])
            ->first();

        $this->authorize('update', $preference ?? NotificationPreference::class);

        $payload = [
            'user_id' => $user->id,
            'notification_type' => $validated['notification_type'],
            'allowed_channels_json' => array_values($validated['allowed_channels']),
            'muted' => (bool) ($validated['muted'] ?? false),
        ];

        if ($preference) {
            $preference->update($payload);
        } else {
            NotificationPreference::query()->create($payload);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preferencias guardadas.']);

        return to_route('notification-preferences.edit');
    }

    /**
     * Distinct notification types observed in the team plus the base
     * catalogue and the user's already-configured types.
     *
     * @param  array<int, string>  $configured
     * @return array<int, string>
     */
    private function knownTypes(array $configured): array
    {
        $observed = Notification::query()
            ->select('notification_type')
            ->distinct()
            ->orderBy('notification_type')
            ->pluck('notification_type')
            ->all();

        $types = array_values(array_unique([
            ...self::BASE_TYPES,
            ...$observed,
            ...$configured,
        ]));

        sort($types);

        return $types;
    }
}
