<?php

namespace App\Domains\Notifications\Listeners;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Actions\ResolveEscalationAudience;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentCreatedReaction;
use App\Domains\Incidents\Support\IncidentNoticeCopy;
use App\Domains\Incidents\Support\IsolatesIncidentCreatedReaction;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

class NotifyOnIncidentCreated implements IncidentCreatedReaction
{
    use IsolatesIncidentCreatedReaction;

    /**
     * Severidad mínima del incidente para salir por canales fuera de la app
     * (correo/SMS/WhatsApp/voz/push). Por debajo sólo hay aviso in-app.
     * Valores: low | medium | high | critical.
     */
    public const string SETTING_MIN_SEVERITY = 'notifications.out_of_band_min_severity';

    public const string DEFAULT_MIN_SEVERITY = 'medium';

    private const array SEVERITY_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function __construct(
        private readonly SendNotification $sendNotification,
        private readonly TenantConfigResolver $tenantConfig,
        private readonly ResolveEscalationAudience $resolveAudience,
    ) {}

    public function retryQueue(): string
    {
        return 'notifications';
    }

    public function react(IncidentCreated $event): void
    {
        $incident = $event->incident;

        $severity = $incident->priority?->code;
        $context = $this->contextSnapshot($incident);

        $payload = [
            'incident_id' => $incident->id,
            'incident_reference' => $incident->reference(),
            'incident_type' => $incident->type?->code,
            'severity' => $severity,
            'incident_title' => $incident->title,
            'asset_name' => $incident->asset?->name,
            'driver_name' => $incident->driver?->full_name,
            'location' => $this->location($incident, $context),
            'incident_url' => $this->incidentUrl($incident),
            'has_media' => $this->hasMedia($context),
        ];

        $copy = IncidentNoticeCopy::created($incident);
        $payload['spoken'] = $copy['spoken'];

        $payload += $this->lateNotice($incident);

        $notificationType = $this->resolveNotificationType($incident);

        // Un incidente por debajo del umbral del tenant (por defecto: low)
        // sólo avisa dentro de la app: no justifica un correo/SMS al equipo.
        $threshold = $this->reachesOutOfBandThreshold($incident->team_id, $severity);

        if (! $threshold['reaches']) {
            $payload['force_channels'] = [ChannelType::Web->value];

            // Corre en la transacción propia de este efecto (tras el commit
            // del incidente): la línea sale sólo si el aviso se confirma.
            $skipInput = [
                'incident_id' => $incident->id,
                'setting_key' => self::SETTING_MIN_SEVERITY,
                'type_source' => $notificationType['source'],
            ];
            $skipCalc = $threshold['calc'];
            $skipResult = ['forced_channel_types' => [ChannelType::Web->value]];

            DB::afterCommit(fn () => SystemLog::skipped('notifications.out_of_band.skipped', reason: 'below_min_severity', input: $skipInput, calc: $skipCalc, result: $skipResult));
        }

        $priority = NotificationPriority::fromIncidentPriority($severity);

        if ($threshold['reaches']) {
            $payload = $this->routeToFirstResponders($incident, $notificationType['type'], $priority, $payload, $copy);
        }

        $this->sendNotification->execute(
            teamId: $incident->team_id,
            notificationType: $notificationType['type'],
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:'.$incident->id,
            payload: $payload,
            subject: $copy['subject'],
            bodyPreview: $copy['body'],
        );
    }

    /**
     * Decisión 2026-10-01: los canales fuera de la app que despiertan (SMS,
     * llamada, push; la política del tenant decide cuáles) son sólo para la
     * persona en turno —o, sin nadie en turno, para quien opera incidentes—;
     * el resto del equipo se entera en la app y por correo. Antes un crítico
     * mandaba SMS a todo el equipo, en turno o no.
     *
     * Devuelve el payload del aviso al equipo: sin los primeros respondientes
     * y fijado a web + correo.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{subject: string, body: string, spoken: string}  $copy
     * @return array<string, mixed>
     */
    private function routeToFirstResponders(Incident $incident, string $notificationType, NotificationPriority $priority, array $payload, array $copy): array
    {
        $audience = $this->resolveAudience->execute($incident->team_id, 'on_call');
        $input = ['incident_id' => $incident->id];
        $calc = [
            'audience' => $audience['audience'],
            'audience_fallback' => $audience['fallback'],
            'responders_count' => count($audience['recipients']),
        ];

        if ($audience['recipients'] === []) {
            // Nadie en el equipo gestiona incidentes: el aviso sale al equipo
            // con la política normal, como antes.
            DB::afterCommit(fn () => SystemLog::skipped('notifications.incident_created.routed', reason: 'no_responders', input: $input, calc: $calc));

            return $payload;
        }

        $this->sendNotification->execute(
            teamId: $incident->team_id,
            notificationType: $notificationType,
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created_responder:'.$incident->id,
            payload: [...$payload, 'recipients' => $audience['recipients'], 'first_responder' => true],
            subject: $copy['subject'],
            bodyPreview: $copy['body'],
        );

        DB::afterCommit(fn () => SystemLog::ok('notifications.incident_created.routed', input: $input, calc: $calc, result: [
            'team_forced_channel_types' => [ChannelType::Web->value, ChannelType::Email->value],
        ]));

        return [
            ...$payload,
            'exclude_user_ids' => $audience['user_ids'],
            'force_channels' => [ChannelType::Web->value, ChannelType::Email->value],
        ];
    }

    /**
     * Un incidente abierto tarde (ver AssessIncidentLateArrival) avisa en
     * todos los canales cuánto hace que ocurrió: RenderNotificationContent
     * antepone `late_notice` al asunto/cuerpo y `late_notice_spoken` a la voz.
     * No toca prioridad ni canales.
     *
     * @return array<string, mixed>
     */
    private function lateNotice(Incident $incident): array
    {
        $notice = $incident->metadata_json['late_arrival'] ?? null;
        $input = ['incident_id' => $incident->id];

        if (! is_array($notice) || ! is_string($notice['text'] ?? null)) {
            DB::afterCommit(fn () => SystemLog::skipped('notifications.late_notice.attached', reason: 'not_late', input: $input, debug: true));

            return [];
        }

        $calc = [
            'delay_seconds' => $notice['delay_seconds'] ?? null,
            'threshold_minutes' => $notice['threshold_minutes'] ?? null,
            'rescued' => $notice['rescued'] ?? null,
            'timezone' => LoggableCode::guard(is_string($notice['timezone'] ?? null) ? $notice['timezone'] : null),
        ];

        DB::afterCommit(fn () => SystemLog::ok('notifications.late_notice.attached', input: $input, calc: $calc, result: ['notice_attached' => true, 'spoken_attached' => is_string($notice['spoken'] ?? null)]));

        return [
            'late_notice' => $notice['text'],
            'late_notice_spoken' => is_string($notice['spoken'] ?? null) ? $notice['spoken'] : null,
            'occurred_ago' => $notice['ago'] ?? null,
            'occurred_at_local' => $notice['occurred_at_local'] ?? null,
        ];
    }

    /**
     * Prefer an incident-type-specific notification type (e.g.
     * `incident.panic_emergency.created`) when an active template exists for
     * it — that's how the panic alert gets its rich template — and fall back
     * to the generic `incident.created` otherwise.
     *
     * @return array{type: string, source: 'type_specific_template'|'generic'}
     */
    private function resolveNotificationType(Incident $incident): array
    {
        $typeCode = $incident->type?->code;

        if ($typeCode === null) {
            return ['type' => 'incident.created', 'source' => 'generic'];
        }

        $specific = "incident.{$typeCode}.created";

        $hasTemplate = NotificationTemplate::query()
            ->where(function ($query) use ($incident) {
                $query->where('team_id', $incident->team_id)
                    ->orWhereNull('team_id');
            })
            ->where('event_type', $specific)
            ->where('is_active', true)
            ->exists();

        return $hasTemplate
            ? ['type' => $specific, 'source' => 'type_specific_template']
            : ['type' => 'incident.created', 'source' => 'generic'];
    }

    /**
     * @return array{reaches: bool, calc: array<string, mixed>}
     */
    private function reachesOutOfBandThreshold(int $teamId, ?string $severity): array
    {
        $minimum = (string) $this->tenantConfig->resolve($teamId, self::SETTING_MIN_SEVERITY, self::DEFAULT_MIN_SEVERITY);

        $minimumRank = self::SEVERITY_RANK[$minimum] ?? self::SEVERITY_RANK[self::DEFAULT_MIN_SEVERITY];
        $severityRank = self::SEVERITY_RANK[$severity ?? ''] ?? self::SEVERITY_RANK['medium'];

        return [
            'reaches' => $severityRank >= $minimumRank,
            'calc' => [
                'severity' => LoggableCode::guard($severity),
                'severity_rank' => $severityRank,
                'severity_rank_source' => isset(self::SEVERITY_RANK[$severity ?? '']) ? 'priority' : 'default_medium',
                'min_severity' => LoggableCode::guard($minimum),
                'min_severity_rank' => $minimumRank,
                'min_severity_valid' => isset(self::SEVERITY_RANK[$minimum]),
            ],
        ];
    }

    private function contextSnapshot(Incident $incident): ?EventContextSnapshot
    {
        if ($incident->related_event_id === null) {
            return null;
        }

        return EventContextSnapshot::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->first();
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, address: string|null}|null
     */
    private function location(Incident $incident, ?EventContextSnapshot $context): ?array
    {
        $snapshot = $context?->location_snapshot_json;

        $latitude = isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null;
        $longitude = isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null;

        $address = $incident->asset?->latestLocation?->formatted_location;

        if ($latitude === null && $longitude === null && $address === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address,
        ];
    }

    private function incidentUrl(Incident $incident): ?string
    {
        $slug = $incident->team?->slug;

        return $slug !== null ? url("/{$slug}/incidents/{$incident->id}") : null;
    }

    private function hasMedia(?EventContextSnapshot $context): bool
    {
        $media = $context?->media_snapshot_json;

        return is_array($media) && ($media['items'] ?? $media) !== [];
    }
}
