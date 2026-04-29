<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Actions\ResolveTenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTenantNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_persisted_policy_for_matching_type(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'incident',
            'notification_type' => 'incident_created',
            'priority' => null,
            'allowed_channels_json' => ['email', 'sms'],
            'fallback_channels_json' => ['email'],
            'is_active' => true,
        ]);

        $resolved = app(ResolveTenantNotificationPolicy::class)
            ->resolve($team->id, 'incident_created', 'high');

        $this->assertTrue($resolved->isPersisted);
        $this->assertSame(['email', 'sms'], $resolved->allowedChannels);
    }

    public function test_returns_default_when_no_match(): void
    {
        $team = User::factory()->create()->currentTeam;

        $resolved = app(ResolveTenantNotificationPolicy::class)
            ->resolve($team->id, 'unknown', 'high');

        $this->assertFalse($resolved->isPersisted);
        $this->assertSame(['email'], $resolved->allowedChannels);
        $this->assertSame('system_default', $resolved->policyCode);
    }

    public function test_more_specific_match_wins_when_multiple_apply(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'broad',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['email'],
            'is_active' => true,
        ]);

        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'targeted',
            'notification_type' => 'incident_created',
            'priority' => 'high',
            'allowed_channels_json' => ['sms', 'voice'],
            'is_active' => true,
        ]);

        $resolved = app(ResolveTenantNotificationPolicy::class)
            ->resolve($team->id, 'incident_created', 'high');

        $this->assertSame('targeted', $resolved->policyCode);
        $this->assertSame(['sms', 'voice'], $resolved->allowedChannels);
    }
}
