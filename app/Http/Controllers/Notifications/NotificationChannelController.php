<?php

namespace App\Http\Controllers\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('manage', NotificationChannel::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:64'],
            'channel_type' => ['required', Rule::enum(ChannelType::class)],
            'config_json' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel = NotificationChannel::query()->create([
            'team_id' => $current_team->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'channel_type' => ChannelType::from($validated['channel_type']),
            'config_json' => $validated['config_json'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'supports_priority' => false,
            'supports_template' => true,
        ]);

        return response()->json(['data' => $channel], 201);
    }

    public function destroy(Team $current_team, NotificationChannel $channel): JsonResponse
    {
        $this->authorize('manage', $channel);

        abort_if($channel->team_id === null, 422, 'Los canales globales no se pueden eliminar.');

        $channel->delete();

        return response()->json(null, 204);
    }

    /**
     * Send a throwaway test message through the channel's real driver
     * (Roadmap F5c) so the operator validates credentials before relying on
     * the channel for incidents.
     */
    public function test(
        Request $request,
        Team $current_team,
        NotificationChannel $channel,
        ChannelDriverRegistry $drivers,
    ): JsonResponse {
        $this->authorize('manage', $channel);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:255'],
        ]);

        $rendered = new RenderedNotification(
            channelType: $channel->channel_type,
            address: $validated['address'],
            subject: 'Prueba de canal — SAM',
            body: "Mensaje de prueba del canal {$channel->name} ({$channel->channel_type->value}). Si lo recibes, el canal está bien configurado.",
        );

        $result = $drivers->driverFor($channel->channel_type)->send($rendered, $channel);

        return response()->json([
            'data' => [
                'success' => $result->success,
                'providerMessageId' => $result->providerMessageId,
                'error' => $result->errorMessage,
            ],
        ], $result->success ? 200 : 422);
    }
}
