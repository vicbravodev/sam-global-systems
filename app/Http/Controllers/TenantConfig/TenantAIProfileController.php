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

        // risk_tolerance / false_positive_tolerance / media_strategy ya no
        // tienen control en la UI (Tarea 12) y el form dejó de mandarlos;
        // UpdateTenantAIProfileRequest los relajó a `sometimes`. Cuando
        // faltan, se conserva el valor persistido/efectivo actual en vez de
        // pisarlo con un valor por defecto o con un snapshot stale del
        // cliente — se resuelve fresco en cada request, así que un cambio
        // hecho por otra vía (p. ej. la ruta API con el mismo controlador)
        // mientras el form estaba abierto no se pierde.
        $current = $this->resolveTenantAIProfile->resolve($current_team->id);

        $profile = $this->updateTenantAIProfile->execute(
            teamId: $current_team->id,
            profileCode: $request->validated('profile_code'),
            name: $request->validated('name'),
            description: $request->validated('description'),
            riskTolerance: $request->filled('risk_tolerance')
                ? RiskTolerance::from($request->validated('risk_tolerance'))
                : $current->riskTolerance,
            falsePositiveTolerance: $request->filled('false_positive_tolerance')
                ? FalsePositiveTolerance::from($request->validated('false_positive_tolerance'))
                : $current->falsePositiveTolerance,
            automationLevel: AutomationLevel::from($request->validated('automation_level')),
            mediaStrategy: $request->filled('media_strategy')
                ? MediaStrategy::from($request->validated('media_strategy'))
                : $current->mediaStrategy,
            promptOverrides: $request->validated('prompt_overrides'),
            humanReviewPolicy: $request->validated('human_review_policy'),
            updatedByType: $updatedByType,
            updatedById: $userId,
        );

        return response()->json(['data' => $profile]);
    }
}
