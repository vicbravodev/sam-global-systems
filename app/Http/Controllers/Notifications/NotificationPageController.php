<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Actions\MarkNotificationRead;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPageController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * Render the tenant notification center: every outbound notification of
     * the team, newest first, with per-user read markers.
     */
    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Notification::class);

        $user = $request->user();

        $status = NotificationStatus::tryFrom((string) $request->query('status'));
        $priority = NotificationPriority::tryFrom((string) $request->query('priority'));
        $unreadOnly = $request->boolean('unread');

        $notifications = Notification::query()
            ->with(['reads' => fn ($query) => $query->where('user_id', $user->id)])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($priority, fn ($query) => $query->where('priority', $priority))
            ->when($unreadOnly, fn ($query) => $query->whereDoesntHave(
                'reads',
                fn ($readQuery) => $readQuery->where('user_id', $user->id),
            ))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('notifications/index', [
            'notifications' => collect($notifications->items())
                ->map(fn (Notification $notification) => $this->presentNotification($notification, $current_team))
                ->all(),
            'pagination' => [
                'page' => $notifications->currentPage(),
                'perPage' => $notifications->perPage(),
                'total' => $notifications->total(),
                'lastPage' => $notifications->lastPage(),
            ],
            'filters' => [
                'status' => $status?->value,
                'priority' => $priority?->value,
                'unread' => $unreadOnly,
            ],
            'filterOptions' => fn () => [
                'statuses' => $this->statusOptions(),
                'priorities' => $this->priorityOptions(),
            ],
        ]);
    }

    /**
     * Mark a notification as read for the authenticated user. Idempotent.
     */
    public function read(
        Request $request,
        Team $current_team,
        Notification $notification,
        MarkNotificationRead $markNotificationRead,
    ): RedirectResponse {
        $this->authorize('view', $notification);

        $markNotificationRead->execute($notification, $request->user());

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentNotification(Notification $notification, Team $team): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => (string) $notification->notification_type,
            'priority' => $notification->priority->value,
            'status' => $notification->status->value,
            'subject' => $notification->subject,
            'bodyPreview' => $notification->body_preview,
            'sourceType' => $notification->source_type->value,
            'sourceUrl' => $this->sourceUrl($notification, $team),
            'sentAt' => $notification->sent_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
            'isRead' => $notification->reads->isNotEmpty(),
        ];
    }

    /**
     * Link back to the source record when the UI has a page for it. Today
     * that is only the incident detail; other source types have no page yet.
     */
    private function sourceUrl(Notification $notification, Team $team): ?string
    {
        if (
            $notification->source_type === NotificationSourceType::Incident
            && $notification->source_reference_id !== null
            && ctype_digit((string) $notification->source_reference_id)
        ) {
            return route('incidents.show', [
                'current_team' => $team->slug,
                'incident' => (int) $notification->source_reference_id,
            ]);
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statusOptions(): array
    {
        return array_map(
            fn (NotificationStatus $status) => [
                'value' => $status->value,
                'label' => match ($status) {
                    NotificationStatus::Pending => 'Pendiente',
                    NotificationStatus::Queued => 'En cola',
                    NotificationStatus::PartiallySent => 'Parcialmente enviada',
                    NotificationStatus::Sent => 'Enviada',
                    NotificationStatus::Failed => 'Fallida',
                    NotificationStatus::Cancelled => 'Cancelada',
                },
            ],
            NotificationStatus::cases(),
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function priorityOptions(): array
    {
        return array_map(
            fn (NotificationPriority $priority) => [
                'value' => $priority->value,
                'label' => match ($priority) {
                    NotificationPriority::Low => 'Baja',
                    NotificationPriority::Normal => 'Normal',
                    NotificationPriority::High => 'Alta',
                    NotificationPriority::Critical => 'Crítica',
                },
            ],
            NotificationPriority::cases(),
        );
    }
}
