<?php

namespace Tests\Feature\Domains\Notifications;

use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La app del chofer en Samsara sólo sirve a avisos HOS al chofer (PR 2):
 * ninguna política ni preferencia del equipo puede elegirla.
 */
class DriverAppChannelNotOfferedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_tenant_notification_policies_reject_the_driver_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('tenant-config.notifications.update', ['current_team' => $user->currentTeam->slug]), ['policies' => [[
                'policy_code' => 'default',
                'allowed_channels' => ['samsara_driver_app'],
                'fallback_channels' => ['samsara_driver_app'],
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['policies.0.allowed_channels.0', 'policies.0.fallback_channels.0']);
    }

    public function test_personal_preferences_reject_the_driver_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('notification-preferences.update'), [
                'notification_type' => 'incident.created',
                'allowed_channels' => ['samsara_driver_app'],
            ])
            ->assertSessionHasErrors('allowed_channels.0');

        $this->actingAs($user)
            ->putJson("/api/{$user->currentTeam->slug}/notifications/preferences", [
                'notification_type' => 'incident.created',
                'allowed_channels' => ['samsara_driver_app'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allowed_channels.0']);
    }
}
