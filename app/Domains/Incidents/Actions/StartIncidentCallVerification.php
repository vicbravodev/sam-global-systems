<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use Illuminate\Support\Facades\Log;

/**
 * Start (or continue) the operator voice-verification chain for an incident
 * (Roadmap V2-A3). The callee resolves from `voice.verification_contacts`
 * (tenant setting, first E.164 entry) with the phone-like contacts of the
 * tenant's escalation steps as fallback. Idempotent per incident: an
 * in-flight attempt is reused and a verification that already produced an
 * outcome is never restarted.
 */
class StartIncidentCallVerification
{
    public const string SETTING_ENABLED = 'voice.verification_enabled';

    public const string SETTING_ATTEMPTS = 'voice.call_attempts';

    public const string SETTING_RETRY_DELAY = 'voice.retry_delay_seconds';

    public const string SETTING_CONTACTS = 'voice.verification_contacts';

    public const int DEFAULT_ATTEMPTS = 3;

    public const int DEFAULT_RETRY_DELAY_SECONDS = 90;

    public function __construct(
        private readonly TenantConfigResolver $tenantConfig,
    ) {}

    public function execute(Incident $incident, int $attempt = 1): ?IncidentCallVerification
    {
        if ($incident->team_id === null || $incident->isTerminal()) {
            return null;
        }

        $existing = IncidentCallVerification::withoutGlobalScopes()
            ->where('incident_id', $incident->id)
            ->orderByDesc('attempt')
            ->first();

        if ($existing !== null && $attempt === 1) {
            // A fresh start never duplicates a chain that is already running
            // or already produced an outcome.
            return $existing->status->isInFlight() ? $existing : null;
        }

        if ($existing !== null && $existing->attempt >= $attempt) {
            return $existing;
        }

        // Retries call the same number the chain started with; only a fresh
        // chain resolves the contact from configuration.
        $phone = $existing?->phone ?? $this->resolvePhone((int) $incident->team_id);

        if ($phone === null) {
            Log::info('Incident call verification skipped: no phone contact configured', [
                'incident_id' => $incident->id,
                'team_id' => $incident->team_id,
            ]);

            return null;
        }

        $verification = IncidentCallVerification::withoutGlobalScopes()->firstOrCreate(
            [
                'incident_id' => $incident->id,
                'attempt' => $attempt,
            ],
            [
                'team_id' => $incident->team_id,
                'phone' => $phone,
                'status' => CallVerificationStatus::Pending,
            ],
        );

        if ($verification->wasRecentlyCreated) {
            PlaceVerificationCallJob::dispatch($verification->id);
        }

        return $verification;
    }

    /**
     * First E.164 phone from `voice.verification_contacts`, falling back to
     * the phone-like contacts declared in the tenant's escalation steps.
     */
    private function resolvePhone(int $teamId): ?string
    {
        $contacts = $this->tenantConfig->resolve($teamId, self::SETTING_CONTACTS, []);

        foreach ((array) $contacts as $contact) {
            if ($this->isPhone($contact)) {
                return trim((string) $contact);
            }
        }

        $config = TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        foreach ((array) ($config?->steps_json ?? []) as $step) {
            foreach ((array) (is_array($step) ? ($step['contacts'] ?? []) : []) as $contact) {
                if ($this->isPhone($contact)) {
                    return trim((string) $contact);
                }
            }
        }

        return null;
    }

    private function isPhone(mixed $contact): bool
    {
        return is_string($contact) && preg_match('/^\+[0-9]{8,15}$/', trim($contact)) === 1;
    }
}
