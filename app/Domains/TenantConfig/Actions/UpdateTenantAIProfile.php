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
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
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
        return TenantContext::for($teamId, function () use ($teamId, $profileCode, $name, $description, $riskTolerance, $falsePositiveTolerance, $automationLevel, $mediaStrategy, $promptOverrides, $humanReviewPolicy, $updatedByType, $updatedById) {
            $profile = TenantAIProfile::query()
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
            // El umbral de revisión humana del motor de decisiones sale del
            // automation_level: sin esto el cambio tardaría el TTL en aplicar.
            Cache::forget(CacheKeys::decisionRules($teamId));

            // Nunca el nombre, la descripción ni los overrides del prompt.
            SystemLog::ok('tenant_config.ai_profile.updated', input: [
                'team_id' => $teamId,
                'profile_code' => LoggableCode::guard($profileCode),
                'updated_by_type' => $updatedByType->value,
                'updated_by_id' => $updatedById,
            ], result: [
                'profile_id' => $profile->id,
                'created' => $profile->wasRecentlyCreated,
                'risk_tolerance' => $riskTolerance->value,
                'false_positive_tolerance' => $falsePositiveTolerance->value,
                'automation_level' => $automationLevel->value,
                'media_strategy' => $mediaStrategy->value,
                'prompt_overrides_present' => $promptOverrides !== null && $promptOverrides !== [],
                'human_review_policy_present' => $humanReviewPolicy !== null && $humanReviewPolicy !== [],
            ]);

            TenantAIProfileChanged::dispatch(
                $teamId,
                $automationLevel,
                $riskTolerance,
                $mediaStrategy,
            );

            $this->snapshotTenantConfig->execute($teamId, $updatedByType, $updatedById);

            return $profile->refresh();
        });
    }
}
