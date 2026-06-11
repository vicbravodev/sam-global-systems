<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;

/**
 * SAM Default Config Pack (Roadmap V2-A5): the monitoring protocol SAM tuned
 * and operates, applied to a tenant as its factory configuration. The tenant
 * can modify everything afterwards — the pack only creates what is missing
 * and NEVER overwrites a value the tenant already has, so re-running it is
 * always safe (idempotent).
 *
 * Rows created by the pack carry `updated_by_type = System`, which is how
 * "SAM default" is distinguished from tenant-modified config. Each PR that
 * introduces a new tenant-facing setting adds its tuned default here and
 * bumps PACK_VERSION.
 */
class ApplyDefaultTenantConfig
{
    public const int PACK_VERSION = 1;

    public const string PACK_LABEL_PREFIX = 'sam-default-v';

    public const string RULESET_CODE = 'sam-default';

    public function __construct(
        private readonly TenantConfigResolver $resolver,
        private readonly SnapshotTenantConfig $snapshotTenantConfig,
    ) {}

    /**
     * @return array{settings_created: int, rules_created: int, escalation_created: bool, snapshot_version: int|null}
     */
    public function execute(Team $team): array
    {
        $summary = [
            'settings_created' => $this->seedSettings($team),
            'rules_created' => $this->seedDecisionRules($team),
            'escalation_created' => $this->seedEscalationConfig($team),
            'snapshot_version' => null,
        ];

        if ($summary['settings_created'] > 0 || $summary['rules_created'] > 0 || $summary['escalation_created']) {
            $version = $this->snapshotTenantConfig->execute($team->id, SettingUpdatedByType::System);

            $snapshot = $version->snapshot_json;
            $snapshot['label'] = self::PACK_LABEL_PREFIX.self::PACK_VERSION;
            $version->forceFill(['snapshot_json' => $snapshot])->save();

            $summary['snapshot_version'] = $version->version;
        }

        return $summary;
    }

    /**
     * The protocol defaults SAM operates with. Listed per setting:
     * key, group, value type and tuned value.
     *
     * @return array<int, array{key: string, group: SettingGroup, type: SettingValueType, value: mixed}>
     */
    public static function defaultSettings(): array
    {
        return [
            // Investigate automatically: footage + stills around every critical event.
            ['key' => 'media.auto_request_on_critical', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Boolean, 'value' => true],
            ['key' => 'media.clip_window_seconds', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 60],
            ['key' => 'media.still_window_minutes', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 30],
            ['key' => 'media.still_count', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 6],
            // Correlate what happened on the road around the event.
            ['key' => 'context.safety_correlation_minutes', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 30],
            // Always verify a panic with a human by phone (1 = real, 2 = error).
            ['key' => 'voice.verification_enabled', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Boolean, 'value' => true],
            ['key' => 'voice.call_attempts', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 3],
            ['key' => 'voice.retry_delay_seconds', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 90],
            // Proactive monitoring: alert when an asset stops reporting (V2-C1).
            ['key' => 'monitoring.offline_alert_minutes', 'group' => SettingGroup::Operational, 'type' => SettingValueType::Number, 'value' => 15],
        ];
    }

    private function seedSettings(Team $team): int
    {
        $created = 0;

        foreach (self::defaultSettings() as $definition) {
            $setting = TenantSetting::withoutGlobalScopes()->firstOrCreate(
                [
                    'team_id' => $team->id,
                    'setting_key' => $definition['key'],
                ],
                [
                    'setting_group' => $definition['group'],
                    'value_json' => ['value' => $definition['value']],
                    'value_type' => $definition['type'],
                    'version' => 1,
                    'is_active' => true,
                    'updated_by_type' => SettingUpdatedByType::System,
                ],
            );

            if ($setting->wasRecentlyCreated) {
                $created++;
                $this->resolver->invalidate($team->id, $definition['key']);
            }
        }

        return $created;
    }

    /**
     * The SAM panic protocol: a panic ALWAYS opens an incident, except when
     * the provider resolved it AND the unit sits parked at its own base —
     * that one goes to human review (never auto-closed; a cancelled panic on
     * the road can be coercion and never degrades).
     *
     * Skipped entirely when the tenant already owns any ruleset: existing
     * decision config is theirs to manage.
     */
    private function seedDecisionRules(Team $team): int
    {
        if (RuleSet::withoutGlobalScopes()->where('team_id', $team->id)->exists()) {
            return 0;
        }

        $incident = DecisionOutcome::query()->where('code', 'INCIDENT')->first();
        $review = DecisionOutcome::query()->where('code', 'REQUIRE_HUMAN_REVIEW')->first();

        if ($incident === null || $review === null) {
            return 0;
        }

        $ruleSet = RuleSet::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'code' => self::RULESET_CODE,
            'name' => 'Protocolo SAM (recomendado)',
            'description' => 'Reglas de monitoreo afinadas por SAM. Puedes ajustarlas o desactivarlas; el protocolo de pánico nunca degrada una alerta en carretera.',
            'version' => self::PACK_VERSION,
            'is_default' => true,
            'is_active' => true,
            'applies_to_json' => null,
        ]);

        DecisionRule::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleSet->id,
            'code' => 'panic-false-alarm-review',
            'name' => 'Pánico resuelto + en base → revisión humana',
            'description' => 'Un pánico que el proveedor ya resolvió Y de una unidad estacionada en su base pasa a revisión humana en vez de incidente automático. Un pánico cancelado en carretera nunca se degrada (posible coacción).',
            'scope' => RuleScope::EventType,
            'priority' => 110,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                    ['field' => 'external_resolved', 'operator' => 'eq', 'value' => true],
                    ['field' => 'parked_at_base', 'operator' => 'eq', 'value' => true],
                ],
            ],
            'outcome_override' => $review->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);

        DecisionRule::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleSet->id,
            'code' => 'after-hours-movement-incident',
            'name' => 'Movimiento fuera de horario → incidente',
            'description' => 'Una unidad en movimiento fuera del horario operativo del tenant abre un incidente (señal de robo o mal uso). Requiere un perfil de horario activo (V2-C2).',
            'scope' => RuleScope::EventType,
            'priority' => 90,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'after_hours_movement'],
                ],
            ],
            'outcome_override' => $incident->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);

        DecisionRule::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleSet->id,
            'code' => 'panic-button-always-incident',
            'name' => 'Botón de pánico → incidente',
            'description' => 'Todo evento panic_button abre un incidente (regla dura de seguridad).',
            'scope' => RuleScope::EventType,
            'priority' => 100,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                ],
            ],
            'outcome_override' => $incident->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);

        return 3;
    }

    /**
     * Default escalation ladder (contacts stay empty → notifications fan out
     * to the team until the tenant fills its monitoring-center contacts):
     * voz+push inmediato → SMS/WhatsApp/email a los 5 min → voz+SMS a los 15.
     */
    private function seedEscalationConfig(Team $team): bool
    {
        $exists = TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->exists();

        if ($exists) {
            return false;
        }

        TenantEscalationConfig::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'escalation_type' => 'incident_critical',
            'trigger_conditions_json' => [],
            'steps_json' => [
                ['delay_minutes' => 0, 'channels' => ['voice', 'push', 'web'], 'attempts' => 2, 'retry_minutes' => 3, 'contacts' => []],
                ['delay_minutes' => 5, 'channels' => ['sms', 'whatsapp', 'email'], 'attempts' => 1, 'contacts' => []],
                ['delay_minutes' => 15, 'channels' => ['voice', 'sms'], 'attempts' => 2, 'retry_minutes' => 5, 'contacts' => []],
            ],
            'time_constraints_json' => null,
            'is_active' => true,
        ]);

        return true;
    }
}
