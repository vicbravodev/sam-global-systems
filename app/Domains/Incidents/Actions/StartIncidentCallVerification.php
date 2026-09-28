<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Start (or continue) the operator voice-verification chain for an incident
 * (Roadmap V2-A3). Callees, in order: the driver (operators have no account,
 * only a phone), `voice.verification_contacts`, the escalation steps' phones
 * and the verified phones of the tenant's admins; attempts walk that list
 * round-robin. No phone at all is NOT silent: it goes to the timeline and
 * escalates. Idempotent per incident: an in-flight attempt is reused and a
 * verification that already produced an outcome is never restarted.
 */
class StartIncidentCallVerification
{
    public const string SETTING_ENABLED = 'voice.verification_enabled';

    public const string SETTING_ATTEMPTS = 'voice.call_attempts';

    public const string SETTING_RETRY_DELAY = 'voice.retry_delay_seconds';

    public const string SETTING_CONTACTS = 'voice.verification_contacts';

    public const int DEFAULT_ATTEMPTS = 3;

    public const int DEFAULT_RETRY_DELAY_SECONDS = 90;

    /** Techo de intentos de una cadena, aunque haya más destinatarios. */
    public const int MAX_ATTEMPTS = 6;

    public const string NO_PHONE_REASON = 'no_phone_contact';

    public function __construct(
        private readonly TenantConfigResolver $tenantConfig,
        private readonly EscalateUnverifiableIncident $escalateUnverifiable,
    ) {}

    public function execute(Incident $incident, int $attempt = 1): ?IncidentCallVerification
    {
        if ($incident->team_id === null || $incident->isTerminal()) {
            return null;
        }

        $existing = IncidentCallVerification::query()
            ->where('incident_id', $incident->id)
            ->orderByDesc('attempt')
            ->first();

        if ($existing !== null && $attempt === 1) {
            if ($this->wasSuppressedByHumanControl($existing)) {
                // The previous chain never produced a real outcome — it was
                // closed only because a human had claimed/acknowledged the
                // incident (Roadmap producción semana 1, Task 5). Once the
                // incident is released, a fresh start restarts the chain as
                // the next attempt instead of staying blocked forever.
                $attempt = $existing->attempt + 1;
            } else {
                // A fresh start never duplicates a chain that is already
                // running or already produced an outcome.
                return $existing->status->isInFlight() ? $existing : null;
            }
        }

        if ($existing !== null && $existing->attempt >= $attempt) {
            return $existing;
        }

        // La cadena de destinatarios se resuelve una vez, al primer intento, y
        // viaja en la metadata: los reintentos recorren la MISMA lista aunque
        // la configuración cambie a media llamada.
        $candidates = $existing?->metadata_json['candidates'] ?? null;

        if (! is_array($candidates) || $candidates === []) {
            // Una cadena anterior sin lista guardada (intentos previos a esta
            // versión) conserva su número como primer destinatario.
            $candidates = array_values(array_unique(array_filter([
                $existing?->phone,
                ...$this->resolveCandidates($incident),
            ])));
        }

        if ($candidates === []) {
            Log::info('Incident call verification skipped: no phone contact configured', [
                'incident_id' => $incident->id,
                'team_id' => $incident->team_id,
            ]);

            $this->escalateUnverifiable->execute(
                $incident,
                self::NO_PHONE_REASON,
                'No hay ningún teléfono a quién llamar: ni del chofer, ni de verificación, ni de escalación, ni de un administrador verificado.',
            );

            return null;
        }

        // Chofer primero, luego los contactos de la empresa, en ronda.
        $phone = (string) $candidates[($attempt - 1) % count($candidates)];

        $verification = IncidentCallVerification::query()->firstOrCreate(
            [
                'incident_id' => $incident->id,
                'attempt' => $attempt,
            ],
            [
                'team_id' => $incident->team_id,
                'phone' => $phone,
                'status' => CallVerificationStatus::Pending,
                'metadata_json' => ['candidates' => $candidates],
            ],
        );

        if ($verification->wasRecentlyCreated) {
            PlaceVerificationCallJob::dispatch($verification->id);
        }

        return $verification;
    }

    /**
     * Cuántos intentos hace la cadena: el presupuesto del tenant, pero nunca
     * menos que el número de destinatarios (todos reciben al menos una
     * llamada), con un techo para no marcar sin fin.
     */
    public function attemptBudget(IncidentCallVerification $verification): int
    {
        $configured = max(1, (int) $this->tenantConfig->resolve(
            (int) $verification->team_id,
            self::SETTING_ATTEMPTS,
            self::DEFAULT_ATTEMPTS,
        ));

        $candidates = count((array) ($verification->metadata_json['candidates'] ?? []));

        return min(self::MAX_ATTEMPTS, max($configured, $candidates));
    }

    /**
     * Destinatarios en orden (decisión 2026-09-28): el chofer del incidente
     * (o el asignado a la unidad), luego `voice.verification_contacts`, luego
     * los teléfonos de los pasos de escalación y, como red de seguridad, los
     * admins/supervisores con teléfono verificado. Sin duplicados.
     *
     * @return array<int, string>
     */
    public function resolveCandidates(Incident $incident): array
    {
        $teamId = (int) $incident->team_id;
        $phones = [];

        foreach ($this->driverPhones($incident) as $phone) {
            $phones[] = $phone;
        }

        foreach ((array) $this->tenantConfig->resolve($teamId, self::SETTING_CONTACTS, []) as $contact) {
            $phones[] = is_string($contact) ? PhoneNumber::normalize($contact) : null;
        }

        $config = TenantEscalationConfig::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        foreach ((array) ($config?->steps_json ?? []) as $step) {
            foreach ((array) (is_array($step) ? ($step['contacts'] ?? []) : []) as $contact) {
                $phones[] = is_string($contact) ? PhoneNumber::normalize($contact) : null;
            }
        }

        foreach (IncidentSupervisors::recipients($teamId) as $recipient) {
            $phones[] = is_string($recipient['phone'] ?? null) ? PhoneNumber::normalize($recipient['phone']) : null;
        }

        return array_values(array_unique(array_filter($phones, fn ($phone) => is_string($phone) && $phone !== '')));
    }

    /**
     * @return array<int, string|null>
     */
    private function driverPhones(Incident $incident): array
    {
        $driverId = $incident->driver_id;

        if ($driverId === null && $incident->asset_id !== null) {
            $driverId = DriverAssignment::query()
                ->where('team_id', $incident->team_id)
                ->where('asset_id', $incident->asset_id)
                ->where('assignment_type', AssignmentType::PrimaryDriver)
                ->whereNull('ended_at')
                ->latest('started_at')
                ->value('driver_id');
        }

        if ($driverId === null) {
            return [];
        }

        $driver = Driver::query()->where('team_id', $incident->team_id)->find($driverId);

        if ($driver === null) {
            return [];
        }

        $mobiles = DriverContact::query()
            ->where('driver_id', $driver->id)
            ->where('contact_type', ContactType::MobilePhone)
            ->orderByDesc('is_primary')
            ->pluck('value')
            ->all();

        return array_map(
            fn ($value) => is_string($value) ? PhoneNumber::normalize($value) : null,
            [$driver->phone, ...$mobiles],
        );
    }

    private function isPhone(mixed $contact): bool
    {
        return is_string($contact) && preg_match('/^\+[0-9]{8,15}$/', trim($contact)) === 1;
    }

    private function wasSuppressedByHumanControl(IncidentCallVerification $verification): bool
    {
        return $verification->status === CallVerificationStatus::Failed
            && ($verification->metadata_json['failure_reason'] ?? null) === PlaceVerificationCallJob::SUPPRESSED_FAILURE_REASON;
    }
}
