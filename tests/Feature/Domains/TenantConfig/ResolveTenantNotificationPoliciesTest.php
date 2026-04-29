<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy as TenantNotificationPolicyData;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Actions\ResolveTenantNotificationPolicies;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveTenantNotificationPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_resolves_to_tenantconfig_action(): void
    {
        $this->assertInstanceOf(
            ResolveTenantNotificationPolicies::class,
            app(TenantNotificationPoliciesResolver::class),
        );
    }

    public function test_returns_defaults_when_no_global_policy_row_exists(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $policy = app(TenantNotificationPoliciesResolver::class)->resolve($team);

        $defaults = TenantNotificationPolicyData::defaults();
        $this->assertEquals($defaults->allowedChannels, $policy->allowedChannels);
        $this->assertEquals($defaults->fallbackChannels, $policy->fallbackChannels);
        $this->assertNull($policy->quietHours);
    }

    public function test_reads_global_default_row_when_present(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'default',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['email', 'web', 'sms'],
            'fallback_channels_json' => ['email'],
            'recipient_rules_json' => null,
            'quiet_hours_json' => ['start' => '22:00', 'end' => '07:00'],
            'escalation_rules_json' => null,
            'is_active' => true,
        ]);

        $policy = app(TenantNotificationPoliciesResolver::class)->resolve($team);

        $this->assertEquals(
            [ChannelType::Email, ChannelType::Web, ChannelType::Sms],
            $policy->allowedChannels,
        );
        $this->assertEquals([ChannelType::Email], $policy->fallbackChannels);
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $policy->quietHours);
    }

    public function test_ignores_inactive_or_typed_rows(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        // Inactive global row should be skipped.
        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'default',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['sms'],
            'fallback_channels_json' => ['sms'],
            'recipient_rules_json' => null,
            'quiet_hours_json' => null,
            'escalation_rules_json' => null,
            'is_active' => false,
        ]);

        // Typed row (notification_type set) should NOT be picked up by the global resolver.
        TenantNotificationPolicy::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'policy_code' => 'default',
            'notification_type' => 'incident_created',
            'priority' => 'high',
            'allowed_channels_json' => ['sms'],
            'fallback_channels_json' => ['sms'],
            'recipient_rules_json' => null,
            'quiet_hours_json' => null,
            'escalation_rules_json' => null,
            'is_active' => true,
        ]);

        $policy = app(TenantNotificationPoliciesResolver::class)->resolve($team);

        // Falls back to defaults from the Notifications DTO.
        $defaults = TenantNotificationPolicyData::defaults();
        $this->assertEquals($defaults->allowedChannels, $policy->allowedChannels);
    }
}
