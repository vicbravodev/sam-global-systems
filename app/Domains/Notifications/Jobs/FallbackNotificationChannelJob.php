<?php

namespace App\Domains\Notifications\Jobs;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Actions\AppendReplyInstructions;
use App\Domains\Notifications\Actions\AttemptDelivery;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\ChannelAddress;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Support\JobFailureReporter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Cae al siguiente canal de la política de fallback del tenant cuando una
 * entrega agotó sus reintentos (o falló de forma permanente).
 *
 * - Sólo canales que el tenant puede usar (`usableByTeam`: activos y no
 *   apagados en `tenant_channel_toggles`).
 * - Deduplica por TIPO de canal para el destinatario: si ya hubo una entrega
 *   por SMS, no se abre otra por SMS aunque exista otra fila de canal.
 * - Usa la dirección propia del canal (`addressForChannel`); un tipo sin
 *   dirección válida queda registrado como `Skipped` y se prueba el siguiente.
 * - No hace nada si {@see DeliveryEscalationGuard} lo bloquea (caducada,
 *   incidente atendido, destinatario ya alcanzado).
 *
 * `tries = 1`: si algo lanza tras un envío real, la cola no debe repetirlo.
 */
class FallbackNotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $failedDeliveryId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        TenantNotificationPoliciesResolver $policies,
        RenderNotificationContent $render,
        AppendReplyInstructions $appendReplyInstructions,
        AttemptDelivery $attemptDelivery,
    ): void {
        $primary = NotificationDelivery::withoutGlobalScopes()->find($this->failedDeliveryId);

        if ($primary === null) {
            return;
        }

        // Trabaja dentro del tenant de la entrega. Ver §2.1.
        TenantContext::set($primary->team_id);

        $primary->load(['notification.team', 'recipient', 'channel']);

        if (! $primary->notification || ! $primary->notification->team || ! $primary->recipient || ! $primary->channel) {
            return;
        }

        if (DeliveryEscalationGuard::blockReason($primary) !== null) {
            return;
        }

        // A later delivery for this recipient means this fallback already ran
        // (or another escalation took over): that delivery's own failure
        // chain decides what comes next. Keeps duplicate runs idempotent.
        $alreadyEscalated = NotificationDelivery::query()
            ->where('notification_id', $primary->notification_id)
            ->where('recipient_id', $primary->recipient_id)
            ->where('id', '>', $primary->id)
            ->where('status', '!=', DeliveryStatus::Skipped)
            ->exists();

        if ($alreadyEscalated) {
            return;
        }

        $teamId = (int) $primary->team_id;
        $policy = $policies->resolve($primary->notification->team);
        $usedTypes = $this->channelTypesAlreadyUsed($primary);

        foreach ($policy->fallbackChannels as $type) {
            if (in_array($type, $usedTypes, true)) {
                continue;
            }

            $channel = NotificationChannel::query()
                ->usableByTeam($teamId)
                ->where('channel_type', $type)
                ->orderBy('id')
                ->first();

            if ($channel === null) {
                continue;
            }

            $address = $primary->recipient->addressForChannel($type);
            $invalid = $address === null || $address === ''
                ? "no {$type->value} address (missing phone/email) for recipient"
                : ChannelAddress::invalidReason($type, $address);

            $delivery = $this->createFallbackDelivery($primary, $channel, $invalid);

            if ($delivery === null || $invalid !== null) {
                continue;
            }

            $rendered = $render->execute($primary->notification, $primary->recipient, $type, null, $address);
            $rendered = $appendReplyInstructions->execute($primary->notification, $primary->recipient, $rendered);

            $attemptDelivery->execute(
                $delivery,
                $channel,
                $rendered,
                usageEventKey: "notif_fallback_{$delivery->id}",
            );

            return;
        }
    }

    /**
     * @return list<ChannelType>
     */
    private function channelTypesAlreadyUsed(NotificationDelivery $primary): array
    {
        return NotificationDelivery::query()
            ->with('channel')
            ->where('notification_id', $primary->notification_id)
            ->where('recipient_id', $primary->recipient_id)
            ->get()
            ->map(fn (NotificationDelivery $delivery) => $delivery->channel?->channel_type)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Creates the fallback delivery row (Skipped with a reason when the
     * recipient has no valid address for that channel). Returns null when a
     * concurrent run already created it.
     */
    private function createFallbackDelivery(
        NotificationDelivery $primary,
        NotificationChannel $channel,
        ?string $skipReason,
    ): ?NotificationDelivery {
        try {
            // Savepoint: on PostgreSQL a failed INSERT aborts the enclosing
            // transaction, so without it the catch below could not query.
            return DB::transaction(fn () => NotificationDelivery::query()->create([
                'notification_id' => $primary->notification_id,
                'recipient_id' => $primary->recipient_id,
                'channel_id' => $channel->id,
                'fallback_from_delivery_id' => $primary->id,
                'team_id' => $primary->team_id,
                'status' => $skipReason === null ? DeliveryStatus::Pending : DeliveryStatus::Skipped,
                'attempt_number' => $skipReason === null ? 1 : 0,
                'error_message' => $skipReason,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'delivery_id' => $this->failedDeliveryId,
        ]);
    }
}
