<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Actions\ResolveTenantAIProfile;
use App\Domains\TenantConfig\Actions\UpdateTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\UpdateTenantAIProfileRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TenantAIProfileController extends Controller
{
    public function __construct(
        private readonly ResolveTenantAIProfile $resolveTenantAIProfile,
        private readonly UpdateTenantAIProfile $updateTenantAIProfile,
    ) {}

    public function show(Team $current_team): JsonResponse
    {
        $this->authorize('view', TenantAIProfile::class);

        $profile = $this->resolveTenantAIProfile->resolve($current_team->id);

        return response()->json(['data' => $profile->toArray()]);
    }

    public function update(UpdateTenantAIProfileRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('update', TenantAIProfile::class);

        $userId = $request->user()?->id;
        $updatedByType = $userId ? SettingUpdatedByType::User : SettingUpdatedByType::System;

        $profile = $this->updateTenantAIProfile->execute(
            teamId: $current_team->id,
            profileCode: $request->validated('profile_code'),
            name: $request->validated('name'),
            description: $request->validated('description'),
            riskTolerance: RiskTolerance::from($request->validated('risk_tolerance')),
            falsePositiveTolerance: FalsePositiveTolerance::from($request->validated('false_positive_tolerance')),
            automationLevel: AutomationLevel::from($request->validated('automation_level')),
            mediaStrategy: MediaStrategy::from($request->validated('media_strategy')),
            promptOverrides: $request->validated('prompt_overrides'),
            humanReviewPolicy: $request->validated('human_review_policy'),
            updatedByType: $updatedByType,
            updatedById: $userId,
        );

        return response()->json(['data' => $profile]);
    }
}
