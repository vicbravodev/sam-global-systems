<?php

namespace App\Http\Controllers\TenantConfig;

use App\Contracts\ObjectStorage;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\TenantConfig\Actions\ResolveTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant configuration page (Roadmap F-TC): general settings (incl. the
 * media/panic toggles the emergency pipeline reads), AI profile, notification
 * policies, escalation steps, on-call schedule and the version history. The
 * mutations reuse the existing TenantConfig API controllers exposed as web
 * routes (session + CSRF).
 */
class TenantConfigPageController extends Controller
{
    public function show(Team $current_team, ResolveTenantAIProfile $resolveAIProfile): Response
    {
        $this->authorize('viewAny', TenantSetting::class);

        return Inertia::render('settings/tenant-config', [
            'settings' => fn () => TenantSetting::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('setting_group')
                ->orderBy('setting_key')
                ->get()
                ->map(fn (TenantSetting $setting): array => [
                    'id' => (int) $setting->id,
                    'key' => $setting->setting_key,
                    'group' => $setting->setting_group?->value,
                    'valueType' => $setting->value_type?->value,
                    'value' => $setting->typed_value,
                    'isActive' => (bool) $setting->is_active,
                    'version' => (int) $setting->version,
                ])
                ->all(),
            'aiProfile' => function () use ($current_team, $resolveAIProfile): array {
                $resolved = $resolveAIProfile->resolve($current_team->id);

                // The resolver returns effective values (with defaults); the
                // persisted row carries name/description for the form.
                $persisted = TenantAIProfile::withoutGlobalScopes()
                    ->where('team_id', $current_team->id)
                    ->first();

                return [
                    'profileCode' => $resolved->profileCode,
                    'name' => $persisted?->name ?? 'Perfil del tenant',
                    'description' => $persisted?->description,
                    'riskTolerance' => $resolved->riskTolerance->value,
                    'falsePositiveTolerance' => $resolved->falsePositiveTolerance->value,
                    'automationLevel' => $resolved->automationLevel->value,
                    'mediaStrategy' => $resolved->mediaStrategy->value,
                ];
            },
            'aiProfileOptions' => fn (): array => [
                'riskTolerances' => array_map(fn (RiskTolerance $case) => $case->value, RiskTolerance::cases()),
                'falsePositiveTolerances' => array_map(fn (FalsePositiveTolerance $case) => $case->value, FalsePositiveTolerance::cases()),
                'automationLevels' => array_map(fn (AutomationLevel $case) => $case->value, AutomationLevel::cases()),
                'mediaStrategies' => array_map(fn (MediaStrategy $case) => $case->value, MediaStrategy::cases()),
            ],
            'notificationPolicies' => fn () => TenantNotificationPolicy::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('policy_code')
                ->get()
                ->map(fn (TenantNotificationPolicy $policy): array => [
                    'id' => (int) $policy->id,
                    'policyCode' => $policy->policy_code,
                    'notificationType' => $policy->notification_type,
                    'priority' => $policy->priority,
                    'allowedChannels' => (array) ($policy->allowed_channels_json ?? []),
                    'fallbackChannels' => (array) ($policy->fallback_channels_json ?? []),
                    'isActive' => (bool) $policy->is_active,
                ])
                ->all(),
            'escalationConfigs' => fn () => TenantEscalationConfig::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('escalation_type')
                ->get()
                ->map(fn (TenantEscalationConfig $config): array => [
                    'id' => (int) $config->id,
                    'escalationType' => $config->escalation_type,
                    'triggerConditions' => (array) ($config->trigger_conditions_json ?? []),
                    'steps' => (array) ($config->steps_json ?? []),
                    'timeConstraints' => $config->time_constraints_json,
                    'isActive' => (bool) $config->is_active,
                ])
                ->all(),
            'scheduleProfiles' => fn () => TenantScheduleProfile::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('profile_code')
                ->get()
                ->map(fn (TenantScheduleProfile $profile): array => [
                    'id' => (int) $profile->id,
                    'profileCode' => $profile->profile_code,
                    'timezone' => $profile->timezone,
                    'operatingHours' => (array) ($profile->operating_hours_json ?? []),
                    'shiftRules' => $profile->shift_rules_json,
                    'afterHoursBehavior' => $profile->after_hours_behavior_json,
                    'isActive' => (bool) $profile->is_active,
                ])
                ->all(),
            'versions' => fn () => TenantConfigVersion::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderByDesc('version')
                ->limit(15)
                ->get()
                ->map(fn (TenantConfigVersion $version): array => [
                    'id' => (int) $version->id,
                    'version' => (int) $version->version,
                    'createdByType' => $version->created_by_type?->value,
                    'createdAt' => $version->created_at?->toIso8601String(),
                    'snapshot' => $version->snapshot_json,
                ])
                ->all(),
            'channels' => fn () => NotificationChannel::query()
                ->where(fn ($query) => $query
                    ->where('team_id', $current_team->id)
                    ->orWhereNull('team_id'))
                ->orderBy('channel_type')
                ->orderBy('name')
                ->get()
                ->map(fn (NotificationChannel $channel): array => [
                    'id' => (int) $channel->id,
                    'code' => $channel->code,
                    'name' => $channel->name,
                    'provider' => $channel->provider,
                    'channelType' => $channel->channel_type?->value,
                    'isActive' => (bool) $channel->is_active,
                    'isGlobal' => $channel->team_id === null,
                    // Secrets stay server-side: only key names + masked tails
                    // reach the browser (Roadmap F5c).
                    'configSummary' => $this->maskConfig((array) ($channel->config_json ?? [])),
                ])
                ->all(),
            'channelTypes' => fn () => array_map(
                fn (ChannelType $type) => $type->value,
                ChannelType::cases(),
            ),
            'branding' => function () use ($current_team): array {
                $branding = TenantBranding::withoutGlobalScopes()
                    ->where('team_id', $current_team->id)
                    ->first();

                $logoUrl = null;

                if ($branding?->logo_url) {
                    try {
                        $logoUrl = app(ObjectStorage::class)
                            ->temporaryUrl($branding->logo_url, now()->addMinutes(30));
                    } catch (\Throwable) {
                        $logoUrl = null;
                    }
                }

                return [
                    'displayName' => $branding?->display_name,
                    'primaryColor' => $branding?->primary_color,
                    'secondaryColor' => $branding?->secondary_color,
                    'emailSignature' => $branding?->email_signature,
                    'logoUrl' => $logoUrl,
                ];
            },
            'canManageChannels' => fn () => (bool) request()->user()?->can('manage', NotificationChannel::class),
            'canManage' => fn () => (bool) request()->user()?->can('update', TenantSetting::class),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function maskConfig(array $config): array
    {
        $masked = [];

        foreach ($config as $key => $value) {
            if (! is_scalar($value)) {
                $masked[(string) $key] = '•••';

                continue;
            }

            $string = (string) $value;
            $masked[(string) $key] = mb_strlen($string) > 8
                ? '••••'.mb_substr($string, -4)
                : '••••';
        }

        return $masked;
    }
}
