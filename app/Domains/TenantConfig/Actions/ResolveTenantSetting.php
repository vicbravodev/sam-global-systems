<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantSetting implements TenantConfigResolver
{
    public function resolve(int $teamId, string $settingKey, mixed $systemDefault = null): mixed
    {
        $cacheKey = CacheKeys::setting($teamId, $settingKey);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached['hit'] ? $cached['value'] : $systemDefault;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('setting_key', $settingKey)
            ->where('is_active', true)
            ->first();

        if ($setting !== null) {
            $value = $setting->typed_value;
            Cache::put($cacheKey, ['hit' => true, 'value' => $value], CacheKeys::TTL_SECONDS);

            return $value;
        }

        Cache::put($cacheKey, ['hit' => false, 'value' => null], CacheKeys::TTL_SECONDS);

        return $systemDefault;
    }

    public function invalidate(int $teamId, string $settingKey): void
    {
        Cache::forget(CacheKeys::setting($teamId, $settingKey));
    }
}
