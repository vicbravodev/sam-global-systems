<?php

namespace App\Http\Controllers\TenantConfig;

use App\Contracts\ObjectStorage;
use App\Domains\Automation\Support\TriggerConditionCatalog;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Domains\Notifications\Support\NotificationTypeLabels;
use App\Domains\Notifications\Support\ProvidedChannels;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Domains\TenantConfig\Actions\ResolveTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
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
    /**
     * Apply the SAM Default Config Pack (Roadmap V2-A5): fills in any missing
     * recommended settings/rules/escalation without touching what the tenant
     * already configured.
     */
    public function applySamDefaults(Team $current_team, ApplyDefaultTenantConfig $applyDefaultConfig): RedirectResponse
    {
        $this->authorize('update', TenantSetting::class);

        $summary = $applyDefaultConfig->execute($current_team);

        $created = $summary['settings_created'] + $summary['rules_created'] + ($summary['escalation_created'] ? 1 : 0);

        return back()->with(
            'success',
            $created > 0
                ? "Configuración recomendada SAM aplicada ({$created} elementos nuevos; lo modificado por ti no se tocó)."
                : 'Tu configuración ya incluye todo lo recomendado por SAM.',
        );
    }

    public function show(Team $current_team, ResolveTenantAIProfile $resolveAIProfile): Response
    {
        $this->authorize('viewAny', TenantSetting::class);

        return Inertia::render('settings/tenant-config', [
            'settings' => fn () => TenantSetting::query()
                ->where('team_id', $current_team->id)
                ->orderBy('setting_group')
                ->orderBy('setting_key')
                ->get()
                ->map(fn (TenantSetting $setting): array => [
                    'id' => $setting->id,
                    'key' => $setting->setting_key,
                    'group' => $setting->setting_group?->value,
                    'valueType' => $setting->value_type?->value,
                    'value' => $setting->typed_value,
                    'isActive' => $setting->is_active,
                    'version' => $setting->version,
                ])
                ->all(),
            'aiProfile' => function () use ($current_team, $resolveAIProfile): array {
                $resolved = $resolveAIProfile->resolve($current_team->id);

                // The resolver returns effective values (with defaults); the
                // persisted row carries name/description for the form.
                $persisted = TenantAIProfile::query()
                    ->where('team_id', $current_team->id)
                    ->first();

                return [
                    'profileCode' => $resolved->profileCode,
                    'name' => $persisted?->name ?? 'Perfil de la empresa',
                    'description' => $persisted?->description,
                    'riskTolerance' => $resolved->riskTolerance->value,
                    'falsePositiveTolerance' => $resolved->falsePositiveTolerance->value,
                    'automationLevel' => $resolved->automationLevel->value,
                    'mediaStrategy' => $resolved->mediaStrategy->value,
                ];
            },
            // Sólo se manda el catálogo de automation_level: es el único
            // campo de TenantAIProfile con control real en la UI (ver
            // AiTab en settings/tenant-config.tsx). Los catálogos de
            // risk_tolerance / false_positive_tolerance / media_strategy
            // sólo alimentaban los <select> retirados (Tarea 12).
            'aiProfileOptions' => fn (): array => [
                'automationLevels' => array_map(
                    fn (AutomationLevel $case) => ['value' => $case->value, 'label' => $case->label()],
                    AutomationLevel::cases(),
                ),
            ],
            'notificationPolicies' => fn () => TenantNotificationPolicy::query()
                ->where('team_id', $current_team->id)
                ->orderBy('policy_code')
                ->get()
                ->map(fn (TenantNotificationPolicy $policy): array => [
                    'id' => $policy->id,
                    'policyCode' => $policy->policy_code,
                    'notificationType' => $policy->notification_type,
                    'priority' => $policy->priority,
                    'allowedChannels' => $policy->allowed_channels_json ?? [],
                    'fallbackChannels' => $policy->fallback_channels_json ?? [],
                    'isActive' => $policy->is_active,
                ])
                ->all(),
            'escalationConfigs' => fn () => TenantEscalationConfig::query()
                ->where('team_id', $current_team->id)
                ->orderBy('escalation_type')
                ->get()
                ->map(fn (TenantEscalationConfig $config): array => [
                    'id' => $config->id,
                    'escalationType' => $config->escalation_type,
                    'triggerConditions' => $config->trigger_conditions_json ?? [],
                    'steps' => $config->steps_json ?? [],
                    'timeConstraints' => $config->time_constraints_json,
                    'isActive' => $config->is_active,
                ])
                ->all(),
            'escalationConditionFields' => fn () => TriggerConditionCatalog::escalationFields(),
            'recipientOptions' => fn (): array => [
                'roles' => array_map(fn (TeamRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ], TeamRole::cases()),
                'users' => $current_team->members()
                    ->orderBy('name')
                    ->get(['users.id', 'users.name', 'users.email'])
                    ->map(fn ($user): array => [
                        'value' => (string) $user->id,
                        'label' => $user->name,
                        'description' => $user->email,
                    ])
                    ->all(),
            ],
            'scheduleProfiles' => fn () => TenantScheduleProfile::query()
                ->where('team_id', $current_team->id)
                ->orderBy('profile_code')
                ->get()
                ->map(fn (TenantScheduleProfile $profile): array => [
                    'id' => $profile->id,
                    'profileCode' => $profile->profile_code,
                    'timezone' => $profile->timezone,
                    'operatingHours' => $profile->operating_hours_json ?? [],
                    'shiftRules' => $profile->shift_rules_json,
                    'afterHoursBehavior' => $profile->after_hours_behavior_json,
                    'isActive' => $profile->is_active,
                ])
                ->all(),
            'versions' => fn () => TenantConfigVersion::query()
                ->where('team_id', $current_team->id)
                ->orderByDesc('version')
                ->limit(15)
                ->get()
                ->map(fn (TenantConfigVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'createdByType' => $version->created_by_type?->value,
                    'createdAt' => $version->created_at?->toIso8601String(),
                    'snapshot' => $version->snapshot_json,
                ])
                ->all(),
            'channels' => function () use ($current_team): array {
                $disabledGlobals = TenantChannelToggle::query()
                    ->where('team_id', $current_team->id)
                    ->where('enabled', false)
                    ->pluck('notification_channel_id')
                    ->all();

                return NotificationChannel::query()
                    ->orderBy('channel_type')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (NotificationChannel $channel): array => [
                        'id' => $channel->id,
                        'code' => $channel->code,
                        'name' => $channel->name,
                        'provider' => $channel->provider,
                        'channelType' => $channel->channel_type?->value,
                        'isActive' => $channel->is_active,
                        // Per-tenant switch over SAM platform channels (V2-B1).
                        'enabledForTeam' => ! in_array($channel->id, $disabledGlobals, true),
                    ])
                    ->all();
            },
            // Sólo los canales que SAM entrega: Push/Slack/Webhook no tienen
            // canal de plataforma y no deben ofrecerse en políticas ni escalación.
            'channelTypes' => fn () => app(ProvidedChannels::class)->options(),
            'notificationTypeOptions' => fn () => app(NotificationTypeLabels::class)->options(
                $this->notificationTypes($current_team),
            ),
            'branding' => function () use ($current_team): array {
                $branding = TenantBranding::query()
                    ->where('team_id', $current_team->id)
                    ->first();

                $logoUrl = null;

                $logoPath = $branding?->logo_url;

                // logo_url es una clave de storage (BrandingController) o lo que
                // fije el super-admin; '' y '0' no son claves válidas: sin logo.
                if ($logoPath !== null && $logoPath !== '' && $logoPath !== '0') {
                    try {
                        $logoUrl = app(ObjectStorage::class)
                            ->temporaryUrl($logoPath, now()->addMinutes(30));
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
            'canManageChannels' => fn () => (bool) request()->user()?->can('toggleGlobal', NotificationChannel::class),
            'canManage' => fn () => (bool) request()->user()?->can('update', TenantSetting::class),
        ]);
    }

    /**
     * Tipos de notificación seleccionables en una política: el catálogo base,
     * los que el tenant ya emitió y los que sus políticas ya usan.
     *
     * @return array<int, string>
     */
    private function notificationTypes(Team $team): array
    {
        $observed = Notification::query()
            ->where('team_id', $team->id)
            ->distinct()
            ->pluck('notification_type')
            ->all();

        $configured = TenantNotificationPolicy::query()
            ->where('team_id', $team->id)
            ->whereNotNull('notification_type')
            ->pluck('notification_type')
            ->all();

        $types = array_values(array_unique(array_filter([
            'incident.created',
            'incident.sla_breached',
            'incident.assigned.on_call',
            ...$observed,
            ...$configured,
        ], fn ($type): bool => is_string($type) && $type !== '')));

        sort($types);

        return $types;
    }
}
