<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantNotificationPolicyResolver;
use App\Domains\TenantConfig\Data\ResolvedNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantNotificationPolicy implements TenantNotificationPolicyResolver
{
    public function resolve(int $teamId, ?string $notificationType = null, ?string $priority = null): ResolvedNotificationPolicy
    {
        $cacheKey = CacheKeys::notificationPolicy($teamId, $notificationType, $priority);

        return Cache::remember($cacheKey, CacheKeys::TTL_SECONDS, function () use ($teamId, $notificationType, $priority) {
            $query = TenantNotificationPolicy::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('is_active', true);

            $candidates = $query->get();

            $match = $candidates
                ->sortByDesc(fn (TenantNotificationPolicy $policy): int => $this->matchSpecificity($policy, $notificationType, $priority))
                ->first(fn (TenantNotificationPolicy $policy): bool => $this->matches($policy, $notificationType, $priority));

            if ($match === null) {
                return $this->defaultPolicy($teamId, $notificationType, $priority);
            }

            return new ResolvedNotificationPolicy(
                teamId: $teamId,
                policyCode: $match->policy_code,
                notificationType: $match->notification_type,
                priority: $match->priority,
                allowedChannels: (array) ($match->allowed_channels_json ?? []),
                fallbackChannels: (array) ($match->fallback_channels_json ?? []),
                recipientRules: $match->recipient_rules_json,
                quietHours: $match->quiet_hours_json,
                escalationRules: $match->escalation_rules_json,
                isPersisted: true,
            );
        });
    }

    private function matches(TenantNotificationPolicy $policy, ?string $notificationType, ?string $priority): bool
    {
        if ($policy->notification_type !== null && $notificationType !== null && $policy->notification_type !== $notificationType) {
            return false;
        }

        if ($policy->priority !== null && $priority !== null && $policy->priority !== $priority) {
            return false;
        }

        return true;
    }

    private function matchSpecificity(TenantNotificationPolicy $policy, ?string $notificationType, ?string $priority): int
    {
        $score = 0;

        if ($policy->notification_type !== null && $policy->notification_type === $notificationType) {
            $score += 2;
        }

        if ($policy->priority !== null && $policy->priority === $priority) {
            $score += 1;
        }

        return $score;
    }

    private function defaultPolicy(int $teamId, ?string $notificationType, ?string $priority): ResolvedNotificationPolicy
    {
        return new ResolvedNotificationPolicy(
            teamId: $teamId,
            policyCode: 'system_default',
            notificationType: $notificationType,
            priority: $priority,
            allowedChannels: ['email'],
            fallbackChannels: [],
            recipientRules: null,
            quietHours: null,
            escalationRules: null,
            isPersisted: false,
        );
    }
}
