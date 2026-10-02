<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Actions\MapExternalEventType;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Support\Carbon;

/**
 * Mete un incidente de alerta leído por el poll de respaldo
 * (PollAlertIncidentsJob, `GET /alerts/incidents/stream`) en el MISMO
 * pipeline que el webhook `AlertIncident`:
 *
 * - El payload se guarda con la forma del webhook (`eventType` + `data`), así
 *   la normalización aplica exactamente las mismas reglas de mapeo
 *   (`data.conditions.0.description == 'Panic Button'`, etc.) y la ruta
 *   rápida de emergencias abre el incidente igual.
 * - Dedup simétrico con el webhook por la identidad del incidente
 *   ({@see ResolveAlertIncidentIdentity}): si ya hay un raw event del tenant
 *   con esa clave (webhook o un poll anterior) no se guarda otra copia; si el
 *   poll llega primero, el webhook posterior cae como duplicado entre fuentes
 *   en {@see DetectDuplicateEvent}.
 * - Sólo emergencias: si la regla de mapeo lo clasifica como no-emergencia se
 *   ignora (el poll es un respaldo de pánicos, no un segundo canal de
 *   alertas). Sin regla se ingiere igual: el webhook haría lo mismo y la
 *   normalización lo escalará como alerta sin clasificar (posible pánico).
 * - Si el webhook no entregó el incidente pasada su gracia, se avisa: el
 *   pánico se rescató, pero el webhook del tenant está roto.
 */
class IngestAlertIncident
{
    public const string EVENT_TYPE = 'AlertIncident';

    /** Prefijo de la clave de respaldo para incidentes sin identidad estable. */
    public const string PAYLOAD_KEY_PREFIX = 'alert_incident_payload:';

    public function __construct(
        private ResolveAlertIncidentIdentity $identity,
        private MapExternalEventType $mapExternalEventType,
        private StoreRawEvent $storeRawEvent,
        private QueueRawEventForProcessing $queueForProcessing,
        private AlertPipelineFailure $alertPipelineFailure,
    ) {}

    /**
     * @param  array<string, mixed>  $incident  Un item de `data` del stream.
     * @return 'ingested'|'already_ingested'|'not_emergency'
     */
    public function execute(TenantIntegration $integration, array $incident): string
    {
        $teamId = $integration->team_id;
        $fingerprint = $this->identity->fingerprint($incident);
        $state = ResolveAlertIncidentIdentity::state($incident);
        // Sin identidad (campos requeridos por la spec ausentes) se usa una
        // huella del objeto: estable entre barridos, así el solape de la
        // ventana no lo re-ingiere, aunque no pueda casarse con el webhook.
        $key = $fingerprint !== null
            ? ResolveAlertIncidentIdentity::PREFIX.$fingerprint.':'.$state
            : self::PAYLOAD_KEY_PREFIX.sha1((string) json_encode($incident)).':'.$state;
        $input = ['integration_id' => $integration->id, 'state' => $state];

        $existing = RawEvent::query()
            ->where('team_id', $teamId)
            ->where('deduplication_key', $key)
            ->with('eventSource:id,source_type')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            $firstSource = $existing->eventSource?->source_type->value;

            SystemLog::skipped('ingestion.alert_incidents.skipped', reason: 'already_ingested', input: $input, calc: [
                'identity' => $fingerprint !== null,
                'first_source' => $firstSource,
            ], result: ['raw_event_id' => $existing->id], debug: true);

            if ($firstSource !== EventSourceType::Webhook->value) {
                $this->checkWebhookDelivery($integration, $incident, $fingerprint, $existing);
            }

            return 'already_ingested';
        }

        $payload = ['eventType' => self::EVENT_TYPE, 'data' => $incident];

        // Mismo criterio que el webhook: la regla de mapeo del proveedor.
        $rule = $this->mapExternalEventType->execute($integration->provider_id, self::EVENT_TYPE, $payload);
        $eventTypeCode = $rule?->mappedEventType?->code;
        $categoryCode = $rule?->mappedCategory?->code ?? $rule?->mappedEventType?->category?->code;

        if ($rule !== null && ! NormalizeRawEvent::isEmergencyCode($categoryCode, $eventTypeCode)) {
            SystemLog::skipped('ingestion.alert_incidents.skipped', reason: 'not_emergency', input: $input, calc: [
                'mapping_rule_id' => $rule->id,
                'event_type_code' => $eventTypeCode,
                'category_code' => $categoryCode,
            ]);

            return 'not_emergency';
        }

        $rawEvent = $this->storeRawEvent->execute(
            payload: $payload,
            sourceType: EventSourceType::PollingFeed->value,
            teamId: $teamId,
            providerId: $integration->provider_id,
            deduplicationKey: $key,
            eventTypeRaw: self::EVENT_TYPE,
        );

        PipelineTrace::within($rawEvent->trace_id, $rawEvent->team_id, function () use ($rawEvent, $input, $fingerprint, $rule, $eventTypeCode, $integration, $incident): void {
            $this->queueForProcessing->execute($rawEvent);

            SystemLog::ok('ingestion.alert_incidents.ingested', input: $input, calc: [
                'identity' => $fingerprint !== null,
                'mapped' => $rule !== null,
                'event_type_code' => $eventTypeCode,
            ], result: ['raw_event_id' => $rawEvent->id]);

            $this->checkWebhookDelivery($integration, $incident, $fingerprint, $rawEvent);
        }, $integration->provider?->code);

        return 'ingested';
    }

    /**
     * El poll vio un incidente que guardó él (no el webhook). Si el webhook
     * tampoco lo trajo después, pasada la gracia, el webhook está roto.
     *
     * @param  array<string, mixed>  $incident
     */
    private function checkWebhookDelivery(TenantIntegration $integration, array $incident, ?string $fingerprint, RawEvent $polledRaw): void
    {
        $input = ['integration_id' => $integration->id, 'raw_event_id' => $polledRaw->id];

        if ($fingerprint === null) {
            SystemLog::skipped('ingestion.alert_incidents.webhook_check', reason: 'no_identity', input: $input, debug: true);

            return;
        }

        $deliveredByWebhook = RawEvent::query()
            ->where('team_id', $integration->team_id)
            ->whereIn('deduplication_key', $this->identity->keysForFingerprint($fingerprint))
            ->whereHas('eventSource', fn ($query) => $query
                ->where('team_id', $integration->team_id)
                ->where('source_type', EventSourceType::Webhook->value))
            ->exists();

        if ($deliveredByWebhook) {
            SystemLog::ok('ingestion.alert_incidents.webhook_check', input: $input, result: ['delivered_by_webhook' => true], debug: true);

            return;
        }

        $grace = max(0, (int) config('pipeline.alert_incidents_poll.webhook_grace_seconds', 120));
        $happenedAt = $this->parseTime($incident['happenedAtTime'] ?? null);

        if ($happenedAt === null) {
            SystemLog::skipped('ingestion.alert_incidents.webhook_check', reason: 'unknown_age', input: $input, calc: ['grace_seconds' => $grace]);

            return;
        }

        $age = max(0, (int) $happenedAt->diffInSeconds(now(), absolute: false));
        $calc = ['age_seconds' => $age, 'grace_seconds' => $grace, 'happened_at' => $happenedAt->toIso8601String()];

        if ($age < $grace) {
            SystemLog::skipped('ingestion.alert_incidents.webhook_check', reason: 'within_grace', input: $input, calc: $calc);

            return;
        }

        $alreadyAlerted = PipelineFailureAlert::query()
            ->where('team_id', $integration->team_id)
            ->where('kind', PipelineFailureAlert::KIND_WEBHOOK_MISSED)
            ->where('raw_event_id', $polledRaw->id)
            ->exists();

        if ($alreadyAlerted) {
            SystemLog::skipped('ingestion.alert_incidents.webhook_check', reason: 'already_alerted', input: $input, calc: $calc, debug: true);

            return;
        }

        SystemLog::degraded('ingestion.alert_incidents.webhook_missed', reason: 'webhook_not_delivered', input: $input, calc: $calc);

        $this->alertPipelineFailure->forMissedWebhook($polledRaw);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
