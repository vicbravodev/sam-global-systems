<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy as TenantNotificationPolicyData;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Actions\ResolveTenantNotificationPolicies;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ResolveTenantNotificationPoliciesTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
        // assertEquals: key order is not part of the contract (jsonb reorders keys).
        $this->assertEquals(['start' => '22:00', 'end' => '07:00'], $policy->quietHours);
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

    public function test_critical_channels_honour_the_tenant_critical_row(): void
    {
        Cache::flush();

        $team = User::factory()->create()->currentTeam;

        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id,
            'policy_code' => 'critical',
            'notification_type' => null,
            'priority' => 'critical',
            'allowed_channels_json' => ['email', 'web'],
        ]);

        $policy = app(TenantNotificationPoliciesResolver::class)->resolve($team);

        // El tenant quitó el SMS de sus críticos: ya no se fuerza el default.
        $this->assertEquals([ChannelType::Email, ChannelType::Web], $policy->criticalChannels);
        $this->assertEquals(TenantNotificationPolicyData::defaults()->allowedChannels, $policy->allowedChannels);
    }

    public function test_critical_channels_fall_back_to_system_defaults_without_a_critical_row(): void
    {
        Cache::flush();

        $team = User::factory()->create()->currentTeam;

        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id,
            'policy_code' => 'default',
            'notification_type' => null,
            'priority' => null,
            'allowed_channels_json' => ['email'],
        ]);

        $policy = app(TenantNotificationPoliciesResolver::class)->resolve($team);

        $this->assertEquals(TenantNotificationPolicyData::defaults()->criticalChannels, $policy->criticalChannels);
    }

    public function test_another_tenants_critical_row_is_never_applied(): void
    {
        Cache::flush();

        $teamA = User::factory()->create()->currentTeam;
        $teamB = User::factory()->create()->currentTeam;

        TenantNotificationPolicy::factory()->create([
            'team_id' => $teamA->id,
            'policy_code' => 'critical',
            'notification_type' => null,
            'priority' => 'critical',
            'allowed_channels_json' => ['web'],
        ]);

        $policy = $this->assertNoTenantLeak($teamB, fn () => app(TenantNotificationPoliciesResolver::class)->resolve($teamB));

        $this->assertEquals(TenantNotificationPolicyData::defaults()->criticalChannels, $policy->criticalChannels);
    }

    public function test_saving_policies_invalidates_the_cached_global_policy(): void
    {
        Cache::flush();

        $this->seed(AccessSeeder::class);
        $user = User::factory()->create();
        $team = $user->currentTeam;

        app(TenantNotificationPoliciesResolver::class)->resolve($team);
        $this->assertTrue(Cache::has(CacheKeys::notificationPoliciesGlobal($team->id)));

        $this->actingAs($user)
            ->putJson("/api/{$team->slug}/settings/notifications", [
                'policies' => [[
                    'policy_code' => 'critical',
                    'priority' => 'critical',
                    'allowed_channels' => ['email', 'web'],
                ]],
            ])
            ->assertOk();

        $this->assertFalse(Cache::has(CacheKeys::notificationPoliciesGlobal($team->id)));
    }
}
