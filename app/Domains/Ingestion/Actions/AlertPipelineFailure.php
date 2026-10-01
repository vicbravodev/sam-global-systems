<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Notifications\PipelineFailureNotification;
use App\Domains\Normalization\Actions\ClassifyRawEventEmergency;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\JobFailureReporter;
use App\Support\LoggableCode;
use App\Support\SafeException;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Alerta humana cuando el camino crítico del pipeline se rinde: un job agotó
 * sus reintentos (`failed()`), el barrido de atascados agotó sus rescates, o
 * una alerta del proveedor (posible pánico) no encajó en ninguna regla de
 * mapeo y quedó `unmapped` sin incidente. En un producto de seguridad, un
 * pánico perdido en silencio es el peor fallo.
 *
 * - Destinatarios: siempre los super-admins de plataforma; además los
 *   owners/admins del team del evento cuando es una emergencia. Nunca otro
 *   tenant: el team sale del propio evento (lookup de entrada sin scope) y los
 *   usuarios se filtran por membresía de ESE team.
 * - Canal: Laravel Notifications directo (correo + in-app) con `sendNow`, no el
 *   dominio Notifications/Twilio ni la cola: ambos son parte de lo que pudo
 *   fallar (un Valkey caído o una cola atascada es justo cuando hay que avisar).
 *   `failed()` ya corre en un worker, fuera del camino caliente.
 * - Idempotente: `pipeline_failure_alerts.dedup_key` es único; se reclama con
 *   insertOrIgnore antes de enviar, así que una alerta por evento y etapa por
 *   muchos reintentos, rescates o workers que coincidan. Si el envío falla se
 *   libera la reclamación para que un fallo posterior pueda volver a avisar.
 * - Nunca lanza: corre dentro de `failed()`.
 *
 * @phpstan-import-type FailureDetails from PipelineFailureNotification
 */
class AlertPipelineFailure
{
    public function __construct(
        private ClassifyRawEventEmergency $classify,
    ) {}

    /**
     * Fallo definitivo de un job del camino crítico.
     */
    public function forJobFailure(
        string $jobClass,
        Throwable $error,
        ?int $rawEventId = null,
        ?int $normalizedEventId = null,
        ?int $expectedTeamId = null,
    ): void {
        $this->guarded(fn () => $this->alert(
            kind: PipelineFailureAlert::KIND_JOB_FAILED,
            stage: (string) preg_replace('/\.failed$/', '', JobFailureReporter::codeFor($jobClass)),
            rawEventId: $rawEventId,
            normalizedEventId: $normalizedEventId,
            expectedTeamId: $expectedTeamId,
            forceEmergency: $jobClass === OpenEmergencyIncidentJob::class,
            error: $error,
            reprocessAttempts: null,
        ), ['raw_event_id' => $rawEventId, 'normalized_event_id' => $normalizedEventId]);
    }

    /**
     * El barrido de atascados agotó los rescates de un raw event.
     */
    public function forExhaustedReprocess(RawEvent $rawEvent): void
    {
        $this->guarded(fn () => $this->alert(
            kind: PipelineFailureAlert::KIND_REPROCESS_EXHAUSTED,
            stage: 'ingestion.reprocess_stuck_raw_events',
            rawEventId: (int) $rawEvent->id,
            normalizedEventId: null,
            expectedTeamId: $rawEvent->team_id !== null ? (int) $rawEvent->team_id : null,
            forceEmergency: false,
            error: null,
            reprocessAttempts: (int) $rawEvent->reprocess_attempts,
        ), ['raw_event_id' => (int) $rawEvent->id]);
    }

    /**
     * Una alerta del proveedor (tipo en `pipeline.unmapped_alert_types`) se
     * normalizó como `unmapped`: no abrirá incidente. Se trata como posible
     * emergencia (avisar de más antes que perder un pánico malformado), así
     * que también llega a owners/admins del tenant del evento. Una por raw
     * event: el dedup_key lleva el raw event.
     */
    public function forUnmappedAlert(RawEvent $rawEvent, string $externalEventType): void
    {
        $this->guarded(fn () => $this->alert(
            kind: PipelineFailureAlert::KIND_UNMAPPED_ALERT,
            stage: 'normalization.unmapped_alert',
            rawEventId: (int) $rawEvent->id,
            normalizedEventId: null,
            expectedTeamId: $rawEvent->team_id !== null ? (int) $rawEvent->team_id : null,
            forceEmergency: true,
            error: null,
            reprocessAttempts: null,
            externalEventType: LoggableCode::guard($externalEventType),
        ), ['raw_event_id' => (int) $rawEvent->id]);
    }

    /**
     * @param  \Closure(): void  $callback
     * @param  array<string, mixed>  $input
     */
    private function guarded(\Closure $callback, array $input): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            SystemLog::failed('ingestion.failure_alert.failed', reason: 'exception', input: $input, error: $e);
        }
    }

    private function alert(
        string $kind,
        string $stage,
        ?int $rawEventId,
        ?int $normalizedEventId,
        ?int $expectedTeamId,
        bool $forceEmergency,
        ?Throwable $error,
        ?int $reprocessAttempts,
        ?string $externalEventType = null,
    ): void {
        $subject = $this->resolveSubject($rawEventId, $normalizedEventId);
        $teamId = $subject['team_id'];
        $input = [
            'kind' => $kind,
            'stage' => $stage,
            'raw_event_id' => $subject['raw_event_id'],
            'normalized_event_id' => $subject['normalized_event_id'],
            'team_id' => $teamId,
        ];

        // El team del job no concuerda con el del evento: nunca se avisa a un
        // tenant por un evento que quizá no es suyo. Sólo plataforma.
        if ($teamId !== null && $expectedTeamId !== null && $teamId !== $expectedTeamId) {
            SystemLog::degraded('ingestion.failure_alert.team_mismatch', reason: 'team_mismatch', input: $input, calc: ['team_matches' => false]);
            $teamId = null;
            $input['team_id'] = null;
        }

        $isEmergency = $forceEmergency || $subject['emergency'];
        $subjectKey = $subject['normalized_event_id'] !== null && $subject['raw_event_id'] === null
            ? 'n'.$subject['normalized_event_id']
            : 'r'.($subject['raw_event_id'] ?? 'x');
        $dedupKey = "{$kind}:{$stage}:t".($teamId ?? 0).":{$subjectKey}";
        $errorJson = $error !== null ? SafeException::describe($error) : null;
        $now = now();

        // Filas de plataforma (auditan a cualquier tenant): se leen y escriben
        // sin tenant activo, siempre por su dedup_key (que incluye el team).
        $claimed = TenantContext::withoutTenant(fn () => PipelineFailureAlert::query()->insertOrIgnore([
            'team_id' => $teamId,
            'dedup_key' => $dedupKey,
            'kind' => $kind,
            'stage' => $stage,
            'raw_event_id' => $subject['raw_event_id'],
            'normalized_event_id' => $subject['normalized_event_id'],
            'event_type_code' => $subject['event_type_code'],
            'asset_id' => $subject['asset_id'],
            'is_emergency' => $isEmergency,
            'error_json' => $errorJson !== null ? json_encode($errorJson) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        if ($claimed === 0) {
            SystemLog::skipped('ingestion.failure_alert.skipped', reason: 'already_alerted', input: $input, calc: ['is_emergency' => $isEmergency]);

            return;
        }

        $superAdmins = User::query()->where('global_role', 'super_admin')->get();
        $tenantUsers = $isEmergency && $teamId !== null
            ? $this->tenantResponsibles($teamId, $superAdmins)
            : new Collection;

        $details = [
            'kind' => $kind,
            'stage' => $stage,
            'team_id' => $teamId,
            'team_name' => $teamId !== null ? Team::query()->whereKey($teamId)->value('name') : null,
            'raw_event_id' => $subject['raw_event_id'],
            'normalized_event_id' => $subject['normalized_event_id'],
            'event_type_code' => $subject['event_type_code'],
            'external_event_type' => $externalEventType,
            'is_emergency' => $isEmergency,
            'asset_id' => $subject['asset_id'],
            'asset_name' => $this->assetName($teamId, $subject['asset_id']),
            'occurred_at' => $subject['occurred_at'],
            'failed_at' => $now->toIso8601String(),
            'error_class' => $errorJson['class'] ?? null,
            'error_message' => $errorJson['message'] ?? null,
            'reprocess_attempts' => $reprocessAttempts,
        ];

        // Cada audiencia por separado: si falla el correo a plataforma, el
        // tenant recibe igual el aviso de su emergencia (y al revés).
        $platformSent = $this->deliver(PipelineFailureNotification::AUDIENCE_PLATFORM, $superAdmins, null, $details, $input);
        $tenantSent = $this->deliver(PipelineFailureNotification::AUDIENCE_TENANT, $tenantUsers, $teamId, $details, $input);

        $attempted = ($superAdmins->isNotEmpty() ? 1 : 0) + ($tenantUsers->isNotEmpty() ? 1 : 0);
        $delivered = ($platformSent ? 1 : 0) + ($tenantSent ? 1 : 0);

        if ($attempted > 0 && $delivered === 0) {
            // No salió nada: se libera la reclamación para que un fallo
            // posterior del mismo evento (otro rescate) vuelva a intentarlo.
            // Si salió alguna audiencia se conserva: nunca se re-manda la que
            // ya llegó (la que falló queda registrada en su `failed`).
            TenantContext::withoutTenant(fn () => PipelineFailureAlert::query()->where('dedup_key', $dedupKey)->delete());

            return;
        }

        $platformRecipients = $platformSent ? $superAdmins->count() : 0;
        $tenantRecipients = $tenantSent ? $tenantUsers->count() : 0;

        TenantContext::withoutTenant(fn () => PipelineFailureAlert::query()->where('dedup_key', $dedupKey)->update([
            'platform_recipients' => $platformRecipients,
            'tenant_recipients' => $tenantRecipients,
            'notified_at' => $now,
        ]));

        $calc = [
            'is_emergency' => $isEmergency,
            'tenant_notified' => $tenantSent,
            'tenant_skip_reason' => match (true) {
                $tenantSent => null,
                $tenantUsers->isNotEmpty() => 'send_failed',
                ! $isEmergency => 'not_emergency',
                $teamId === null => 'no_tenant',
                default => 'no_tenant_admins',
            },
            'platform_send_failed' => $superAdmins->isNotEmpty() && ! $platformSent,
        ];
        $result = ['platform_recipients' => $platformRecipients, 'tenant_recipients' => $tenantRecipients];

        if ($delivered === 0) {
            SystemLog::degraded('ingestion.failure_alert.sent', reason: 'no_recipients', input: $input, calc: $calc, result: $result);

            return;
        }

        if ($delivered < $attempted) {
            SystemLog::degraded('ingestion.failure_alert.sent', reason: 'partial_delivery', input: $input, calc: $calc, result: $result);

            return;
        }

        SystemLog::ok('ingestion.failure_alert.sent', input: $input, calc: $calc, result: $result);
    }

    /**
     * Envía una audiencia; nunca lanza. Plataforma sin tenant activo, tenant
     * dentro del suyo.
     *
     * @param  Collection<int, User>  $recipients
     * @param  FailureDetails  $details
     * @param  array<string, mixed>  $input
     */
    private function deliver(string $audience, Collection $recipients, ?int $teamId, array $details, array $input): bool
    {
        if ($recipients->isEmpty()) {
            return false;
        }

        $send = fn () => Notification::sendNow($recipients, new PipelineFailureNotification($audience, $details));

        try {
            $teamId === null ? TenantContext::withoutTenant($send) : TenantContext::for($teamId, $send);
        } catch (Throwable $e) {
            SystemLog::failed('ingestion.failure_alert.failed', reason: 'send_failed', input: [...$input, 'audience' => $audience], error: $e);

            return false;
        }

        return true;
    }

    /**
     * Lookup de entrada sin scope: corre desde `failed()`, sin tenant activo;
     * el team sale del propio evento.
     *
     * @return array{team_id: ?int, raw_event_id: ?int, normalized_event_id: ?int, event_type_code: ?string, emergency: bool, asset_id: ?int, occurred_at: ?string}
     */
    private function resolveSubject(?int $rawEventId, ?int $normalizedEventId): array
    {
        if ($normalizedEventId !== null) {
            $normalized = NormalizedEvent::withoutGlobalScopes()
                ->with(['eventType:id,code,category_id', 'eventType.category:id,code', 'eventCategory:id,code'])
                ->find($normalizedEventId);

            if ($normalized !== null) {
                $classification = $this->classify->fromNormalized($normalized);

                return [
                    'team_id' => $normalized->team_id !== null ? (int) $normalized->team_id : null,
                    'raw_event_id' => (int) $normalized->raw_event_id,
                    'normalized_event_id' => (int) $normalized->id,
                    'event_type_code' => $classification['event_type_code'],
                    'emergency' => $classification['emergency'],
                    'asset_id' => $normalized->asset_id !== null ? (int) $normalized->asset_id : null,
                    'occurred_at' => $normalized->occurred_at?->toIso8601String(),
                ];
            }
        }

        $rawEvent = $rawEventId !== null ? RawEvent::withoutGlobalScopes()->find($rawEventId) : null;

        if ($rawEvent === null) {
            return [
                'team_id' => null,
                'raw_event_id' => $rawEventId,
                'normalized_event_id' => $normalizedEventId,
                'event_type_code' => null,
                'emergency' => false,
                'asset_id' => null,
                'occurred_at' => null,
            ];
        }

        $teamId = $rawEvent->team_id !== null ? (int) $rawEvent->team_id : null;
        $classification = TenantContext::for($teamId, fn () => $this->classify->execute($rawEvent));

        return [
            'team_id' => $teamId,
            'raw_event_id' => (int) $rawEvent->id,
            'normalized_event_id' => $classification['normalized_event_id'],
            'event_type_code' => $classification['event_type_code'],
            'emergency' => $classification['emergency'],
            'asset_id' => $classification['asset_id'],
            'occurred_at' => ($rawEvent->occurred_at ?? $rawEvent->received_at)?->toIso8601String(),
        ];
    }

    /**
     * Owners y admins del team del evento (miembros de ESE team, nunca de
     * otro), sin repetir a los super-admins que ya reciben la versión técnica.
     *
     * @param  Collection<int, User>  $superAdmins
     * @return Collection<int, User>
     */
    private function tenantResponsibles(int $teamId, Collection $superAdmins): Collection
    {
        return User::query()
            ->whereIn('id', Membership::query()
                ->where('team_id', $teamId)
                ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->select('user_id'))
            ->whereNotIn('id', $superAdmins->modelKeys())
            ->get();
    }

    private function assetName(?int $teamId, ?int $assetId): ?string
    {
        if ($teamId === null || $assetId === null) {
            return null;
        }

        $name = TenantContext::for($teamId, fn () => Asset::query()
            ->where('team_id', $teamId)
            ->whereKey($assetId)
            ->value('name'));

        return is_string($name) ? $name : null;
    }
}
