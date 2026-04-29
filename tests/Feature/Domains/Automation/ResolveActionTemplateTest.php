<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ResolveActionTemplate;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveActionTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_template_takes_precedence_over_system_wide(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        ActionTemplate::factory()
            ->systemWide()
            ->ofType(ActionType::SendEmail)
            ->create(['code' => 'alert_notify', 'name' => 'System default']);

        $tenantTemplate = ActionTemplate::factory()
            ->ofType(ActionType::SendEmail)
            ->create([
                'team_id' => $teamId,
                'code' => 'alert_notify',
                'name' => 'Tenant override',
            ]);

        $resolved = (new ResolveActionTemplate)->execute($teamId, 'alert_notify');

        $this->assertNotNull($resolved);
        $this->assertSame($tenantTemplate->id, $resolved->id);
        $this->assertSame('Tenant override', $resolved->name);
    }

    public function test_falls_back_to_system_wide_when_no_tenant_template(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $system = ActionTemplate::factory()->systemWide()->create(['code' => 'system_only']);

        $resolved = (new ResolveActionTemplate)->execute($teamId, 'system_only');

        $this->assertNotNull($resolved);
        $this->assertSame($system->id, $resolved->id);
    }

    public function test_inactive_templates_are_ignored(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        ActionTemplate::factory()->inactive()->create([
            'team_id' => $teamId,
            'code' => 'inactive_only',
        ]);

        $resolved = (new ResolveActionTemplate)->execute($teamId, 'inactive_only');

        $this->assertNull($resolved);
    }
}
