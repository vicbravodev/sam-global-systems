<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Actions\UpdateTenantSetting;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\UpdateTenantSettingsRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TenantConfigController extends Controller
{
    public function __construct(
        private readonly UpdateTenantSetting $updateTenantSetting,
    ) {}

    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantSetting::class);

        $settings = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderBy('setting_group')
            ->orderBy('setting_key')
            ->get()
            ->groupBy(fn (TenantSetting $setting): string => $setting->setting_group->value);

        return response()->json(['data' => $settings]);
    }

    public function update(UpdateTenantSettingsRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('update', TenantSetting::class);

        $userId = $request->user()?->id;
        $updatedByType = $userId ? SettingUpdatedByType::User : SettingUpdatedByType::System;

        $persisted = [];

        foreach ($request->validated('settings') as $payload) {
            $persisted[] = $this->updateTenantSetting->execute(
                teamId: $current_team->id,
                settingKey: $payload['setting_key'],
                settingGroup: SettingGroup::from($payload['setting_group']),
                valueType: SettingValueType::from($payload['value_type']),
                value: $payload['value'],
                updatedByType: $updatedByType,
                updatedById: $userId,
            );
        }

        return response()->json(['data' => $persisted]);
    }
}
