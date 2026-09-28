<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Actions\MarkNotificationRead;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
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
            ->with([
                'reads' => fn ($query) => $query->where('user_id', $user->id),
                'deliveries.channel',
            ])
            ->withCount(['recipients', 'deliveries'])
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
            'summary' => fn () => $this->summary($user->id),
        ]);
    }

    /**
     * Tenant pulse for the header strip: what this user has not read yet,
     * what went out in the last 24 h, what did not (failed or cancelled) and
     * how many critical notices were raised. Ignores the active filters on
     * purpose; tenant scope comes from the BelongsToTenant global scope.
     *
     * @return array{unread: int, sent24h: int, undelivered24h: int, critical24h: int}
     */
    private function summary(int $userId): array
    {
        $since = now()->subDay();

        return [
            'unread' => Notification::query()
                ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
                ->count(),
            'sent24h' => Notification::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', [NotificationStatus::Sent, NotificationStatus::PartiallySent])
                ->count(),
            'undelivered24h' => Notification::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', [NotificationStatus::Failed, NotificationStatus::Cancelled])
                ->count(),
            'critical24h' => Notification::query()
                ->where('created_at', '>=', $since)
                ->where('priority', NotificationPriority::Critical)
                ->count(),
        ];
    }

    /**
     * One chip per channel the notification went through, carrying the worst
     * delivery status seen on that channel (a failed SMS to one recipient
     * outranks the two that were delivered) so the row tells at a glance
     * which channel needs attention.
     *
     * @return list<array{type: string, status: string, count: int}>
     */
    private function channels(Notification $notification): array
    {
        $rank = [
            DeliveryStatus::Failed->value => 6,
            DeliveryStatus::Bounced->value => 5,
            DeliveryStatus::Retrying->value => 4,
            DeliveryStatus::Skipped->value => 3,
            DeliveryStatus::Cancelled->value => 3,
            DeliveryStatus::Pending->value => 2,
            DeliveryStatus::Queued->value => 2,
            DeliveryStatus::Sending->value => 2,
            DeliveryStatus::Delivered->value => 1,
        ];

        $channels = [];

        foreach ($notification->deliveries as $delivery) {
            $type = $delivery->channel?->channel_type;
            $type = $type instanceof ChannelType ? $type->value : (is_string($type) ? $type : null);

            if ($type === null) {
                continue;
            }

            $status = $delivery->status instanceof DeliveryStatus
                ? $delivery->status->value
                : (string) $delivery->status;

            if (! isset($channels[$type])) {
                $channels[$type] = ['type' => $type, 'status' => $status, 'count' => 0];
            }

            $channels[$type]['count']++;

            if (($rank[$status] ?? 0) > ($rank[$channels[$type]['status']] ?? 0)) {
                $channels[$type]['status'] = $status;
            }
        }

        return array_values($channels);
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
            'statusReason' => $this->statusReason($notification),
            'recipientsCount' => (int) $notification->recipients_count,
            'channels' => $this->channels($notification),
        ];
    }

    /**
     * Human explanation for notifications that never went out. The dispatcher
     * marks a notification "cancelled" in two cases it does not persist a
     * reason for, but both are derivable from what it DID persist: it bails
     * before creating recipients when nobody resolves, and it bails before
     * creating deliveries when no channel is available/allowed.
     */
    private function statusReason(Notification $notification): ?string
    {
        if ($notification->status !== NotificationStatus::Cancelled) {
            return null;
        }

        if ((int) $notification->recipients_count === 0) {
            return 'No se envió: ninguna persona del equipo tenía datos de contacto al momento de generarse.';
        }

        if ((int) $notification->deliveries_count === 0) {
            return 'No se envió: no había ningún canal de notificación activo o permitido para este aviso.';
        }

        // Past this point deliveries_count > 0, so "no non-skipped delivery
        // exists" means every channel was skipped for lack of a destination.
        if (! $notification->deliveries()
            ->where('status', '!=', DeliveryStatus::Skipped)
            ->exists()) {
            return 'No se envió: faltan datos de contacto (teléfono o email) para los canales seleccionados.';
        }

        return 'No se envió: el sistema la descartó antes de salir por algún canal.';
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
