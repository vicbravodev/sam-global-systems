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
        $teamId = (int) $normalized->team_id;
        $assetId = $normalized->asset_id;

        if ($assetId === null) {
            return;
        }

        TenantContext::for($teamId, function () use ($normalized, $teamId, $assetId) {
            $localDate = AssetDayPricing::localDate($normalized->occurred_at ?? now());

            $this->recordUsage->execute(
                teamId: $teamId,
                meterCode: AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE,
                quantity: 1,
                eventKey: "unmonitored_emergency:{$teamId}:{$assetId}:{$localDate}",
                metadata: [
                    'asset_id' => $assetId,
                    'normalized_event_id' => $normalized->id,
                    'local_date' => $localDate,
                ],
                occurredAt: AssetDayPricing::localNoon($localDate),
            );

            $recipients = IncidentSupervisors::recipients($teamId);

            if ($recipients === []) {
                return;
            }

            $assetName = Asset::query()->whereKey($assetId)->value('name') ?? "#{$assetId}";
            $surcharge = AssetDayPricing::unmonitoredEmergencySurchargePercent();

            $this->sendNotification->execute(
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
        });
    }
}
