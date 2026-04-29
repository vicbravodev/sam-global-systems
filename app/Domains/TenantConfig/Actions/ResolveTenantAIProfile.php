<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Domains\TenantConfig\Data\ResolvedAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantAIProfile implements TenantAIProfileResolver
{
    public function resolve(int $teamId): ResolvedAIProfile
    {
        $cacheKey = CacheKeys::aiProfile($teamId);

        return Cache::remember($cacheKey, CacheKeys::TTL_SECONDS, function () use ($teamId) {
            $profile = TenantAIProfile::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->first();

            if ($profile === null) {
                return $this->defaultProfile($teamId);
            }

            return new ResolvedAIProfile(
                teamId: $teamId,
                profileCode: $profile->profile_code,
                riskTolerance: $profile->risk_tolerance,
                falsePositiveTolerance: $profile->false_positive_tolerance,
                automationLevel: $profile->automation_level,
                mediaStrategy: $profile->media_strategy,
                promptOverrides: $profile->prompt_overrides_json,
                humanReviewPolicy: $profile->human_review_policy_json,
                isPersisted: true,
            );
        });
    }

    private function defaultProfile(int $teamId): ResolvedAIProfile
    {
        return new ResolvedAIProfile(
            teamId: $teamId,
            profileCode: 'system_default',
            riskTolerance: RiskTolerance::Medium,
            falsePositiveTolerance: FalsePositiveTolerance::Medium,
            automationLevel: AutomationLevel::Assisted,
            mediaStrategy: MediaStrategy::Preferred,
            promptOverrides: null,
            humanReviewPolicy: null,
            isPersisted: false,
        );
    }
}
