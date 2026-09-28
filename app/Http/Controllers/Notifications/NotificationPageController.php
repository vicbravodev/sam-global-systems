<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Actions\MarkNotificationRead;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\DeliveryFeedbackPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        $failuresOnly = $request->boolean('failures');

        $notifications = Notification::query()
            ->with([
                'reads' => fn ($query) => $query->where('user_id', $user->id),
                'deliveries.channel',
            ])
            ->withCount($this->deliveryCounts())
            ->withExists(['recipients as addressed_to_me' => fn ($query) => $this->addressedTo($query, $user->id)])
            ->when($failuresOnly, fn ($query) => $this->undelivered($query))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($priority, fn ($query) => $query->where('priority', $priority))
            ->when($unreadOnly, fn ($query) => $this->unreadBy($query, $user->id))
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
                'failures' => $failuresOnly,
            ],
            'filterOptions' => fn () => [
                'statuses' => $this->statusOptions(),
                'priorities' => $this->priorityOptions(),
            ],
            'summary' => fn () => $this->summary($user->id),
        ]);
    }

    /**
     * Delivery detail of one notification: who was contacted, through which
     * channel, what the provider reported (delivered, read, answered call,
     * failure reason) and the retry → fallback chain. Provider cost is never
     * exposed to the tenant here.
     */
    public function show(Request $request, Team $current_team, Notification $notification): Response
    {
        $this->authorize('view', $notification);

        $notification->loadCount($this->deliveryCounts());
        $notification->loadExists(['recipients as addressed_to_me' => fn ($query) => $this->addressedTo($query, $request->user()->id)]);
        $notification->load([
            'reads' => fn ($query) => $query->where('user_id', $request->user()->id),
            'deliveries.channel',
        ]);

        $deliveries = NotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->with(['recipient', 'channel'])
            ->orderBy('recipient_id')
            ->orderBy('id')
            ->get();

        $charges = MessagingCharge::query()
            ->where('source_type', MessagingChargeSource::NotificationDelivery)
            ->whereIn('source_id', $deliveries->pluck('id'))
            ->orderBy('id')
            ->get(['source_id', 'provider_sid', 'events_json'])
            ->groupBy('source_id');

        $fallbackSources = $this->fallbackSources($deliveries);

        $rows = $deliveries->map(function (NotificationDelivery $delivery) use ($charges, $fallbackSources): array {
            $channelType = $delivery->channel?->channel_type;
            $recipientKey = (int) $delivery->recipient_id;
            $fallbackFrom = $fallbackSources[$delivery->id] ?? null;

            $events = ($charges->get($delivery->id) ?? collect())
                ->flatMap(fn (MessagingCharge $charge) => collect($charge->events_json ?? [])
                    ->map(fn (array $event) => [
                        'status' => (string) ($event['status'] ?? ''),
                        'label' => DeliveryFeedbackPresenter::providerEventLabel((string) ($event['status'] ?? '')),
                        'errorCode' => $event['error_code'] ?? null,
                        'at' => $event['at'] ?? null,
                        'source' => $event['source'] ?? null,
                    ]))
                ->values()
                ->all();

            return [
                'id' => (int) $delivery->id,
                'recipient' => [
                    'id' => $recipientKey,
                    'name' => $delivery->recipient?->name,
                    'type' => $delivery->recipient?->recipient_type?->value,
                ],
                'channel' => [
                    'type' => $channelType?->value,
                    'label' => $channelType?->label(),
                ],
                'address' => $this->deliveryTarget($delivery, $channelType),
                'status' => $delivery->status->value,
                'statusLabel' => DeliveryFeedbackPresenter::label($delivery, $channelType),
                'tone' => DeliveryFeedbackPresenter::tone($delivery),
                'reason' => DeliveryFeedbackPresenter::reason($delivery),
                'attempts' => (int) $delivery->attempt_number,
                'isFallback' => $fallbackFrom !== null,
                'fallbackFromChannel' => $fallbackFrom?->channel?->channel_type?->label(),
                'callDurationSeconds' => $delivery->call_duration_seconds,
                'acceptedAt' => $delivery->accepted_at?->toIso8601String(),
                'sentAt' => $delivery->sent_at?->toIso8601String(),
                'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
                'readAt' => $delivery->read_at?->toIso8601String(),
                'answeredAt' => $delivery->answered_at?->toIso8601String(),
                'failedAt' => $delivery->failed_at?->toIso8601String(),
                'events' => $events,
            ];
        })->values()->all();

        return Inertia::render('notifications/show', [
            'notification' => $this->presentNotification($notification, $current_team),
            'deliveries' => $rows,
        ]);
    }

    /**
     * Tenant pulse for the header strip: what was addressed to this user and
     * they have not read yet, what went out in the last 24 h, what did not
     * reach someone (a failed/bounced delivery, or the notification failed or
     * was cancelled) and how many critical notices were raised. Ignores the
     * active filters on purpose; tenant scope comes from the BelongsToTenant
     * global scope.
     *
     * @return array{unread: int, sent24h: int, undelivered24h: int, critical24h: int}
     */
    private function summary(int $userId): array
    {
        $since = now()->subDay();

        return [
            'unread' => $this->unreadBy(Notification::query(), $userId)->count(),
            'sent24h' => Notification::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', [NotificationStatus::Sent, NotificationStatus::PartiallySent])
                ->count(),
            'undelivered24h' => $this->undelivered(Notification::query()->where('created_at', '>=', $since))->count(),
            'critical24h' => Notification::query()
                ->where('created_at', '>=', $since)
                ->where('priority', NotificationPriority::Critical)
                ->count(),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function deliveryCounts(): array
    {
        return [
            'recipients',
            'deliveries',
            'deliveries as attempted_deliveries_count' => fn ($query) => $query
                ->whereNotIn('status', [DeliveryStatus::Skipped, DeliveryStatus::Cancelled]),
            'deliveries as delivered_deliveries_count' => fn ($query) => $query
                ->where('status', DeliveryStatus::Delivered),
            'deliveries as failed_deliveries_count' => fn ($query) => $query
                ->whereIn('status', [DeliveryStatus::Failed, DeliveryStatus::Bounced]),
        ];
    }

    /**
     * Recipients that are this user (the dispatcher stores the user id as the
     * reference of a `user` recipient).
     *
     * @param  Builder<NotificationRecipient>|HasMany<NotificationRecipient, Notification>  $query
     * @return Builder<NotificationRecipient>|HasMany<NotificationRecipient, Notification>
     */
    private function addressedTo(Builder|HasMany $query, int $userId): Builder|HasMany
    {
        return $query
            ->where('recipient_type', RecipientType::User)
            ->where('recipient_reference_id', (string) $userId);
    }

    /**
     * Notifications addressed to the user that they have not read. "Sin
     * leer" is personal: a notice sent to another operator is not pending
     * for me just because I never opened it.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private function unreadBy(Builder $query, int $userId): Builder
    {
        return $query
            ->whereHas('recipients', fn ($recipientQuery) => $this->addressedTo($recipientQuery, $userId))
            ->whereDoesntHave('reads', fn ($readQuery) => $readQuery->where('user_id', $userId));
    }

    /**
     * Notifications that did not reach someone: at least one failed/bounced
     * delivery (even if another channel got through), or the notification
     * itself failed or was cancelled. Same predicate for the pulse counter
     * and the "Con fallas de entrega" filter it toggles.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private function undelivered(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('status', [NotificationStatus::Failed, NotificationStatus::Cancelled])
            ->orWhereHas(
                'deliveries',
                fn ($deliveryQuery) => $deliveryQuery->whereIn('status', [DeliveryStatus::Failed, DeliveryStatus::Bounced]),
            ));
    }

    /**
     * Which delivery each fallback row was opened for. Deliveries created by
     * FallbackNotificationChannelJob carry `fallback_from_delivery_id`; for
     * rows written before that link existed, a delivery counts as a fallback
     * only when it was created AFTER the dispatch round of its recipient and
     * no earlier than the failure of an earlier delivery of that recipient on
     * another channel. List order alone proves nothing.
     *
     * @param  Collection<int, NotificationDelivery>  $deliveries
     * @return array<int, NotificationDelivery> delivery id => delivery it fell back from
     */
    private function fallbackSources(Collection $deliveries): array
    {
        $byId = $deliveries->keyBy('id');
        $sources = [];

        foreach ($deliveries->groupBy('recipient_id') as $recipientDeliveries) {
            $dispatchedAt = $recipientDeliveries->min(fn (NotificationDelivery $d) => $d->created_at?->getTimestamp());

            foreach ($recipientDeliveries as $delivery) {
                if ($delivery->fallback_from_delivery_id !== null) {
                    $source = $byId->get($delivery->fallback_from_delivery_id);

                    if ($source !== null) {
                        $sources[$delivery->id] = $source;
                    }

                    continue;
                }

                $createdAt = $delivery->created_at?->getTimestamp();

                if ($createdAt === null || $dispatchedAt === null || $createdAt <= $dispatchedAt) {
                    continue;
                }

                $source = $recipientDeliveries
                    ->filter(fn (NotificationDelivery $earlier) => $earlier->id < $delivery->id
                        && $earlier->failed_at !== null
                        && $earlier->failed_at->getTimestamp() <= $createdAt
                        && $earlier->channel?->channel_type !== $delivery->channel?->channel_type)
                    ->sortByDesc(fn (NotificationDelivery $earlier) => $earlier->failed_at?->getTimestamp())
                    ->first();

                if ($source !== null) {
                    $sources[$delivery->id] = $source;
                }
            }
        }

        return $sources;
    }

    /**
     * Where the delivery went. The web inbox has no external address (the
     * recipient's generic address is an email, which would suggest it was
     * mailed), so it is named for what it is.
     */
    private function deliveryTarget(NotificationDelivery $delivery, ?ChannelType $channelType): ?string
    {
        if ($channelType === ChannelType::Web) {
            return 'Bandeja web de SAM';
        }

        if ($channelType === ChannelType::Push) {
            return 'App móvil de SAM';
        }

        return DeliveryFeedbackPresenter::maskAddress(
            $delivery->payload_json['address'] ?? ($channelType !== null ? $delivery->recipient?->addressForChannel($channelType) : null),
        );
    }

    /**
     * Honest row status from what the deliveries actually did: a notification
     * whose deliveries all failed is not "Enviada" just because it left the
     * dispatcher, and 1 of 3 is partial.
     *
     * @return array{label: string, tone: string}
     */
    private function statusDisplay(Notification $notification): array
    {
        $attempted = (int) ($notification->attempted_deliveries_count ?? 0);
        $delivered = (int) ($notification->delivered_deliveries_count ?? 0);
        $failed = (int) ($notification->failed_deliveries_count ?? 0);

        return match ($notification->status) {
            NotificationStatus::Pending => ['label' => 'Pendiente', 'tone' => 'neutral'],
            NotificationStatus::Queued => ['label' => 'En cola', 'tone' => 'info'],
            NotificationStatus::Cancelled => ['label' => 'Cancelada', 'tone' => 'muted'],
            NotificationStatus::Failed => ['label' => 'Fallida', 'tone' => 'critical'],
            NotificationStatus::Sent, NotificationStatus::PartiallySent => match (true) {
                $attempted === 0 => ['label' => 'Enviada', 'tone' => 'ok'],
                $delivered === $attempted => ['label' => 'Entregada', 'tone' => 'ok'],
                $delivered === 0 && $failed === $attempted => ['label' => 'Sin entregar', 'tone' => 'critical'],
                $delivered === 0 => ['label' => 'Sin confirmar entrega', 'tone' => 'warning'],
                default => ['label' => "Parcial {$delivered}/{$attempted}", 'tone' => 'warning'],
            },
        };
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
            DeliveryStatus::Sent->value => 2,
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
        $display = $this->statusDisplay($notification);

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
            'addressedToMe' => (bool) ($notification->addressed_to_me ?? false),
            'statusLabel' => $display['label'],
            'statusTone' => $display['tone'],
            'statusReason' => $this->statusReason($notification),
            'recipientsCount' => (int) $notification->recipients_count,
            'channels' => $this->channels($notification),
            'deliverySummary' => isset($notification->attempted_deliveries_count) ? [
                'attempted' => (int) $notification->attempted_deliveries_count,
                'delivered' => (int) $notification->delivered_deliveries_count,
                'failed' => (int) $notification->failed_deliveries_count,
            ] : null,
            'detailUrl' => route('notifications.show', [
                'current_team' => $team->slug,
                'notification' => $notification->id,
            ]),
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
