<?php

namespace App\Domains\Incidents\Listeners;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\IncidentTypeCode;
use App\Domains\Incidents\Events\IncidentCreated;

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

        if ($incident->type?->code !== IncidentTypeCode::PanicEmergency->value) {
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
            return;
        }

        $this->startVerification->execute($incident);
    }
}
