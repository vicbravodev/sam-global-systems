<?php

namespace App\Domains\Assets\Listeners;

use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Support\TenantContext;

/**
 * Closing the telematics circuit: a rejected token takes the integration to
 * `error`, which stops its feed. When the tenant fixes the credentials and the
 * integration is `active` again, its cursors start clean — no leftover
 * backoff pause or failure count delaying the first cycle.
 */
class ResumeTelematicsFeedOnIntegrationActivated
{
    public function handle(IntegrationStatusChanged $event): void
    {
        if ($event->status !== TenantIntegrationStatus::Active->value) {
            return;
        }

        TenantContext::for($event->teamId, fn () => TelematicsFeedCursor::query()
            ->where('team_id', $event->teamId)
            ->where('tenant_integration_id', $event->integrationId)
            ->update([
                'consecutive_failures' => 0,
                'paused_until' => null,
                'last_error' => null,
            ]));
    }
}
