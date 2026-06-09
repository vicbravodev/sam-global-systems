<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAuditViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_cross_tenant_security_audit(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $team = Team::factory()->create(['is_personal' => false]);

        AuditLog::factory()->create([
            'team_id' => $team->id,
            'category' => AuditCategory::Security,
            'action' => 'impersonation.started',
            'summary' => 'test entry',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/audit/index')
                    ->has('entries', 1)
                    ->where('entries.0.action', 'impersonation.started'),
            );
    }

    public function test_audit_viewer_blocked_for_regular_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
    }
}
