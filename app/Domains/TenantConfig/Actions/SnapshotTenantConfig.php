<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;

class SnapshotTenantConfig
{
    public function execute(
        int $teamId,
        SettingUpdatedByType $createdByType = SettingUpdatedByType::System,
        ?int $createdById = null,
    ): TenantConfigVersion {
        $version = (int) TenantConfigVersion::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->max('version') + 1;

        $snapshot = [
            'version' => $version,
            'captured_at' => now()->toIso8601String(),
            'settings' => $this->collectSettings($teamId),
            'rule_overrides' => $this->collectRuleOverrides($teamId),
            'ai_profile' => $this->collectAIProfile($teamId),
            'notification_policies' => $this->collectNotificationPolicies($teamId),
            'escalation_configs' => $this->collectEscalationConfigs($teamId),
            'schedule_profiles' => $this->collectScheduleProfiles($teamId),
        ];

        return TenantConfigVersion::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'version' => $version,
            'snapshot_json' => $snapshot,
            'created_by_type' => $createdByType,
            'created_by_id' => $createdById,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSettings(int $teamId): array
    {
        return TenantSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->mapWithKeys(fn (TenantSetting $setting): array => [
                $setting->setting_key => [
                    'group' => $setting->setting_group->value,
                    'type' => $setting->value_type->value,
                    'value' => $setting->typed_value,
                    'version' => $setting->version,
                    'is_active' => $setting->is_active,
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectRuleOverrides(int $teamId): array
    {
        return TenantRuleOverride::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->map(fn (TenantRuleOverride $override): array => [
                'base_rule_code' => $override->base_rule_code,
                'override_type' => $override->override_type->value,
                'config' => $override->override_config_json,
                'is_active' => $override->is_active,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectAIProfile(int $teamId): ?array
    {
        $profile = TenantAIProfile::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if ($profile === null) {
            return null;
        }

        return [
            'profile_code' => $profile->profile_code,
            'name' => $profile->name,
            'risk_tolerance' => $profile->risk_tolerance->value,
            'false_positive_tolerance' => $profile->false_positive_tolerance->value,
            'automation_level' => $profile->automation_level->value,
            'media_strategy' => $profile->media_strategy->value,
            'prompt_overrides' => $profile->prompt_overrides_json,
            'human_review_policy' => $profile->human_review_policy_json,
            'is_active' => $profile->is_active,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectNotificationPolicies(int $teamId): array
    {
        return TenantNotificationPolicy::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->map(fn (TenantNotificationPolicy $policy): array => [
                'policy_code' => $policy->policy_code,
                'notification_type' => $policy->notification_type,
                'priority' => $policy->priority,
                'allowed_channels' => $policy->allowed_channels_json,
                'fallback_channels' => $policy->fallback_channels_json,
                'is_active' => $policy->is_active,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectEscalationConfigs(int $teamId): array
    {
        return TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->map(fn (TenantEscalationConfig $config): array => [
                'escalation_type' => $config->escalation_type,
                'trigger_conditions' => $config->trigger_conditions_json,
                'steps' => $config->steps_json,
                'time_constraints' => $config->time_constraints_json,
                'is_active' => $config->is_active,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectScheduleProfiles(int $teamId): array
    {
        return TenantScheduleProfile::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->map(fn (TenantScheduleProfile $profile): array => [
                'profile_code' => $profile->profile_code,
                'timezone' => $profile->timezone,
                'operating_hours' => $profile->operating_hours_json,
                'after_hours_behavior' => $profile->after_hours_behavior_json,
                'is_active' => $profile->is_active,
            ])
            ->all();
    }
}
