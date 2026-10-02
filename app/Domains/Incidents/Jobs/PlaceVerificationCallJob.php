<?php

namespace App\Domains\Incidents\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\EscalateUnverifiableIncident;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Domains\Incidents\Support\VerificationCallTwiml;
use App\Domains\Notifications\Actions\RecordMessagingCharge;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Domains\Notifications\Support\TwilioResourceAttribution;
use App\Domains\Notifications\Support\TwilioWebhookUrl;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\JobFailureReporter;
use App\Support\LoggableCode;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Twilio\Exceptions\RestException;

/**
 * Place one operator verification call through SAM's platform Twilio voice
 * channel (Roadmap V2-A3). The DTMF answer arrives at
 * the gather webhook; Twilio's status callback reports unanswered calls, and
 * a delayed `EvaluateVerificationCallOutcomeJob` acts as the safety net when
 * no callback ever lands.
 */
class PlaceVerificationCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string USAGE_METER_CODE = 'voice_calls';

    /** Espera mínima de la red de seguridad: más que una llamada completa. */
    public const int MIN_OUTCOME_DELAY_SECONDS = 90;

    /**
     * Distinguishable `metadata_json.failure_reason` used when a verification
     * is closed by {@see IncidentSuppression} instead of a real call outcome.
     * `StartIncidentCallVerification` reads this to tell "the incident got
     * claimed mid-flight" apart from a genuinely concluded chain, so a fresh
     * start after the incident is released is not blocked forever.
     */
    public const string SUPPRESSED_FAILURE_REASON = 'suppressed_human_control';

    public int $tries = 1;

    public function __construct(
        public readonly int $verificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        TwilioVoiceCaller $caller,
        TenantConfigResolver $tenantConfig,
        HandleVerificationCallAttemptFailure $handleFailure,
        RecordUsageEvent $recordUsage,
        RecordMessagingCharge $recordCharge,
        EscalateUnverifiableIncident $escalateUnverifiable,
    ): void {
        $verification = IncidentCallVerification::withoutGlobalScopes()->find($this->verificationId);

        if ($verification === null || $verification->status !== CallVerificationStatus::Pending) {
            SystemLog::skipped('incidents.call_verification.skipped', reason: 'not_pending', input: ['verification_id' => $this->verificationId], debug: true);

            return;
        }

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($verification->team_id);

        $logInput = [
            'verification_id' => $verification->id,
            'incident_id' => $verification->incident_id,
            'attempt' => $verification->attempt,
        ];

        $incident = Incident::query()->find($verification->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            $verification->forceFill([
                'status' => CallVerificationStatus::Failed,
                'metadata_json' => ['failure_reason' => 'incident_terminal'],
            ])->save();

            SystemLog::skipped('incidents.call_verification.closed', reason: 'incident_terminal', input: $logInput);

            return;
        }

        // Somebody already claimed it or acknowledged it: a human is on it,
        // no need to keep calling. Close the attempt terminally — leaving it
        // Pending would make StartIncidentCallVerification treat it as
        // in-flight forever and refuse to start a new chain once the
        // incident is released.
        if (IncidentSuppression::isUnderHumanControl($incident)) {
            $verification->forceFill([
                'status' => CallVerificationStatus::Failed,
                'metadata_json' => ['failure_reason' => self::SUPPRESSED_FAILURE_REASON],
            ])->save();

            SystemLog::skipped('incidents.call_verification.closed', reason: 'human_control', input: $logInput, result: ['failure_reason' => self::SUPPRESSED_FAILURE_REASON]);

            return;
        }

        // Tenant suspendido/cancelado/expirado: la llamada de verificación de
        // una emergencia se hace IGUAL (decisión 2026-09-28: la persona va
        // primero que el cobro). Queda anotado para cobranza/soporte.
        $blocked = TenantCanSend::blockedReason($verification->team_id);

        if ($blocked !== null) {
            SystemLog::degraded('incidents.call_verification.emergency_override', reason: 'tenant_blocked', input: ['verification_id' => $verification->id, 'team_id' => $verification->team_id, 'blocked_reason' => $blocked]);
        }

        $channel = $this->resolveVoiceChannel($verification->team_id);
        $config = PlatformTwilioConfig::resolve($channel?->config_json ?? [], ChannelType::Voice);
        $from = $config['from'];

        if ($channel === null || $from === null || ! PlatformTwilioConfig::hasCredentials()) {
            // A misconfigured channel would fail every retry the same way:
            // close the attempt without consuming the attempts budget.
            $verification->forceFill([
                'status' => CallVerificationStatus::Failed,
                'metadata_json' => ['failure_reason' => 'voice_channel_unavailable'],
            ])->save();

            SystemLog::skipped('incidents.call_verification.skipped', reason: 'no_voice_channel', input: ['verification_id' => $verification->id, 'team_id' => $verification->team_id], calc: [
                'channel_present' => $channel !== null,
                'from_present' => $from !== null,
                'credentials_present' => PlatformTwilioConfig::hasCredentials(),
            ]);

            // Nunca en silencio: el pánico queda escalado y explicado.
            $escalateUnverifiable->execute(
                $incident,
                'voice_channel_unavailable',
                'El canal de voz de SAM no está disponible para este equipo (apagado o sin credenciales).',
            );

            return;
        }

        $incident->loadMissing('asset');

        $started = hrtime(true);

        try {
            $call = $caller->createCall($verification->phone, $from, [
                'twiml' => VerificationCallTwiml::prompt(
                    $verification,
                    $incident,
                    TwilioWebhookUrl::route('webhooks.twilio.voice.gather', ['verification' => $verification->id]),
                ),
                'statusCallback' => TwilioWebhookUrl::route('webhooks.twilio.voice.status', ['verification' => $verification->id]),
                'timeout' => $config['ring_timeout_seconds'] ?? 25,
            ]);
            $durationMs = SystemLog::elapsedMs($started);
        } catch (\Throwable $e) {
            $durationMs = SystemLog::elapsedMs($started);

            // Sin respuesta de Twilio (timeout, red): la llamada pudo haberse
            // creado. Antes de marcar otra (dos teléfonos sonando a la vez y
            // dos cobros), se busca en Twilio.
            $call = $e instanceof RestException ? null : $this->findPlacedCall($caller, $verification->phone, $from, $started);

            if ($call === null) {
                // Sin `error: $e`: el mensaje del proveedor puede traer el
                // número marcado. Sólo la clase de la excepción.
                SystemLog::degraded('incidents.call_verification.placement_failed', reason: 'provider_error', input: ['verification_id' => $verification->id], result: ['error_class' => class_basename($e), 'outcome_known' => $e instanceof RestException], durationMs: $durationMs);

                $verification->forceFill(['notification_channel_id' => $channel->id])->save();
                $handleFailure->execute($verification, 'placement_failed: '.SafeErrorMessage::from($e));

                return;
            }

            SystemLog::ok('incidents.call_verification.placement_adopted', input: ['verification_id' => $verification->id], result: ['error_class' => class_basename($e)], durationMs: $durationMs);
        }

        $verification->forceFill([
            'status' => CallVerificationStatus::Calling,
            'notification_channel_id' => $channel->id,
            'call_sid' => (string) ($call->sid ?? ''),
            'placed_at' => now(),
        ])->save();

        $this->recordUsage($verification, $recordUsage);

        // Cost of the call itself (Twilio price, cost-plus billing): the
        // reconciler fetches it once the call is over.
        $recordCharge->execute(
            teamId: $verification->team_id,
            providerSid: (string) ($call->sid ?? ''),
            resourceType: MessagingResourceType::Call,
            sourceType: MessagingChargeSource::VerificationCall,
            sourceId: $verification->id,
            channelType: ChannelType::Voice,
            status: isset($call->status) ? (string) $call->status : null,
        );

        $configuredDelay = (int) $tenantConfig->resolve(
            $verification->team_id,
            StartIncidentCallVerification::SETTING_RETRY_DELAY,
            StartIncidentCallVerification::DEFAULT_RETRY_DELAY_SECONDS,
        );
        // Nunca antes de que termine la llamada en curso (timbre 25 s + dos
        // lecturas + 10 s de espera de tecla): la red de seguridad no debe
        // marcar otra mientras ésta sigue sonando.
        $retryDelay = max(self::MIN_OUTCOME_DELAY_SECONDS, $configuredDelay);

        EvaluateVerificationCallOutcomeJob::dispatch($verification->id)
            ->delay(now()->addSeconds($retryDelay));

        SystemLog::ok('incidents.call_verification.placed',
            input: $logInput,
            calc: [
                'ring_timeout_seconds' => $config['ring_timeout_seconds'] ?? 25,
                'configured_retry_delay_seconds' => $configuredDelay,
                'retry_delay_seconds' => $retryDelay,
                'min_retry_delay_seconds' => 30,
            ],
            result: [
                'call_sid' => LoggableCode::guard((string) ($call->sid ?? '')),
                'provider_status' => LoggableCode::guard(isset($call->status) ? (string) $call->status : null),
                'channel_id' => $channel->id,
                'safety_net_requested' => true,
            ],
            durationMs: $durationMs,
        );
    }

    /**
     * SAM's platform voice channel, unless the tenant switched it off
     * (Roadmap V2-B1): then no verification calls are placed.
     */
    private function resolveVoiceChannel(int $teamId): ?NotificationChannel
    {
        return NotificationChannel::query()
            ->usableByTeam($teamId)
            ->where('channel_type', ChannelType::Voice)
            ->orderBy('id')
            ->first();
    }

    private function recordUsage(IncidentCallVerification $verification, RecordUsageEvent $recordUsage): void
    {
        if (! UsageMeter::where('code', self::USAGE_METER_CODE)->exists()) {
            return;
        }

        $recordUsage->execute(
            teamId: $verification->team_id,
            meterCode: self::USAGE_METER_CODE,
            quantity: 1,
            eventKey: "voice_call:{$verification->id}",
            metadata: [
                'incident_id' => $verification->incident_id,
                'attempt' => $verification->attempt,
            ],
        );
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'verification_id' => $this->verificationId,
        ]);
    }

    /**
     * La llamada que Twilio sí creó pese a la excepción, sólo si es
     * inequívocamente ésta (TwilioResourceAttribution); si no, null y se
     * sigue como fallo normal. Nunca lanza.
     */
    private function findPlacedCall(TwilioVoiceCaller $caller, string $to, string $from, int $startedHrtime): ?object
    {
        $elapsedSeconds = (int) ceil(SystemLog::elapsedMs($startedHrtime) / 1000);
        $since = now()->subSeconds($elapsedSeconds + 10);

        try {
            $candidates = $caller->findRecentCalls($to, $from, $since);
        } catch (\Throwable) {
            return null;
        }

        return TwilioResourceAttribution::pick($candidates, $since, now()->addMinute())['resource'];
    }
}
