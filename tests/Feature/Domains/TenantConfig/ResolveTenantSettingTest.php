<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\TenantConfig\Actions\ResolveTenantSetting;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveTenantSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_persisted_setting_when_present(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'setting_key' => 'ai.confidence_threshold',
            'setting_group' => 'ai',
            'value_json' => ['value' => 0.85],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        $resolver = app(ResolveTenantSetting::class);

        $value = $resolver->resolve($team->id, 'ai.confidence_threshold', 0.5);

        $this->assertSame(0.85, $value);
    }

    public function test_falls_back_to_system_default_when_no_setting(): void
    {
        $team = User::factory()->create()->currentTeam;

        $resolver = app(ResolveTenantSetting::class);

        $this->assertSame(0.42, $resolver->resolve($team->id, 'ai.confidence_threshold', 0.42));
    }

    public function test_inactive_setting_is_treated_as_absent(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'setting_key' => 'feature.flag',
            'setting_group' => 'operational',
            'value_json' => ['value' => true],
            'value_type' => 'boolean',
            'version' => 1,
            'is_active' => false,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        $resolver = app(ResolveTenantSetting::class);

        $this->assertFalse($resolver->resolve($team->id, 'feature.flag', false));
    }

    public function test_value_is_cached_and_invalidate_clears_it(): void
    {
        $team = User::factory()->create()->currentTeam;
        $resolver = app(ResolveTenantSetting::class);

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'setting_key' => 'ai.confidence_threshold',
            'setting_group' => 'ai',
            'value_json' => ['value' => 0.5],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        $resolver->resolve($team->id, 'ai.confidence_threshold');

        $this->assertNotNull(
            Cache::get(CacheKeys::setting($team->id, 'ai.confidence_threshold')),
            'Resolved setting should be cached',
        );

        $resolver->invalidate($team->id, 'ai.confidence_threshold');

        $this->assertNull(
            Cache::get(CacheKeys::setting($team->id, 'ai.confidence_threshold')),
            'Cache must be cleared by invalidate()',
        );
    }

    public function test_contract_is_bound_to_action(): void
    {
        $this->assertInstanceOf(ResolveTenantSetting::class, app(TenantConfigResolver::class));
    }
}
