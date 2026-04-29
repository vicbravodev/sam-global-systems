<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy as TenantNotificationPolicyData;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

class ResolveTenantNotificationPolicies implements TenantNotificationPoliciesResolver
{
    public function resolve(Team $team): TenantNotificationPolicyData
    {
        return Cache::remember(
            CacheKeys::notificationPoliciesGlobal($team->id),
            CacheKeys::TTL_SECONDS,
            function () use ($team): TenantNotificationPolicyData {
                $row = TenantNotificationPolicy::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('policy_code', 'default')
                    ->where('is_active', true)
                    ->whereNull('notification_type')
                    ->whereNull('priority')
                    ->first();

                if ($row === null) {
                    return TenantNotificationPolicyData::defaults();
                }

                $defaults = TenantNotificationPolicyData::defaults();

                return new TenantNotificationPolicyData(
                    allowedChannels: $this->mapChannels($row->allowed_channels_json) ?? $defaults->allowedChannels,
                    criticalChannels: $defaults->criticalChannels,
                    fallbackChannels: $this->mapChannels($row->fallback_channels_json) ?? $defaults->fallbackChannels,
                    quietHours: $row->quiet_hours_json,
                );
            },
        );
    }

    /**
     * @param  array<int, string>|null  $values
     * @return array<int, ChannelType>|null
     */
    private function mapChannels(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (string $value): ?ChannelType => ChannelType::tryFrom($value), $values),
        ));
    }
}
