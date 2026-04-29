<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Events\TenantAIProfileChanged;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class UpdateTenantAIProfile
{
    public function __construct(
        private readonly SnapshotTenantConfig $snapshotTenantConfig,
    ) {}

    /**
     * @param  array<string, mixed>|null  $promptOverrides
     * @param  array<string, mixed>|null  $humanReviewPolicy
     */
    public function execute(
        int $teamId,
        string $profileCode,
        string $name,
        ?string $description,
        RiskTolerance $riskTolerance,
        FalsePositiveTolerance $falsePositiveTolerance,
        AutomationLevel $automationLevel,
        MediaStrategy $mediaStrategy,
        ?array $promptOverrides = null,
        ?array $humanReviewPolicy = null,
        SettingUpdatedByType $updatedByType = SettingUpdatedByType::System,
        ?int $updatedById = null,
    ): TenantAIProfile {
        $profile = TenantAIProfile::withoutGlobalScopes()
            ->updateOrCreate(
                ['team_id' => $teamId],
                [
                    'profile_code' => $profileCode,
                    'name' => $name,
                    'description' => $description,
                    'prompt_overrides_json' => $promptOverrides,
                    'risk_tolerance' => $riskTolerance,
                    'false_positive_tolerance' => $falsePositiveTolerance,
                    'automation_level' => $automationLevel,
                    'media_strategy' => $mediaStrategy,
                    'human_review_policy_json' => $humanReviewPolicy,
                    'is_active' => true,
                ],
            );

        Cache::forget(CacheKeys::aiProfile($teamId));

        TenantAIProfileChanged::dispatch(
            $teamId,
            $automationLevel,
            $riskTolerance,
            $mediaStrategy,
        );

        $this->snapshotTenantConfig->execute($teamId, $updatedByType, $updatedById);

        return $profile->refresh();
    }
}
