<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use LogicException;

/**
 * One step of the HOS ladder as a notification to the driver: the step's
 * channels forced (`force_channels`), the driver as explicit recipient (his
 * phone for WhatsApp/SMS/voice, his Samsara app address for the app) and
 * the HOS copy (`spoken` for the call). `event_key` = `hos:{episode}:{step}`:
 * SendNotification dedups it, so an overlapping cycle never sends twice.
 * The integration must be one of the episode's team: its id goes into the
 * app address and the external driver id is the one of the episode's own
 * driver for that integration's provider.
 *
 * Billing is the existing one: Twilio channels meter and charge cost + 30 %
 * in AttemptDelivery; the Samsara app has no meter.
 *
 * Must run inside the episode's TenantContext.
 */
class SendHosNudge
{
    public const string NOTIFICATION_TYPE = 'hos.nudge';

    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public static function eventKey(HosEpisode $episode, int $step): string
    {
        return "hos:{$episode->id}:{$step}";
    }

    public function execute(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision): Notification
    {
        if ($decision->step === null || $decision->notice === null) {
            throw new LogicException('An HOS nudge needs a step and a notice.');
        }

        // La dirección de la app lleva el id de la integración: nunca la de otro tenant.
        if ($integration->team_id !== $episode->team_id) {
            throw new LogicException('An HOS nudge can only go through an integration of the episode\'s team.');
        }

        $driver = Driver::query()
            ->where('team_id', $episode->team_id)
            ->findOrFail($episode->driver_id);

        // Ids de Samsara son únicos platform-wide: la referencia se busca por el
        // chofer de este team (ya validado) y el proveedor de esta integración.
        $externalId = DriverExternalReference::query()
            ->where('driver_id', $driver->id)
            ->where('provider_id', $integration->provider_id)
            ->whereHas('driver', fn ($query) => $query->where('team_id', $episode->team_id))
            ->value('external_id');

        $copy = HosNoticeCopy::for($decision->notice, $decision->amount);

        return $this->sendNotification->execute(
            teamId: $episode->team_id,
            notificationType: self::NOTIFICATION_TYPE,
            sourceType: NotificationSourceType::HosEpisode,
            sourceReferenceId: (string) $episode->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: self::eventKey($episode, $decision->step),
            payload: [
                'force_channels' => $decision->channels,
                'recipients' => [[
                    'recipient_type' => RecipientType::Driver->value,
                    'address' => 'driver:'.$driver->id,
                    'name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'recipient_reference_id' => (string) $driver->id,
                    'metadata' => is_string($externalId) && $externalId !== ''
                        ? [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => SamsaraDriverAppAddress::make($integration->id, $externalId)]
                        : [],
                ]],
                'spoken' => $copy['spoken'],
                'hos' => [
                    'episode_id' => $episode->id,
                    'situation' => $episode->situation->value,
                    'step' => $decision->step,
                    'notice' => $decision->notice->value,
                ],
            ],
            subject: $copy['subject'],
            bodyPreview: $copy['body'],
        );
    }
}
