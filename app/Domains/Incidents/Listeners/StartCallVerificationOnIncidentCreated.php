<?php

namespace App\Domains\Incidents\Listeners;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\IncidentTypeCode;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap V2-A3: every panic incident triggers the operator voice
 * verification — REGARDLESS of the AI verdict; even a probable false alarm
 * gets verified by phone. ON by default (decisión 2026-09-28: a panic is
 * always verified); a tenant may opt out with `voice.verification_enabled`.
 */
class StartCallVerificationOnIncidentCreated
{
    public function __construct(
        private readonly TenantConfigResolver $tenantConfig,
        private readonly StartIncidentCallVerification $startVerification,
    ) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $incident->loadMissing('type');

        $logInput = ['incident_id' => $incident->id, 'incident_type_code' => $incident->type?->code];

        if ($incident->type?->code !== IncidentTypeCode::PanicEmergency->value) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.call_verification.skipped', reason: 'not_panic', input: $logInput, debug: true));

            return;
        }

        $enabled = filter_var(
            $this->tenantConfig->resolve(
                (int) $incident->team_id,
                StartIncidentCallVerification::SETTING_ENABLED,
                true,
            ),
            FILTER_VALIDATE_BOOL,
        );

        if (! $enabled) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.call_verification.skipped', reason: 'disabled_by_tenant', input: $logInput, calc: ['setting_key' => StartIncidentCallVerification::SETTING_ENABLED]));

            return;
        }

        $this->startVerification->execute($incident);
    }
}
