<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Events\TenantCreated;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TenantAccessInvitation;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Alta de un cliente de punta a punta desde la consola: el super-admin crea
 * el tenant, el dueño recibe su enlace de bienvenida (7 días), define su
 * contraseña y aterriza en su empresa con el paquete por defecto aplicado.
 */
class AdminTenantOnboardingTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private const string PASSWORD = 'Flota-Segura-2026!';

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTenant(User $admin, array $overrides = []): Team
    {
        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), $overrides + [
                'name' => 'Transportes Norte',
                'owner_email' => 'Dueno@Norte.MX',
                'owner_name' => 'Ana Dueña',
                'timezone' => 'America/Monterrey',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Team::query()->where('name', 'Transportes Norte')->sole();
    }

    /**
     * Renderiza el correo como lo haría el worker y devuelve el enlace.
     */
    private function accessLinkFor(User $user, Team $team): string
    {
        $mail = (new TenantAccessInvitation($team->id))->toMail($user);

        return (string) $mail->actionUrl;
    }

    public function test_creating_a_client_provisions_the_owner_and_queues_the_welcome_link(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $team = $this->createTenant($admin);

        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();
        $this->assertFalse($team->is_personal);
        $this->assertSame('America/Monterrey', $team->timezone);
        $this->assertTrue($team->owner()?->is($owner));
        $this->assertNull($owner->email_verified_at, 'Sin activar hasta usar su enlace.');
        $this->assertSame($team->id, $owner->current_team_id, 'Aterriza en su empresa, no en su team personal.');
        $this->assertNotNull($owner->personalTeam());

        Notification::assertSentTo($owner, TenantAccessInvitation::class,
            fn (TenantAccessInvitation $n): bool => $n->teamId === $team->id && $n->invitedById === $admin->id);

        // Paquete por defecto aplicado DENTRO del tenant nuevo.
        $this->assertGreaterThan(0, TenantContext::for($team->id, fn () => TenantSetting::query()->count()));

        // Visible en el visor de auditoría de la consola (categoría Security) con actor.
        $audit = TenantContext::withoutTenant(fn () => AuditLog::query()->where('action', 'tenant.created')->sole());
        $this->assertSame(AuditCategory::Security, $audit->category);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($team->id, $audit->team_id);

        $this->assertSystemLogged('tenancy.user.provisioned');
        $this->assertSystemLogged('tenancy.tenant.onboarded', fn (array $e): bool => $e['result']['owner_created'] === true
            && $e['result']['access_link_queued'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_existing_verified_owner_is_attached_without_mail_or_moving_their_current_team(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'dueno@norte.mx']);
        $previousTeam = $owner->current_team_id;

        $team = $this->createTenant($this->superAdmin(), ['owner_name' => null]);

        $this->assertTrue($team->owner()?->is($owner));
        $this->assertSame($previousTeam, $owner->fresh()?->current_team_id);
        Notification::assertNothingSent();
    }

    public function test_a_new_owner_without_a_name_is_rejected_and_nothing_is_created(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.tenants.store'), [
                'name' => 'Transportes Norte',
                'owner_email' => 'nuevo@norte.mx',
            ])
            ->assertSessionHasErrors('owner_name');

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@norte.mx']);
        $this->assertDatabaseMissing('teams', ['name' => 'Transportes Norte']);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.tenants.store'), [
                'name' => 'Transportes Norte',
                'owner_email' => 'nuevo@norte.mx',
                'owner_name' => 'Ana',
                'timezone' => 'Marte/Olympus',
            ])
            ->assertSessionHasErrors('timezone');
    }

    public function test_a_failure_mid_onboarding_rolls_everything_back_and_sends_no_mail(): void
    {
        Notification::fake();
        Event::listen(TenantCreated::class, fn () => throw new RuntimeException('boom'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->superAdmin())->post(route('admin.tenants.store'), [
                'name' => 'Transportes Norte',
                'owner_email' => 'nuevo@norte.mx',
                'owner_name' => 'Ana',
            ]);
            $this->fail('Se esperaba la excepción del listener.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@norte.mx']);
        $this->assertDatabaseMissing('teams', ['name' => 'Transportes Norte']);
        Notification::assertNothingSent();
    }

    public function test_onboarding_a_client_never_touches_another_tenants_data(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $other = Team::factory()->create(['is_personal' => false]);
        $before = TenantContext::for($other->id, fn () => TenantSetting::query()->count());

        $team = $this->createTenant($admin);

        $this->assertSame($before, TenantContext::for($other->id, fn () => TenantSetting::query()->count()));
        $this->assertSame(0, TenantContext::withoutTenant(fn () => TenantSetting::query()
            ->where('team_id', '!=', $team->id)->where('created_at', '>=', now()->subMinute())->count()));
    }

    public function test_the_owner_activates_their_account_with_the_link_and_lands_in_their_company(): void
    {
        Notification::fake();
        $team = $this->createTenant($this->superAdmin());
        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();
        Auth::logout();

        $url = $this->accessLinkFor($owner, $team);
        $this->assertSystemLogged('tenancy.access_link.sent', fn (array $e): bool => $e['result']['expires_in_days'] === 7);

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = basename($path);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/set-password')
                ->where('email', 'dueno@norte.mx')
                ->where('token', $token));

        $this->post(route('onboarding.store'), [
            'token' => $token,
            'email' => $query['email'],
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('dashboard', ['current_team' => $team->slug]));

        $this->assertAuthenticatedAs($owner);
        $owner->refresh();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame($team->id, $owner->current_team_id);
        $this->assertSystemLogged('tenancy.access_link.activated', fn (array $e): bool => $e['result']['landing_team_id'] === $team->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_link_is_single_use(): void
    {
        Notification::fake();
        $team = $this->createTenant($this->superAdmin());
        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();
        Auth::logout();
        $token = basename((string) parse_url($this->accessLinkFor($owner, $team), PHP_URL_PATH));

        $payload = ['token' => $token, 'email' => $owner->email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->post(route('onboarding.store'), $payload)->assertRedirect();
        Auth::logout();

        $this->post(route('onboarding.store'), $payload)->assertSessionHasErrors('email');
        $this->assertSystemLogged('tenancy.access_link.rejected');
    }

    public function test_the_link_expires_after_seven_days(): void
    {
        Notification::fake();
        $team = $this->createTenant($this->superAdmin());
        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();
        Auth::logout();
        $token = basename((string) parse_url($this->accessLinkFor($owner, $team), PHP_URL_PATH));

        $this->travel(8)->days();

        $this->post(route('onboarding.store'), [
            'token' => $token, 'email' => $owner->email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($owner->fresh()?->email_verified_at);
    }

    public function test_a_resent_link_invalidates_the_previous_one(): void
    {
        Notification::fake();
        $team = $this->createTenant($this->superAdmin());
        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();
        Auth::logout();

        $first = basename((string) parse_url($this->accessLinkFor($owner, $team), PHP_URL_PATH));
        $this->accessLinkFor($owner, $team);

        $this->post(route('onboarding.store'), [
            'token' => $first, 'email' => $owner->email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');
    }

    public function test_the_welcome_mail_is_skipped_once_the_user_already_activated(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $verified = User::factory()->create();

        $this->assertFalse((new TenantAccessInvitation($team->id))->shouldSend($verified, 'mail'));
        $this->assertSystemLogged('tenancy.access_link.skipped', fn (array $e): bool => $e['reason'] === 'already_activated');
    }

    public function test_the_welcome_mail_is_skipped_when_the_tenant_was_deleted(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $pending = User::factory()->unverified()->create();
        $team->delete();

        $this->assertFalse((new TenantAccessInvitation($team->id))->shouldSend($pending, 'mail'));
        $this->assertSystemLogged('tenancy.access_link.skipped', fn (array $e): bool => $e['reason'] === 'team_deleted');
    }

    public function test_adding_an_unknown_email_as_member_creates_the_account_and_sends_access(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.members.store', $team), [
                'email' => 'monitor@norte.mx', 'name' => 'Luis Monitor', 'role' => TeamRole::Member->value,
            ])
            ->assertSessionHasNoErrors();

        $member = User::query()->where('email', 'monitor@norte.mx')->sole();
        $this->assertTrue($team->members()->where('users.id', $member->id)->exists());
        $this->assertSame($team->id, $member->current_team_id);
        Notification::assertSentTo($member, TenantAccessInvitation::class);
    }

    public function test_adding_an_unknown_email_without_a_name_is_rejected(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.tenants.members.store', $team), ['email' => 'monitor@norte.mx', 'role' => 'member'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('users', ['email' => 'monitor@norte.mx']);
    }

    public function test_the_operator_can_resend_access_only_to_pending_members(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        $pending = User::factory()->unverified()->create();
        $active = User::factory()->create();
        $team->members()->attach($pending, ['role' => TeamRole::Member->value]);
        $team->members()->attach($active, ['role' => TeamRole::Member->value]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.members.send-access', [$team, $pending]))
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($pending, TenantAccessInvitation::class);
        $this->assertSame(1, TenantContext::withoutTenant(fn () => AuditLog::query()->where('action', 'tenant.member_access_resent')->count()));

        $this->actingAs($admin)
            ->post(route('admin.tenants.members.send-access', [$team, $active]))
            ->assertSessionHasErrors('member');
        Notification::assertNotSentTo($active, TenantAccessInvitation::class);
    }

    public function test_member_actions_reject_users_outside_the_tenant(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        $stranger = User::factory()->unverified()->create();

        $this->actingAs($admin)->post(route('admin.tenants.members.send-access', [$team, $stranger]))->assertSessionHasErrors('member');
        $this->actingAs($admin)->put(route('admin.tenants.members.update', [$team, $stranger]), ['role' => 'admin'])->assertSessionHasErrors('member');
        $this->actingAs($admin)->delete(route('admin.tenants.members.destroy', [$team, $stranger]))->assertSessionHasErrors('member');

        $this->assertSame(0, TenantContext::withoutTenant(fn () => AuditLog::query()->where('team_id', $team->id)->count()));
        Notification::assertNothingSent();
    }

    public function test_non_operators_cannot_resend_access(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $pending = User::factory()->unverified()->create();
        $team->members()->attach($pending, ['role' => TeamRole::Member->value]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.tenants.members.send-access', [$team, $pending]))
            ->assertForbidden();
    }

    public function test_a_team_created_from_settings_by_the_operator_is_a_fully_provisioned_tenant(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('teams.store'), ['name' => 'Laboratorio SAM'])->assertRedirect();

        $team = Team::query()->where('name', 'Laboratorio SAM')->sole();
        $this->assertFalse($team->is_personal);
        $this->assertGreaterThan(0, TenantContext::for($team->id, fn () => TenantSetting::query()->count()));
    }

    /**
     * @return array{token: string, query: array<string, mixed>}
     */
    private function linkParts(User $user, Team $team): array
    {
        $url = $this->accessLinkFor($user, $team);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['token' => basename((string) parse_url($url, PHP_URL_PATH)), 'query' => $query];
    }

    public function test_a_user_with_confirmed_two_factor_is_not_logged_in_by_the_link(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->unverified()->withTwoFactor()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
        ['token' => $token] = $this->linkParts($user, $team);

        $this->post(route('onboarding.store'), [
            'token' => $token, 'email' => $user->email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertSystemLogged('tenancy.access_link.activated', fn (array $e): bool => $e['result']['requires_two_factor'] === true);
    }

    public function test_the_token_only_works_for_the_email_it_was_issued_to(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $victim = User::factory()->unverified()->create();
        $attacker = User::factory()->unverified()->create();
        $team->members()->attach($victim, ['role' => TeamRole::Member->value]);
        ['token' => $token] = $this->linkParts($victim, $team);

        $this->post(route('onboarding.store'), [
            'token' => $token, 'email' => $attacker->email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($attacker->fresh()?->email_verified_at);
        $this->assertSystemLogged('tenancy.access_link.rejected');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_link_lands_on_the_invited_tenant_when_the_user_belongs_to_several(): void
    {
        $invited = Team::factory()->create(['is_personal' => false]);
        $newer = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->unverified()->create();
        $invited->members()->attach($user, ['role' => TeamRole::Member->value]);
        $newer->members()->attach($user, ['role' => TeamRole::Member->value]);
        ['token' => $token, 'query' => $query] = $this->linkParts($user, $invited);

        $this->post(route('onboarding.store'), [
            'token' => $token, 'email' => $user->email, 'team' => $query['team'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('dashboard', ['current_team' => $invited->slug]));
    }

    public function test_a_team_slug_the_user_does_not_belong_to_is_ignored(): void
    {
        $own = Team::factory()->create(['is_personal' => false]);
        $foreign = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->unverified()->create();
        $own->members()->attach($user, ['role' => TeamRole::Member->value]);
        ['token' => $token] = $this->linkParts($user, $own);

        $this->post(route('onboarding.store'), [
            'token' => $token, 'email' => $user->email, 'team' => $foreign->slug,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('dashboard', ['current_team' => $own->slug]));

        $this->assertSame($own->id, $user->fresh()?->current_team_id);
    }

    public function test_the_welcome_mail_is_always_in_spanish(): void
    {
        Notification::fake();
        app()->setLocale('en');
        $this->createTenant($this->superAdmin());
        $owner = User::query()->where('email', 'dueno@norte.mx')->sole();

        Notification::assertSentTo($owner, TenantAccessInvitation::class,
            fn (TenantAccessInvitation $n): bool => $n->locale === 'es');
    }
}
