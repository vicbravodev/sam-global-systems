<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\NotificationPreference;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * API `notifications/preferences` (index/update): cada usuario ve y edita
 * sólo SUS preferencias dentro del team de la ruta; nunca las de otro tenant.
 */
class NotificationPreferenceControllerTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_index_lists_the_user_preferences_of_the_current_team(): void
    {
        $own = NotificationPreference::factory()->create(['team_id' => $this->team->id, 'user_id' => $this->user->id]);
        NotificationPreference::factory()->create(['team_id' => $this->team->id, 'user_id' => User::factory()->create()->id]);

        $response = $this->actingAs($this->user)->getJson("/api/{$this->team->slug}/notifications/preferences");

        $response->assertOk();
        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
    }

    public function test_update_creates_the_preference_when_missing(): void
    {
        $response = $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/notifications/preferences", [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email'],
            'muted' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.notification_type', 'incident.created');
        $this->assertDatabaseHas('notification_preferences', [
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'notification_type' => 'incident.created',
            'muted' => true,
        ]);
    }

    public function test_update_changes_an_existing_preference(): void
    {
        $preference = NotificationPreference::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'notification_type' => 'incident.created',
            'muted' => false,
        ]);

        $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/notifications/preferences", [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['web'],
            'muted' => true,
        ])->assertOk();

        $fresh = $preference->fresh();
        $this->assertTrue((bool) $fresh->muted);
        $this->assertSame(['web'], $fresh->allowed_channels_json);
        $this->assertSame(1, NotificationPreference::query()->withoutGlobalScopes()->count());
    }

    public function test_update_rejects_invalid_payload(): void
    {
        $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/notifications/preferences", [
            'allowed_channels' => 'email',
        ])->assertUnprocessable()->assertJsonValidationErrors(['notification_type', 'allowed_channels']);

        $this->assertSame(0, NotificationPreference::query()->withoutGlobalScopes()->count());
    }

    public function test_index_never_returns_preferences_of_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $foreign = NotificationPreference::factory()->create(['team_id' => $otherTeam->id, 'user_id' => $this->user->id]);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->getJson("/api/{$this->team->slug}/notifications/preferences"),
        );

        $response->assertOk()->assertJsonPath('data', []);
        $this->assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
    }

    public function test_update_never_touches_the_same_type_preference_of_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $foreign = NotificationPreference::factory()->create([
            'team_id' => $otherTeam->id,
            'user_id' => $this->user->id,
            'notification_type' => 'incident.created',
            'muted' => false,
        ]);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/notifications/preferences", [
                'notification_type' => 'incident.created',
                'allowed_channels' => ['email'],
                'muted' => true,
            ]),
        );

        $response->assertOk();
        $this->assertSame($this->team->id, $response->json('data.team_id'));
        $this->assertFalse((bool) $foreign->fresh()->muted);
    }

    public function test_member_of_another_team_cannot_use_a_foreign_slug(): void
    {
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->getJson("/api/{$this->team->slug}/notifications/preferences")
            ->assertForbidden();

        $this->actingAs($intruder)->putJson("/api/{$this->team->slug}/notifications/preferences", [
            'notification_type' => 'incident.created',
            'allowed_channels' => ['email'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('notification_preferences', ['team_id' => $this->team->id]);
    }
}
