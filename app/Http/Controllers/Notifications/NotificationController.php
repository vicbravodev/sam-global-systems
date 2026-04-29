<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        $query = Notification::where('team_id', $current_team->id);

        if ($request->filled('status')) {
            $status = NotificationStatus::tryFrom((string) $request->input('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('priority')) {
            $priority = NotificationPriority::tryFrom((string) $request->input('priority'));
            if ($priority) {
                $query->where('priority', $priority);
            }
        }

        if ($request->filled('notification_type')) {
            $query->where('notification_type', $request->input('notification_type'));
        }

        $notifications = $query->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($notifications);
    }

    public function show(Team $current_team, Notification $notification): JsonResponse
    {
        $this->authorize('view', $notification);

        $notification->load(['recipients', 'deliveries.channel', 'template']);

        return response()->json(['data' => $notification]);
    }

    public function send(Request $request, Team $current_team, SendNotification $sendNotification): JsonResponse
    {
        $this->authorize('send', Notification::class);

        $validated = $request->validate([
            'notification_type' => ['required', 'string', 'max:128'],
            'priority' => ['required', 'string'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_preview' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'event_key' => ['nullable', 'string', 'max:128'],
            'recipients' => ['nullable', 'array'],
        ]);

        $priority = NotificationPriority::tryFrom($validated['priority']) ?? NotificationPriority::Normal;

        $payload = $validated['payload'] ?? [];

        if (isset($validated['recipients'])) {
            $payload['recipients'] = $validated['recipients'];
        }

        $notification = $sendNotification->execute(
            teamId: $current_team->id,
            notificationType: $validated['notification_type'],
            sourceType: NotificationSourceType::Manual,
            sourceReferenceId: null,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::User,
            triggeredById: $request->user()?->id,
            eventKey: $validated['event_key'] ?? 'manual:'.(string) Str::uuid(),
            payload: $payload,
            subject: $validated['subject'] ?? null,
            bodyPreview: $validated['body_preview'] ?? null,
        );

        return response()->json([
            'message' => 'Notification dispatched',
            'data' => $notification,
        ], 202);
    }
}
