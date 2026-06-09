<?php

namespace Tests\Feature\Http\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_super_admin_promotes_a_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.operators.store'), ['email' => $target->email])
            ->assertRedirect(route('admin.operators.index'));

        $this->assertTrue($target->fresh()->isSuperAdmin());
        $this->assertDatabaseHas('audit_logs', ['action' => 'super-admin.promoted']);
    }

    public function test_super_admin_demotes_another_operator(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAs($admin)
            ->delete(route('admin.operators.destroy', $other))
            ->assertRedirect(route('admin.operators.index'));

        $this->assertFalse($other->fresh()->isSuperAdmin());
        $this->assertDatabaseHas('audit_logs', ['action' => 'super-admin.demoted']);
    }

    public function test_operator_cannot_demote_self(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // ensure more than one exists

        $this->actingAs($admin)
            ->delete(route('admin.operators.destroy', $admin))
            ->assertSessionHasErrors('operator');

        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_cannot_demote_the_last_operator(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        // Demote the other → now only $admin remains.
        $this->actingAs($admin)->delete(route('admin.operators.destroy', $other));

        // A second operator promoted then we drop back to one and try again.
        $this->assertSame(1, User::where('global_role', 'super_admin')->count());
    }

    public function test_operator_routes_blocked_for_regular_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.operators.index'))->assertForbidden();
    }
}
