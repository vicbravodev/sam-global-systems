<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Con el auto-registro cerrado, la invitación es la única puerta de entrada
 * de un usuario nuevo a un tenant: el enlace del correo prueba la posesión
 * del buzón, así que el alta por invitación deja el email verificado.
 */
class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(array $attributes = []): TeamInvitation
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Acme Logística']);
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        return TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email' => 'nuevo@acme.test',
            'role' => TeamRole::Admin,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(3),
            ...$attributes,
        ]);
    }

    private function signup(TeamInvitation $invitation, array $overrides = [])
    {
        return $this->post(route('invitations.register', $invitation), [
            'name' => 'Nuevo Usuario',
            'password' => 'secreto-seguro-123',
            'password_confirmation' => 'secreto-seguro-123',
            ...$overrides,
        ]);
    }

    public function test_guest_without_account_sees_signup_form(): void
    {
        $invitation = $this->invitation();

        $this->get(route('invitations.show', $invitation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('mode', 'register')
                ->where('email', 'nuevo@acme.test')
                ->where('teamName', 'Acme Logística')
                ->where('roleLabel', TeamRole::Admin->label())
                ->where('code', $invitation->code));
    }

    public function test_guest_signup_creates_verified_user_membership_and_logs_in(): void
    {
        $invitation = $this->invitation();

        $response = $this->signup($invitation);

        $user = User::where('email', 'nuevo@acme.test')->firstOrFail();
        $team = $invitation->team;

        $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Nuevo Usuario', $user->name);
        $this->assertSame(TeamRole::Admin, $user->teamRole($team));
        $this->assertNotNull($user->personalTeam(), 'Todo usuario conserva su equipo personal.');
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_guest_signup_validates_password(): void
    {
        $invitation = $this->invitation();

        $this->signup($invitation, ['password_confirmation' => 'otra-cosa'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@acme.test']);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_guest_signup_ignores_a_submitted_email(): void
    {
        $invitation = $this->invitation();

        $this->signup($invitation, ['email' => 'otro@evil.test']);

        $this->assertDatabaseHas('users', ['email' => 'nuevo@acme.test']);
        $this->assertDatabaseMissing('users', ['email' => 'otro@evil.test']);
    }

    public function test_expired_invitation_cannot_be_used_to_sign_up(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subMinute()]);

        $this->get(route('invitations.show', $invitation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('mode', 'invalid'));

        $this->signup($invitation)->assertSessionHasErrors('invitation');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@acme.test']);
    }

    public function test_used_invitation_cannot_be_used_to_sign_up(): void
    {
        $invitation = $this->invitation(['accepted_at' => now()]);

        $this->signup($invitation)->assertSessionHasErrors('invitation');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@acme.test']);
    }

    public function test_signup_is_refused_when_an_account_already_exists_for_the_email(): void
    {
        $existing = User::factory()->create(['email' => 'nuevo@acme.test', 'password' => 'original-pass']);
        $invitation = $this->invitation();

        $this->signup($invitation)->assertSessionHasErrors('invitation');

        $this->assertGuest();
        $this->assertFalse($existing->fresh()->belongsToTeam($invitation->team));
    }

    public function test_guest_with_existing_account_is_asked_to_log_in_and_comes_back(): void
    {
        User::factory()->create(['email' => 'nuevo@acme.test']);
        $invitation = $this->invitation();

        $this->get(route('invitations.show', $invitation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('mode', 'login'))
            ->assertSessionHas('url.intended', route('invitations.show', $invitation));
    }

    public function test_logged_in_invitee_sees_accept_button(): void
    {
        $user = User::factory()->create(['email' => 'nuevo@acme.test']);
        $invitation = $this->invitation();

        $this->actingAs($user)
            ->get(route('invitations.show', $invitation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('mode', 'accept'));
    }

    public function test_logged_in_user_with_other_email_sees_wrong_account(): void
    {
        $user = User::factory()->create(['email' => 'otro@acme.test']);
        $invitation = $this->invitation();

        $this->actingAs($user)
            ->get(route('invitations.show', $invitation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('mode', 'wrong_account'));
    }

    public function test_acceptance_via_get_is_not_possible(): void
    {
        $user = User::factory()->create(['email' => 'nuevo@acme.test']);
        $invitation = $this->invitation();

        $this->actingAs($user)->get("/invitations/{$invitation->code}/accept");

        $this->assertFalse($user->fresh()->belongsToTeam($invitation->team));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_logged_in_invitee_accepts_via_post_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'nuevo@acme.test']);
        $invitation = $this->invitation(['email' => 'Nuevo@ACME.test']);

        $this->actingAs($user)
            ->post(route('invitations.accept', $invitation))
            ->assertRedirect(route('dashboard', ['current_team' => $invitation->team->slug]));

        $this->assertSame(TeamRole::Admin, $user->teamRole($invitation->team));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accept_rejects_wrong_email_expired_and_used(): void
    {
        $other = User::factory()->create(['email' => 'otro@acme.test']);
        $invitation = $this->invitation();

        $this->actingAs($other)
            ->post(route('invitations.accept', $invitation))
            ->assertSessionHasErrors('invitation');
        $this->assertFalse($other->fresh()->belongsToTeam($invitation->team));

        $invitee = User::factory()->create(['email' => 'nuevo@acme.test']);

        $invitation->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($invitee)
            ->post(route('invitations.accept', $invitation))
            ->assertSessionHasErrors('invitation');

        $invitation->update(['expires_at' => now()->addDay(), 'accepted_at' => now()]);
        $this->actingAs($invitee)
            ->post(route('invitations.accept', $invitation))
            ->assertSessionHasErrors('invitation');

        $this->assertFalse($invitee->fresh()->belongsToTeam($invitation->team));
    }

    public function test_accept_requires_authentication(): void
    {
        $invitation = $this->invitation();

        $this->post(route('invitations.accept', $invitation))->assertRedirect(route('login'));
    }

    public function test_invitation_email_links_to_the_invitation_page(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        $this->actingAs($owner)->post(route('teams.invitations.store', $team), [
            'email' => 'Invitado@Example.com',
            'role' => TeamRole::Member->value,
        ])->assertSessionHasNoErrors();

        $invitation = TeamInvitation::where('email', 'invitado@example.com')->firstOrFail();

        Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification) use ($invitation) {
            $mail = $notification->toMail(new \stdClass);

            return $mail->actionUrl === route('invitations.show', $invitation);
        });
    }

    public function test_owner_role_cannot_be_granted_through_an_invitation(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

        foreach ([$owner, $admin] as $inviter) {
            $this->actingAs($inviter)
                ->post(route('teams.invitations.store', $team), [
                    'email' => 'escalada@example.com',
                    'role' => TeamRole::Owner->value,
                ])
                ->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('team_invitations', ['email' => 'escalada@example.com']);
    }

    public function test_team_edit_page_never_exposes_invitation_codes(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
        ]);

        foreach ([$member, $owner] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('teams.edit', $team));

            $response->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('teams/edit')
                    ->where('invitations.0.id', $invitation->id)
                    ->missing('invitations.0.code'));

            $this->assertStringNotContainsString($invitation->code, $response->getContent());
        }
    }
}
