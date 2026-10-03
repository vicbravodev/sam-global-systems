<?php

namespace Database\Seeders\Showcase;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Domains\TenantConfig\Actions\SnapshotTenantConfig;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Configuración del tenant: perfil de IA, políticas de notificación,
 * horario operativo, SLAs por prioridad, marca, overrides de reglas,
 * escalamiento y versiones de configuración.
 *
 * Un tenant NUEVO recibe el SAM Default Config Pack real
 * ({@see ApplyDefaultTenantConfig}). Un tenant con datos reales NO: el pack
 * activa llamadas de verificación y escalamientos por voz, y el showcase no
 * debe cambiar cómo se comporta el pipeline en vivo. Por la misma razón, en
 * ese caso los overrides de reglas y el escalamiento se crean desactivados.
 *
 * Marcador: cada bloque sólo se crea si el tenant no tiene ninguna fila de
 * esa tabla (el perfil de IA y la marca son únicos por tenant).
 */
class TenantConfigShowcaseSeeder extends ShowcaseStep
{
    private bool $changed = false;

    public function run(): void
    {
        $teamId = $this->ctx->team->id;

        if (! TenantSetting::query()->where('team_id', $teamId)->exists()) {
            app(ApplyDefaultTenantConfig::class)->execute($this->ctx->team);
            $this->ctx->count('tenant_settings (SAM default pack)');
        }

        $this->seedSettings();
        $this->seedAiProfile();
        $this->seedNotificationPolicies();
        $this->seedScheduleProfile();
        $this->seedSlas();
        $this->seedBranding();
        $this->seedRuleOverrides();
        $this->seedEscalation();

        if ($this->changed) {
            app(SnapshotTenantConfig::class)->execute($teamId, SettingUpdatedByType::User, $this->ctx->user('admin')->id);
            $this->ctx->count('tenant_config_versions');
        }

        Cache::forget(CacheKeys::aiProfile($teamId));
        Cache::forget(CacheKeys::decisionRules($teamId));
        Cache::forget(CacheKeys::schedule($teamId));

        $this->ctx->slaSeconds = $this->effectiveSlas();
    }

    private function seedSettings(): void
    {
        // Sólo claves con valor por defecto equivalente al comportamiento actual.
        $settings = [
            ['panic.auto_close_on_external_resolution', SettingGroup::Operational, SettingValueType::String, 'annotate'],
            ['context.live_location_staleness_seconds', SettingGroup::Operational, SettingValueType::Number, 300],
            ['branding.report_footer', SettingGroup::Branding, SettingValueType::String, 'Reporte generado por SAM · Centro de monitoreo'],
            ['compliance.evidence_retention_days', SettingGroup::Compliance, SettingValueType::Number, 180],
        ];

        foreach ($settings as [$key, $group, $type, $value]) {
            $exists = TenantSetting::query()
                ->where('team_id', $this->ctx->team->id)
                ->where('setting_key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            TenantSetting::query()->create([
                'team_id' => $this->ctx->team->id,
                'setting_key' => $key,
                'setting_group' => $group,
                'value_json' => ['value' => $value],
                'value_type' => $type,
                'version' => 1,
                'is_active' => true,
                'updated_by_type' => SettingUpdatedByType::User,
                'updated_by_id' => $this->ctx->user('admin')->id,
            ]);
            $this->ctx->count('tenant_settings');
            $this->changed = true;
        }
    }

    private function seedAiProfile(): void
    {
        if (TenantAIProfile::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        // Mismos valores que el fallback del resolver: visible en la UI sin
        // alterar cómo evalúa la IA en vivo.
        TenantAIProfile::query()->create([
            'team_id' => $this->ctx->team->id,
            'profile_code' => 'operativo',
            'name' => 'Perfil operativo '.$this->ctx->team->name,
            'description' => 'Revisión humana obligatoria en emergencias; la IA descarta ruido de baja severidad.',
            'prompt_overrides_json' => ['tone' => 'operativo', 'language' => 'es-MX'],
            'risk_tolerance' => RiskTolerance::Medium,
            'false_positive_tolerance' => FalsePositiveTolerance::Medium,
            'automation_level' => AutomationLevel::Assisted,
            'media_strategy' => MediaStrategy::Preferred,
            'human_review_policy_json' => [
                'always_review' => ['panic_button', 'collision', 'tampering'],
                'min_confidence_for_auto' => 0.85,
            ],
            'is_active' => true,
        ]);
        $this->ctx->count('tenant_ai_profiles');
        $this->changed = true;
    }

    private function seedNotificationPolicies(): void
    {
        if (TenantNotificationPolicy::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $policies = [
            ['default', null, null, ['web', 'email'], ['sms'], true],
            ['critical-incidents', 'incident.created', 'critical', ['web', 'sms', 'voice', 'whatsapp'], ['email'], true],
            ['sla-breach', 'incident.sla_breached', null, ['web', 'email', 'whatsapp'], ['sms'], true],
            ['on-call', 'incident.assigned.on_call', null, ['web', 'sms'], ['voice'], true],
            ['weekly-digest', 'report.ready', 'low', ['email'], [], false],
        ];

        foreach ($policies as [$code, $type, $priority, $allowed, $fallback, $active]) {
            TenantNotificationPolicy::query()->create([
                'team_id' => $this->ctx->team->id,
                'policy_code' => $code,
                'notification_type' => $type,
                'priority' => $priority,
                'allowed_channels_json' => $allowed,
                'fallback_channels_json' => $fallback,
                'recipient_rules_json' => ['roles' => ['monitorista', 'supervisor']],
                'quiet_hours_json' => $code === 'default' ? ['start' => '23:00', 'end' => '06:00', 'except_priorities' => ['critical', 'high']] : null,
                'escalation_rules_json' => null,
                'is_active' => $active,
            ]);
            $this->ctx->count('tenant_notification_policies');
        }

        $this->changed = true;
    }

    private function seedScheduleProfile(): void
    {
        if (TenantScheduleProfile::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $weekday = ['start' => '06:00', 'end' => '22:00'];

        TenantScheduleProfile::query()->create([
            'team_id' => $this->ctx->team->id,
            'profile_code' => 'default',
            'timezone' => $this->ctx->team->timezone ?? 'America/Mexico_City',
            'operating_hours_json' => [
                'monday' => $weekday, 'tuesday' => $weekday, 'wednesday' => $weekday,
                'thursday' => $weekday, 'friday' => $weekday,
                'saturday' => ['start' => '07:00', 'end' => '15:00'],
            ],
            'holidays_json' => ['2026-09-16', '2026-11-16', '2026-12-25', '2027-01-01'],
            'shift_rules_json' => [
                ['name' => 'Matutino', 'start' => '06:00', 'end' => '14:00'],
                ['name' => 'Vespertino', 'start' => '14:00', 'end' => '22:00'],
                ['name' => 'Nocturno', 'start' => '22:00', 'end' => '06:00'],
            ],
            'after_hours_behavior_json' => ['movement_alert' => true, 'escalate_to' => 'supervisor'],
            'is_active' => true,
        ]);
        $this->ctx->count('tenant_schedule_profiles');
        $this->changed = true;
    }

    private function seedSlas(): void
    {
        if (TenantIncidentSla::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $targets = ['critical' => 300, 'high' => 1_800, 'medium' => 3_600, 'low' => 14_400];

        foreach (IncidentPriority::query()->get() as $priority) {
            if (! isset($targets[$priority->code])) {
                continue;
            }

            TenantIncidentSla::query()->create([
                'team_id' => $this->ctx->team->id,
                'incident_priority_id' => $priority->id,
                'sla_seconds' => $targets[$priority->code],
            ]);
            $this->ctx->count('tenant_incident_slas');
        }

        $this->changed = true;
    }

    private function seedBranding(): void
    {
        if (TenantBranding::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        TenantBranding::query()->create([
            'team_id' => $this->ctx->team->id,
            'logo_url' => null,
            'primary_color' => '#00809f',
            'secondary_color' => '#0a0a0a',
            'display_name' => $this->ctx->team->name,
            'email_signature' => "Centro de monitoreo {$this->ctx->team->name}\nAtención 24/7 · monitoreo@{$this->ctx->team->slug}.test",
            'custom_domain' => null,
        ]);
        $this->ctx->count('tenant_brandings');
    }

    private function seedRuleOverrides(): void
    {
        if ($this->ctx->light || TenantRuleOverride::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        // Con datos reales los overrides quedan inactivos: se ven en Reglas
        // pero no cambian las decisiones del pipeline en vivo.
        $active = ! $this->ctx->hasRealData;

        $overrides = [
            ['panic_button_incident', RuleOverrideType::ForceHumanReview, [], 'Todo pánico pasa por un monitorista antes de cerrar.', $active],
            ['camera_obstructed_alert', RuleOverrideType::ChangePriority, ['priority' => 'high'], 'El cliente exige cámara operativa en todo momento.', $active],
            ['speeding_log_only', RuleOverrideType::ChangeThreshold, ['risk_score' => 70], 'Reducir ruido de excesos leves en autopista.', $active],
            ['geofence_exit_alert', RuleOverrideType::DisableRule, [], 'Temporada de rutas especiales: salidas de geocerca esperadas.', false],
            ['collision_escalate', RuleOverrideType::ReplaceEscalationPolicy, ['escalation_policy_code' => 'critical-emergency'], 'Colisiones siguen el protocolo de emergencia crítica.', $active],
        ];

        foreach ($overrides as [$code, $type, $config, $reason, $isActive]) {
            TenantRuleOverride::query()->create([
                'team_id' => $this->ctx->team->id,
                'base_rule_code' => $code,
                'override_type' => $type,
                'override_config_json' => $config,
                'reason' => $reason,
                'is_active' => $isActive,
            ]);
            $this->ctx->count('tenant_rule_overrides');
        }

        $this->changed = true;
    }

    private function seedEscalation(): void
    {
        if (TenantEscalationConfig::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        // Sólo pasa por aquí un tenant con datos reales (el pack ya creó uno
        // en los nuevos): se muestra como borrador inactivo.
        TenantEscalationConfig::query()->create([
            'team_id' => $this->ctx->team->id,
            'escalation_type' => 'after_hours_panic',
            'trigger_conditions_json' => ['incident_type' => 'panic_emergency'],
            'steps_json' => [
                ['delay_minutes' => 0, 'channels' => ['web', 'push'], 'recipient' => 'monitorista', 'contacts' => [], 'attempts' => 1],
                ['delay_minutes' => 10, 'channels' => ['sms', 'email'], 'recipient' => 'supervisor', 'contacts' => [], 'attempts' => 1],
            ],
            'time_constraints_json' => ['only_outside_operating_hours' => true],
            'is_active' => false,
        ]);
        $this->ctx->count('tenant_escalation_configs');
        $this->changed = true;
    }

    /**
     * @return array<string, int>
     */
    private function effectiveSlas(): array
    {
        $slas = $this->ctx->slaSeconds;

        foreach (IncidentPriority::query()->get() as $priority) {
            $tenant = TenantIncidentSla::query()
                ->where('team_id', $this->ctx->team->id)
                ->where('incident_priority_id', $priority->id)
                ->value('sla_seconds');

            $slas[$priority->code] = (int) ($tenant ?? $priority->sla_seconds ?? $slas[$priority->code] ?? 3_600);
        }

        return $slas;
    }
}
