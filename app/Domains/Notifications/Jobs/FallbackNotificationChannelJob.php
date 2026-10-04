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
use App\Domains\Notifications\Models\PushSubscription;
use App\Domains\Notifications\Support\ChannelAddress;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Domains\Notifications\Support\MessagingSuppressions;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
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
        $input = ['failed_delivery_id' => $this->failedDeliveryId];

        if ($primary === null) {
            SystemLog::skipped('notifications.fallback.skipped', reason: 'relations_missing', input: $input);

            return;
        }

        // Trabaja dentro del tenant de la entrega. Ver §2.1.
        TenantContext::set($primary->team_id);

        $primary->load(['notification.team', 'recipient', 'channel']);

        if ($primary->notification === null || $primary->notification->team === null || $primary->recipient === null || $primary->channel === null) {
            SystemLog::skipped('notifications.fallback.skipped', reason: 'relations_missing', input: $input);

            return;
        }

        $guard = DeliveryEscalationGuard::explain($primary);

        if ($guard['reason'] !== null) {
            DeliveryEscalationGuard::logBlocked($primary, $guard, 'fallback_job');

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
            SystemLog::skipped('notifications.fallback.skipped', reason: 'already_escalated', input: $input);

            return;
        }

        $teamId = $primary->team_id;
        $policy = $policies->resolve($primary->notification->team);
        $usedTypes = $this->channelTypesAlreadyUsed($primary);

        // Recorrido de la política para el log: un paso por tipo, sin
        // direcciones ni el texto de `invalidReason`.
        $walk = [];

        foreach ($policy->fallbackChannels as $type) {
            if (in_array($type, $usedTypes, true)) {
                $walk[] = ['channel_type' => $type->value, 'outcome' => 'already_used'];

                continue;
            }

            $channel = NotificationChannel::query()
                ->usableByTeam($teamId)
                ->where('channel_type', $type)
                ->orderBy('id')
                ->first();

            if ($channel === null) {
                $walk[] = ['channel_type' => $type->value, 'outcome' => 'no_usable_channel'];

                continue;
            }

            $address = $primary->recipient->addressForChannel($type);
            $hasAddress = $address !== null && $address !== '';
            $suppressed = $hasAddress ? MessagingSuppressions::reasonFor($type, $address) : null;
            $noPushDevice = $hasAddress && $type === ChannelType::Push && ! PushSubscription::existsFor($teamId, (int) $address);
            $invalid = match (true) {
                ! $hasAddress => "no {$type->value} address (missing phone/email) for recipient",
                $noPushDevice => 'no device subscribed for push',
                $suppressed !== null => "address unavailable for {$type->value}",
                default => ChannelAddress::invalidReason($type, $address),
            };

            $delivery = $this->createFallbackDelivery($primary, $channel, $invalid);

            if ($delivery === null || $invalid !== null) {
                $walk[] = ['channel_type' => $type->value, 'outcome' => match (true) {
                    $delivery === null => 'race_lost',
                    ! $hasAddress => 'no_address',
                    $noPushDevice => 'no_push_device',
                    $suppressed !== null => 'suppressed',
                    default => 'invalid_address',
                }];

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

            $walk[] = ['channel_type' => $type->value, 'outcome' => 'chosen'];

            SystemLog::ok('notifications.fallback.chosen', input: $input, calc: $this->walkCalc($policy->fallbackChannels, $usedTypes, $walk), result: [
                'delivery_id' => $delivery->id,
                'channel_type' => $type->value,
                'channel_id' => $channel->id,
            ]);

            return;
        }

        // Antes el job terminaba sin rastro y el destinatario se quedaba sin
        // aviso: ningún tipo de la política quedó disponible.
        SystemLog::degraded('notifications.fallback.exhausted', reason: 'no_fallback_channel', input: $input, calc: $this->walkCalc($policy->fallbackChannels, $usedTypes, $walk));
    }

    /**
     * @param  array<int, ChannelType>  $policyTypes
     * @param  list<ChannelType>  $usedTypes
     * @param  list<array{channel_type: string, outcome: string}>  $walk
     * @return array<string, mixed>
     */
    private function walkCalc(array $policyTypes, array $usedTypes, array $walk): array
    {
        return [
            'policy_fallback_types' => array_values(array_map(fn (ChannelType $t) => $t->value, $policyTypes)),
            'used_types' => array_map(fn (ChannelType $t) => $t->value, $usedTypes),
            'walk' => $walk,
        ];
    }

    /**
     * @return list<ChannelType>
     */
    private function channelTypesAlreadyUsed(NotificationDelivery $primary): array
    {
        $types = NotificationDelivery::query()
            ->with('channel')
            ->where('notification_id', $primary->notification_id)
            ->where('recipient_id', $primary->recipient_id)
            ->get()
            ->map(fn (NotificationDelivery $delivery) => $delivery->channel?->channel_type)
            ->filter()
            ->unique()
            ->all();

        return array_values($types);
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
