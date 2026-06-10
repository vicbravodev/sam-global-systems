<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationPreferencesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('notification-preferences.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_page_renders_user_preferences_and_known_types(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        NotificationPreference::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'notification_type' => 'incident.created',
            'allowed_channels_json' => ['email', 'web'],
            'muted' => false,
        ]);

        // A type observed in the tenant's notification log must be offered.
        Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.panic_emergency.created',
        ]);

        $response = $this->actingAs($user)->get(route('notification-preferences.edit'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('settings/notifications')
                ->has('preferences', 1)
                ->has(
                    'preferences.0',
                    fn (Assert $row) => $row
                        ->where('notificationType', 'incident.created')
                        ->where('allowedChannels', ['email', 'web'])
                        ->where('muted', false)
                        ->etc(),
                )
                ->where(
                    'knownTypes',
                    fn ($types) => collect($types)->contains('incident.panic_emergency.created')
                        && collect($types)->contains('incident.sla_breached'),
                )
                ->has('channelOptions'),
        );
    }

    public function test_preferences_of_other_users_are_not_listed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $other = User::factory()->create();

        NotificationPreference::factory()->create([
            'team_id' => $team->id,
            'user_id' => $other->id,
            'notification_type' => 'incident.created',
            'allowed_channels_json' => ['sms'],
        ]);

        $response = $this->actingAs($user)->get(route('notification-preferences.edit'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('settings/notifications')
                ->has('preferences', 0),
        );
    }

    public function test_update_creates_a_preference_for_the_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->put(route('notification-preferences.update'), [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email', 'push'],
            'muted' => true,
        ]);

        $response->assertRedirect(route('notification-preferences.edit'));

        $preference = NotificationPreference::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('notification_type', 'incident.created')
            ->sole();

        $this->assertSame($team->id, $preference->team_id);
        $this->assertSame(['email', 'push'], $preference->allowed_channels_json);
        $this->assertTrue($preference->muted);
    }

    public function test_update_is_an_idempotent_upsert(): void
    {
        $user = User::factory()->create();

        $payload = [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email'],
            'muted' => false,
        ];

        $this->actingAs($user)->put(route('notification-preferences.update'), $payload)
            ->assertRedirect();
        $this->actingAs($user)->put(route('notification-preferences.update'), [
            ...$payload,
            'allowed_channels' => ['email', 'web'],
        ])->assertRedirect();

        $preferences = NotificationPreference::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('notification_type', 'incident.created')
            ->get();

        $this->assertCount(1, $preferences);
        $this->assertSame(['email', 'web'], $preferences->first()->allowed_channels_json);
    }

    public function test_update_rejects_unknown_channels(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('notification-preferences.update'), [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['carrier-pigeon'],
        ]);

        $response->assertSessionHasErrors('allowed_channels.0');
        $this->assertSame(0, NotificationPreference::withoutGlobalScopes()->count());
    }

    public function test_update_cannot_touch_another_users_preference(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $other = User::factory()->create();
        $foreign = NotificationPreference::factory()->create([
            'team_id' => $team->id,
            'user_id' => $other->id,
            'notification_type' => 'incident.created',
            'allowed_channels_json' => ['sms'],
            'muted' => false,
        ]);

        $this->actingAs($user)->put(route('notification-preferences.update'), [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email'],
            'muted' => true,
        ])->assertRedirect();

        // The other user's row is untouched; the actor got their own row.
        $this->assertSame(['sms'], $foreign->fresh()->allowed_channels_json);
        $this->assertFalse($foreign->fresh()->muted);
        $this->assertSame(2, NotificationPreference::withoutGlobalScopes()
            ->where('notification_type', 'incident.created')
            ->count());
    }

    public function test_update_does_not_leak_into_other_teams(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $foreignOwner = User::factory()->create();

        $this->actingAs($user)->put(route('notification-preferences.update'), [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email'],
        ])->assertRedirect();

        $this->assertSame(0, NotificationPreference::withoutGlobalScopes()
            ->where('team_id', $foreignOwner->currentTeam->id)
            ->count());
        $this->assertSame(1, NotificationPreference::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count());
    }
}
