<?php

namespace Tests\Feature\Http\Middleware;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTenantNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-30: un tenant con la suscripción vigente SUSPENDIDA no
 * entra a su consola (web ni API) — 423 —, pero los super-admins sí y los
 * demás tenants del mismo usuario no se ven afectados.
 */
class EnsureTenantNotSuspendedTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private Team $suspended;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->suspended = Team::factory()->create(['name' => 'Transportes Suspendidos']);
        $this->suspended->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        Subscription::factory()->suspended()->create(['team_id' => $this->suspended->id]);
    }

    public function test_a_member_of_a_suspended_tenant_gets_the_suspended_page_with_423(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $this->suspended->slug]))
            ->assertStatus(423)
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/tenant-suspended')
                ->where('teamName', 'Transportes Suspendidos')
                // Su team personal (sin suscripción) sigue disponible.
                ->has('otherTeams', 1)
                ->where('otherTeams.0.slug', $this->owner->personalTeam()?->slug));

        $context = $this->assertSystemLogged('tenancy.web_access.denied', fn (array $c): bool => $c['reason'] === EnsureTenantNotSuspended::REASON
            && $c['input']['team_id'] === $this->suspended->id
            && $c['input']['user_id'] === $this->owner->id
            && $c['input']['route_name'] === 'dashboard'
            && $c['input']['wants_json'] === false);
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('debug', $this->systemLogEntries('tenancy.web_access.denied')[0]['level']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_inertia_visits_also_get_the_page_not_json(): void
    {
        $this->actingAs($this->owner)
            ->get(route('incidents.index', ['current_team' => $this->suspended->slug]), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertStatus(423)
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'errors/tenant-suspended');
    }

    public function test_mutations_of_a_suspended_tenant_are_blocked_too(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('automation.workflows.store', ['current_team' => $this->suspended->slug]), ['name' => 'x'])
            ->assertStatus(423)
            ->assertJson(['reason' => EnsureTenantNotSuspended::REASON]);
    }

    public function test_json_endpoints_of_a_suspended_tenant_get_json_423(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('palette.search', ['current_team' => $this->suspended->slug, 'q' => 'abc']))
            ->assertStatus(423)
            ->assertJson(['reason' => EnsureTenantNotSuspended::REASON]);

        $this->actingAs($this->owner)
            ->getJson(route('api.integrations.index', ['current_team' => $this->suspended->slug]))
            ->assertStatus(423)
            ->assertJson(['reason' => EnsureTenantNotSuspended::REASON]);

        $this->assertSystemLogged('tenancy.web_access.denied', fn (array $c): bool => $c['input']['route_name'] === 'api.integrations.index'
            && $c['input']['wants_json'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_copilot_sse_stream_gets_json_423(): void
    {
        $this->actingAs($this->owner)
            ->post(route('copilot.stream', ['current_team' => $this->suspended->slug]), ['message' => 'hola'], [
                'Accept' => 'text/event-stream',
            ])
            ->assertStatus(423)
            ->assertJson(['reason' => EnsureTenantNotSuspended::REASON]);
    }

    public function test_the_same_user_still_enters_another_active_team(): void
    {
        $active = Team::factory()->create();
        $active->members()->attach($this->owner, ['role' => TeamRole::Member->value]);
        Subscription::factory()->create(['team_id' => $active->id, 'status' => SubscriptionStatus::Active]);

        $this->assertNoTenantLeak($active, fn () => $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $active->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')));

        $this->assertSystemNotLogged('tenancy.web_access.denied');

        // Y la página de la suspendida le ofrece entrar a la activa.
        $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $this->suspended->slug]))
            ->assertStatus(423)
            ->assertInertia(fn (Assert $page) => $page
                ->has('otherTeams', 2)
                ->where('otherTeams', fn ($teams): bool => collect($teams)->pluck('slug')->contains($active->slug)));
    }

    public function test_other_suspended_teams_are_not_offered(): void
    {
        $alsoSuspended = Team::factory()->create();
        $alsoSuspended->members()->attach($this->owner, ['role' => TeamRole::Member->value]);
        Subscription::factory()->suspended()->create(['team_id' => $alsoSuspended->id]);

        $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $this->suspended->slug]))
            ->assertStatus(423)
            ->assertInertia(fn (Assert $page) => $page
                ->where('otherTeams', fn ($teams): bool => ! collect($teams)->pluck('slug')->contains($alsoSuspended->slug)));
    }

    public function test_an_active_tenant_is_not_affected(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create(['team_id' => $user->currentTeam->id, 'status' => SubscriptionStatus::Active]);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk();

        $this->assertSystemNotLogged('tenancy.web_access.denied');
    }

    public function test_canceled_expired_and_past_due_keep_their_current_behavior(): void
    {
        foreach (['canceled', 'expired', 'pastDue'] as $state) {
            $user = User::factory()->create();
            Subscription::factory()->{$state}()->create(['team_id' => $user->currentTeam->id]);

            $this->actingAs($user)
                ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
                ->assertOk();
        }

        $this->assertSystemNotLogged('tenancy.web_access.denied');
    }

    public function test_the_latest_subscription_decides(): void
    {
        // Una suspensión vieja no bloquea si la vigente (más reciente) está activa.
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Subscription::factory()->suspended()->create(['team_id' => $team->id, 'starts_at' => now()->subMonth()]);
        Subscription::factory()->create(['team_id' => $team->id, 'starts_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk();
    }

    public function test_a_non_member_gets_403_and_never_learns_about_the_suspension(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('palette.search', ['current_team' => $this->suspended->slug]))
            ->assertForbidden();

        $this->assertSystemNotLogged('tenancy.web_access.denied');
    }

    public function test_super_admins_are_never_blocked(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['current_team' => $this->suspended->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $this->suspended))
            ->assertOk();

        $this->assertSystemNotLogged('tenancy.web_access.denied');
    }

    public function test_personal_settings_and_logout_stay_reachable(): void
    {
        $this->owner->switchTeam($this->suspended);

        $this->actingAs($this->owner)->get(route('profile.edit'))->assertOk();
        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();
        $this->actingAs($this->owner)->get(route('teams.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('teams.switch', ['team' => $this->owner->personalTeam()]))
            ->assertRedirect();

        $this->actingAs($this->owner)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_reactivating_the_subscription_restores_access(): void
    {
        $url = route('dashboard', ['current_team' => $this->suspended->slug]);
        $this->actingAs($this->owner)->get($url)->assertStatus(423);

        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $this->actingAs($admin)
            ->post(route('admin.tenants.subscription.reactivate', $this->suspended))
            ->assertRedirect();

        $this->actingAs($this->owner)->get($url)->assertOk();
    }
}
