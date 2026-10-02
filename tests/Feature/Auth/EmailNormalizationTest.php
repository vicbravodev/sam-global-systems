<?php

namespace Tests\Feature\Auth;

use App\Domains\Tenancy\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Postgres compara `unique(email)` distinguiendo mayúsculas: sin normalizar,
 * «Foo@x.com» y «foo@x.com» serían dos cuentas distintas y los lookups por
 * email del super-admin podrían reutilizar la equivocada.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_stores_email_lowercase(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'New.Address@Example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('new.address@example.com', $user->fresh()->email);
    }

    public function test_profile_update_rejects_email_taken_with_different_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'TAKEN@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_tenant_store_reuses_existing_user_regardless_of_case(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create();
        $owner = User::factory()->create(['email' => 'owner@acme.test']);
        Plan::factory()->create(['code' => 'pro', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'Acme',
                'plan_code' => 'pro',
                'owner_email' => 'Owner@ACME.test',
            ])
            ->assertRedirect();

        $team = Team::where('name', 'Acme')->firstOrFail();
        $this->assertTrue($owner->fresh()->belongsToTeam($team));
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['owner@acme.test'])->count());
    }

    public function test_admin_tenant_store_provisions_lowercase_owner(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'NewCo',
                'owner_email' => 'Founder@NewCo.test',
                'owner_name' => 'Founder',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'founder@newco.test']);
    }

    public function test_admin_can_add_member_with_mixed_case_email(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->create(['email' => 'member@acme.test']);
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.members.store', $team), [
                'email' => 'MEMBER@acme.test',
                'role' => 'member',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->belongsToTeam($team));
    }

    public function test_password_reset_marks_email_as_verified(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
