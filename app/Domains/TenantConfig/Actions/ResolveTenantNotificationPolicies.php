<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy as TenantNotificationPolicyData;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

class ResolveTenantNotificationPolicies implements TenantNotificationPoliciesResolver
{
    /**
     * Política global del tenant: la fila `default` (sin tipo ni prioridad)
     * da los canales normales, de fallback y el horario de silencio; la fila
     * activa sin tipo con `priority = critical` da los canales críticos. Sin
     * fila crítica se usan los críticos de sistema (email/web/sms/push).
     */
    public function resolve(Team $team): TenantNotificationPolicyData
    {
        return Cache::remember(
            CacheKeys::notificationPoliciesGlobal($team->id),
            CacheKeys::TTL_SECONDS,
            fn (): TenantNotificationPolicyData => TenantContext::for($team->id, function () use ($team): TenantNotificationPolicyData {
                $defaults = TenantNotificationPolicyData::defaults();

                $row = TenantNotificationPolicy::query()
                    ->where('team_id', $team->id)
                    ->where('policy_code', 'default')
                    ->where('is_active', true)
                    ->whereNull('notification_type')
                    ->whereNull('priority')
                    ->first();

                $criticalRow = TenantNotificationPolicy::query()
                    ->where('team_id', $team->id)
                    ->where('is_active', true)
                    ->whereNull('notification_type')
                    ->where('priority', 'critical')
                    ->orderByDesc('id')
                    ->first();

                if ($row === null && $criticalRow === null) {
                    return $defaults;
                }

                return new TenantNotificationPolicyData(
                    allowedChannels: $this->mapChannels($row?->allowed_channels_json) ?? $defaults->allowedChannels,
                    criticalChannels: $this->mapChannels($criticalRow?->allowed_channels_json) ?? $defaults->criticalChannels,
                    fallbackChannels: $this->mapChannels($row?->fallback_channels_json) ?? $defaults->fallbackChannels,
                    quietHours: $row?->quiet_hours_json,
                );
            }),
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
