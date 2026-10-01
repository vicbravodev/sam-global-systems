<?php

namespace App\Domains\Tenancy\Listeners;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Emergencia atendida en una unidad NO vigilada (decisión 2026-09-28): ese
 * día la unidad se cobra como tracto-día + recargo (una sola vez por unidad y
 * día local, aunque presione pánico diez veces) y el admin recibe un aviso de
 * uso extra con la sugerencia de darla de alta.
 */
class ChargeUnmonitoredEmergency implements ShouldQueue
{
    public string $queue = 'billing';

    public function __construct(
        private readonly RecordUsageEvent $recordUsage,
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(UnmonitoredAssetEmergencyReceived $event): void
    {
        $normalized = $event->normalizedEvent;
        $teamId = $normalized->team_id;
        $assetId = $normalized->asset_id;

        if ($assetId === null) {
            SystemLog::skipped('billing.emergency_surcharge.skipped', reason: 'no_asset', input: [
                'team_id' => $teamId,
                'normalized_event_id' => $normalized->id,
            ]);

            return;
        }

        TenantContext::for($teamId, function () use ($normalized, $teamId, $assetId) {
            $localDate = AssetDayPricing::localDate($normalized->occurred_at);
            $eventKey = "unmonitored_emergency:{$teamId}:{$assetId}:{$localDate}";
            // Nunca el nombre ni la placa del activo, ni el asunto/cuerpo del aviso.
            $logInput = [
                'team_id' => $teamId,
                'asset_id' => $assetId,
                'normalized_event_id' => $normalized->id,
            ];

            $recorded = $this->recordUsage->record(
                teamId: $teamId,
                meterCode: AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE,
                quantity: 1,
                eventKey: $eventKey,
                metadata: [
                    'asset_id' => $assetId,
                    'normalized_event_id' => $normalized->id,
                    'local_date' => $localDate,
                ],
                occurredAt: AssetDayPricing::localNoon($localDate),
            );

            // El importe no se conoce aquí: sale al cerrar la factura con la tarifa diaria.
            if ($recorded) {
                SystemLog::ok('billing.emergency_surcharge.charged', input: $logInput, calc: [
                    'local_date' => $localDate,
                    // `occurred_at` es NOT NULL en normalized_events: la fecha siempre sale del evento.
                    'occurred_at_source' => 'event',
                    'surcharge_percent' => AssetDayPricing::unmonitoredEmergencySurchargePercent(),
                    'meter_code' => AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE,
                ], result: ['event_key' => $eventKey, 'recorded' => true]);
            } else {
                SystemLog::skipped(
                    'billing.emergency_surcharge.skipped',
                    reason: 'already_charged_today',
                    input: $logInput,
                    calc: ['local_date' => $localDate],
                    result: ['event_key' => $eventKey],
                );
            }

            $recipients = IncidentSupervisors::recipients($teamId);

            if ($recipients === []) {
                SystemLog::skipped('billing.emergency_surcharge.notified', reason: 'no_supervisors', input: [
                    'team_id' => $teamId,
                    'asset_id' => $assetId,
                ]);

                return;
            }

            $assetName = Asset::query()->whereKey($assetId)->value('name') ?? "#{$assetId}";
            $surcharge = AssetDayPricing::unmonitoredEmergencySurchargePercent();

            $notification = $this->sendNotification->execute(
                teamId: $teamId,
                notificationType: 'billing.unmonitored_emergency',
                sourceType: NotificationSourceType::SystemEvent,
                sourceReferenceId: (string) $normalized->id,
                priority: NotificationPriority::High,
                triggeredByType: NotificationTriggeredByType::System,
                triggeredById: null,
                eventKey: "unmonitored_emergency_notice:{$assetId}:{$localDate}",
                payload: [
                    'asset_id' => $assetId,
                    'normalized_event_id' => $normalized->id,
                    'recipients' => $recipients,
                    'force_channels' => [ChannelType::Web->value, ChannelType::Email->value],
                ],
                subject: "Emergencia atendida en una unidad no vigilada: {$assetName}",
                bodyPreview: sprintf(
                    'La unidad %s no está dada de alta en vigilancia y envió una emergencia. SAM la atendió igual; hoy se cobra como tracto-día con %s%% de recargo. Actívala en Flota para cobrarla a tarifa normal.',
                    $assetName,
                    rtrim(rtrim(number_format($surcharge, 2), '0'), '.'),
                ),
            );

            $notifiedInput = ['team_id' => $teamId, 'asset_id' => $assetId];

            // SendNotification deduplica por event_key: si devolvió la fila existente, no se envió nada.
            if (! $notification->wasRecentlyCreated) {
                SystemLog::skipped(
                    'billing.emergency_surcharge.notified',
                    reason: 'already_notified',
                    input: $notifiedInput,
                    result: ['notification_id' => $notification->id],
                );

                return;
            }

            SystemLog::ok('billing.emergency_surcharge.notified', input: $notifiedInput, result: [
                'notification_id' => $notification->id,
                'recipients_count' => count($recipients),
            ]);
        });
    }
}
