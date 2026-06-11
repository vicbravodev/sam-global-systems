<?php

namespace App\Domains\Incidents\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\VerificationCallTwiml;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Place one operator verification call through the tenant's (or SAM's
 * platform) Twilio voice channel (Roadmap V2-A3). The DTMF answer arrives at
 * the gather webhook; Twilio's status callback reports unanswered calls, and
 * a delayed `EvaluateVerificationCallOutcomeJob` acts as the safety net when
 * no callback ever lands.
 */
class PlaceVerificationCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string USAGE_METER_CODE = 'voice_calls';

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
    ): void {
        $verification = IncidentCallVerification::withoutGlobalScopes()->find($this->verificationId);

        if ($verification === null || $verification->status !== CallVerificationStatus::Pending) {
            return;
        }

        $incident = Incident::withoutGlobalScopes()->find($verification->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            $verification->forceFill([
                'status' => CallVerificationStatus::Failed,
                'metadata_json' => ['failure_reason' => 'incident_terminal'],
            ])->save();

            return;
        }

        $channel = $this->resolveVoiceChannel((int) $verification->team_id);
        $config = $channel?->config_json ?? [];
        $from = $config['from'] ?? null;
        $sid = $config['twilio_account_sid'] ?? $config['account_sid'] ?? null;
        $token = $config['twilio_auth_token'] ?? $config['auth_token'] ?? null;

        if ($channel === null || ! is_string($from) || $from === '' || ! is_string($sid) || $sid === '' || ! is_string($token) || $token === '') {
            // A misconfigured channel would fail every retry the same way:
            // close the attempt without consuming the attempts budget.
            $verification->forceFill([
                'status' => CallVerificationStatus::Failed,
                'metadata_json' => ['failure_reason' => 'voice_channel_unavailable'],
            ])->save();

            Log::warning('Verification call skipped: no usable voice channel', [
                'verification_id' => $verification->id,
                'team_id' => $verification->team_id,
            ]);

            return;
        }

        $incident->loadMissing('asset');

        try {
            $call = $caller->createCall($config, $verification->phone, $from, [
                'twiml' => VerificationCallTwiml::prompt(
                    $verification,
                    $incident,
                    route('webhooks.twilio.voice.gather', ['verification' => $verification->id]),
                ),
                'statusCallback' => route('webhooks.twilio.voice.status', ['verification' => $verification->id]),
                'timeout' => (int) ($config['ring_timeout_seconds'] ?? 25),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Verification call placement failed', [
                'verification_id' => $verification->id,
                'error' => $e->getMessage(),
            ]);

            $verification->forceFill(['notification_channel_id' => $channel->id])->save();
            $handleFailure->execute($verification, 'placement_failed: '.$e->getMessage());

            return;
        }

        $verification->forceFill([
            'status' => CallVerificationStatus::Calling,
            'notification_channel_id' => $channel->id,
            'call_sid' => (string) ($call->sid ?? ''),
            'placed_at' => now(),
        ])->save();

        $this->recordUsage($verification, $recordUsage);

        $retryDelay = max(30, (int) $tenantConfig->resolve(
            (int) $verification->team_id,
            StartIncidentCallVerification::SETTING_RETRY_DELAY,
            StartIncidentCallVerification::DEFAULT_RETRY_DELAY_SECONDS,
        ));

        EvaluateVerificationCallOutcomeJob::dispatch($verification->id)
            ->delay(now()->addSeconds($retryDelay));
    }

    /**
     * The tenant's own voice channel wins over SAM's platform-wide one.
     */
    private function resolveVoiceChannel(int $teamId): ?NotificationChannel
    {
        return NotificationChannel::withoutGlobalScopes()
            ->where('channel_type', ChannelType::Voice)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('team_id IS NULL')
            ->first();
    }

    private function recordUsage(IncidentCallVerification $verification, RecordUsageEvent $recordUsage): void
    {
        if (! UsageMeter::where('code', self::USAGE_METER_CODE)->exists()) {
            return;
        }

        $recordUsage->execute(
            teamId: (int) $verification->team_id,
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
        Log::warning('PlaceVerificationCallJob failed', [
            'verification_id' => $this->verificationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
