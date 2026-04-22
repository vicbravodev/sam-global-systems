# Tenant Config

## 1. Purpose

Personalize SAM's behavior per tenant — rules, thresholds, AI profiles, notification policies, branding, schedules — without code changes. This domain provides a layered configuration system with deterministic precedence resolution, enabling each tenant to fine-tune operational behavior while inheriting sensible defaults from their plan and the system baseline.

## 2. Responsibilities

- Store and resolve per-tenant settings with typed values and grouping
- Allow tenants to override base rules (disable, change thresholds, change outcomes)
- Define tenant-level notification policies (channels, quiet hours, escalation rules)
- Maintain AI profiles controlling automation level, risk tolerance, and media strategy
- Configure escalation chains with trigger conditions and step sequences
- Define operating schedules with timezones, shifts, holidays, and after-hours behavior
- Version tenant configuration snapshots for auditing and rollback
- Enforce deterministic config resolution precedence: tenant override → tenant setting → plan default → system default

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Admin / API | Setting updates, rule overrides, AI profile changes | Inertia pages / API |
| Tenancy module | Plan defaults for new tenants | Service call at subscription creation |
| System seeder | Global system defaults | Migration / seeder |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Decisions module | Rule overrides that modify decision behavior | Service call to `ApplyTenantRuleOverrides` |
| AI module | AI profile (automation level, risk tolerance, media strategy) | Service call to `ResolveTenantAIProfile` |
| Automation module | Escalation configurations and workflow parameters | Service call to `ResolveTenantSetting` |
| Notifications module | Notification policies (channels, quiet hours, fallback) | Service call to `ResolveTenantNotificationPolicy` |
| All modules | Generic settings resolved by key | Service call to `ResolveTenantSetting` |
| Audit module | Configuration change events | Domain events |

## 4. Entities

### 4.1 Tenant Settings (`tenant_settings`)

Key-value store for per-tenant configuration with typed values and grouping.

```php
Schema::create('tenant_settings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('setting_key');
    $table->string('setting_group'); // enum
    $table->jsonb('value_json');
    $table->string('value_type'); // enum
    $table->unsignedInteger('version')->default(1);
    $table->boolean('is_active')->default(true);
    $table->string('updated_by_type'); // enum
    $table->unsignedBigInteger('updated_by_id')->nullable();
    $table->timestamps();

    $table->unique(['team_id', 'setting_key']);
});
```

**Enum `SettingGroup`**: `Operational`, `Notification`, `Ai`, `Escalation`, `Branding`, `Schedule`, `Compliance`

**Enum `SettingValueType`**: `String`, `Number`, `Boolean`, `Json`, `Array`

**Enum `SettingUpdatedByType`**: `User`, `System`

### 4.2 Tenant Rule Overrides (`tenant_rule_overrides`)

Per-tenant overrides for base decision rules and policies.

```php
Schema::create('tenant_rule_overrides', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('base_rule_code');
    $table->string('override_type'); // enum
    $table->jsonb('override_config_json');
    $table->text('reason')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['team_id', 'base_rule_code']);
});
```

**Enum `RuleOverrideType`**: `DisableRule`, `ChangeThreshold`, `ChangePriority`, `ChangeOutcome`, `ForceHumanReview`, `ReplaceEscalationPolicy`

### 4.3 Tenant Notification Policies (`tenant_notification_policies`)

Per-tenant policies controlling notification routing and behavior.

```php
Schema::create('tenant_notification_policies', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('policy_code');
    $table->string('notification_type')->nullable();
    $table->string('priority')->nullable();
    $table->jsonb('allowed_channels_json');
    $table->jsonb('fallback_channels_json')->nullable();
    $table->jsonb('recipient_rules_json')->nullable();
    $table->jsonb('quiet_hours_json')->nullable();
    $table->jsonb('escalation_rules_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['team_id', 'notification_type']);
});
```

### 4.4 Tenant AI Profiles (`tenant_ai_profiles`)

Per-tenant AI behavior configuration controlling automation aggressiveness and decision-making strategy.

```php
Schema::create('tenant_ai_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('profile_code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->jsonb('prompt_overrides_json')->nullable();
    $table->string('risk_tolerance'); // enum
    $table->string('false_positive_tolerance'); // enum
    $table->string('automation_level'); // enum
    $table->string('media_strategy'); // enum
    $table->jsonb('human_review_policy_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique('team_id');
});
```

**Enum `RiskTolerance`**: `Low`, `Medium`, `High`

**Enum `FalsePositiveTolerance`**: `Low`, `Medium`, `High`

**Enum `AutomationLevel`**: `Conservative`, `Assisted`, `SemiAutomatic`, `HighlyAutomated`

**Enum `MediaStrategy`**: `Optional`, `Preferred`, `RequiredForCritical`, `WaitBeforeDeciding`

### 4.5 Tenant Escalation Configs (`tenant_escalation_configs`)

Per-tenant escalation chain definitions.

```php
Schema::create('tenant_escalation_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('escalation_type');
    $table->jsonb('trigger_conditions_json');
    $table->jsonb('steps_json');
    $table->jsonb('time_constraints_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 4.6 Tenant Schedule Profiles (`tenant_schedule_profiles`)

Operating schedule definitions including timezone, shifts, holidays, and after-hours behavior.

```php
Schema::create('tenant_schedule_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('profile_code');
    $table->string('timezone');
    $table->jsonb('operating_hours_json');
    $table->jsonb('holidays_json')->nullable();
    $table->jsonb('shift_rules_json')->nullable();
    $table->jsonb('after_hours_behavior_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Example `operating_hours_json`:**

```json
{
    "monday": { "start": "08:00", "end": "18:00" },
    "tuesday": { "start": "08:00", "end": "18:00" },
    "saturday": null,
    "sunday": null
}
```

**Example `after_hours_behavior_json`:**

```json
{
    "suppress_low_priority": true,
    "escalation_policy": "on_call_only",
    "notification_channels": ["sms", "push"]
}
```

### 4.7 Tenant Config Versions (`tenant_config_versions`)

Point-in-time snapshots of the entire tenant configuration for audit and rollback.

```php
Schema::create('tenant_config_versions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('version');
    $table->jsonb('snapshot_json');
    $table->string('created_by_type'); // enum
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->timestamps();
});
```

### Config Resolution Precedence

Settings are resolved using a deterministic hierarchy (highest to lowest priority):

1. **Tenant-specific override** — Active `tenant_rule_overrides` or `tenant_settings` for the specific key
2. **Tenant setting** — Value from `tenant_settings` for the tenant
3. **Plan/feature default** — Default value associated with the tenant's current plan (stored in `plans` or `tenant_features`)
4. **System global default** — Hard-coded or seeded system-wide default

```php
public function resolve(int $teamId, string $settingKey, mixed $systemDefault = null): mixed
{
    // 1. Check tenant_settings
    $setting = TenantSetting::where('team_id', $teamId)
        ->where('setting_key', $settingKey)
        ->where('is_active', true)
        ->first();

    if ($setting) {
        return $setting->typed_value;
    }

    // 2. Check plan default
    $planDefault = $this->getPlanDefault($teamId, $settingKey);
    if ($planDefault !== null) {
        return $planDefault;
    }

    // 3. Fall back to system default
    return $systemDefault;
}
```

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `ResolveTenantSetting` | Look up a setting by key for a given team, walking the resolution precedence chain (tenant override → tenant setting → plan default → system default). Returns the typed value. |
| `UpdateTenantSetting` | Create or update a `tenant_settings` record, increment `version`, and dispatch `TenantSettingUpdated` event. Triggers a config version snapshot if the setting group is critical. |
| `ApplyTenantRuleOverrides` | Given a base rule code, load all active `tenant_rule_overrides` for the team and apply them to the rule evaluation context. Returns the modified rule parameters. |
| `ResolveTenantNotificationPolicy` | Load the tenant's notification policy for a given `notification_type` and `priority`. Returns allowed channels, fallback channels, quiet hours, and escalation rules. |
| `ResolveTenantAIProfile` | Load the active `tenant_ai_profiles` record for the team. Returns automation level, risk tolerance, false positive tolerance, and media strategy. |
| `ResolveTenantSchedule` | Load the active `tenant_schedule_profiles` for the team. Determine if the current time is within operating hours, identify the current shift, and return the after-hours behavior if applicable. |
| `SnapshotTenantConfig` | Collect all `tenant_settings`, `tenant_rule_overrides`, `tenant_notification_policies`, `tenant_ai_profiles`, `tenant_escalation_configs`, and `tenant_schedule_profiles` into a single JSON document and store it in `tenant_config_versions`. |

## 6. Jobs

This domain does not define queue jobs. Configuration resolution is synchronous and cached. Snapshot creation is triggered inline when critical settings change.

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `TenantSettingUpdated` | A `tenant_settings` record is created or updated | `teamId`, `settingKey`, `settingGroup`, `previousValue`, `newValue`, `updatedByType`, `updatedById` |
| `TenantAIProfileChanged` | A `tenant_ai_profiles` record is created or updated | `teamId`, `automationLevel`, `riskTolerance`, `mediaStrategy` |

## 8. Broadcasting Events

None. Configuration changes take effect on the next request/evaluation cycle. Frontends read configuration on page load via Inertia props.

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/{current_team}/settings/config` | `TenantConfigController@index` | View all tenant settings grouped by `setting_group` |
| `PUT` | `/{current_team}/settings/config` | `TenantConfigController@update` | Batch update tenant settings |
| `GET` | `/{current_team}/settings/rules` | `TenantRuleOverrideController@index` | List rule overrides |
| `POST` | `/{current_team}/settings/rules` | `TenantRuleOverrideController@store` | Create a rule override |
| `PUT` | `/{current_team}/settings/rules/{override}` | `TenantRuleOverrideController@update` | Update a rule override |
| `DELETE` | `/{current_team}/settings/rules/{override}` | `TenantRuleOverrideController@destroy` | Delete a rule override |
| `GET` | `/{current_team}/settings/notifications` | `TenantNotificationPolicyController@index` | List notification policies |
| `PUT` | `/{current_team}/settings/notifications` | `TenantNotificationPolicyController@update` | Update notification policies |
| `GET` | `/{current_team}/settings/ai-profile` | `TenantAIProfileController@show` | View the tenant AI profile |
| `PUT` | `/{current_team}/settings/ai-profile` | `TenantAIProfileController@update` | Update the tenant AI profile |
| `GET` | `/{current_team}/settings/escalation` | `TenantEscalationConfigController@index` | List escalation configs |
| `POST` | `/{current_team}/settings/escalation` | `TenantEscalationConfigController@store` | Create escalation config |
| `PUT` | `/{current_team}/settings/escalation/{config}` | `TenantEscalationConfigController@update` | Update escalation config |
| `GET` | `/{current_team}/settings/schedule` | `TenantScheduleProfileController@index` | List schedule profiles |
| `PUT` | `/{current_team}/settings/schedule/{profile}` | `TenantScheduleProfileController@update` | Update schedule profile |
| `GET` | `/{current_team}/settings/versions` | `TenantConfigVersionController@index` | List config version history |
| `GET` | `/{current_team}/settings/versions/{version}` | `TenantConfigVersionController@show` | View a specific config version snapshot |

## 10. Business Rules

1. **Strict tenant isolation** — Every setting, override, policy, and profile belongs to exactly one tenant. No cross-tenant contamination. Enforced by `BelongsToTenant` trait and `team_id` FK.
2. **Deterministic precedence** — Config resolution follows a documented, deterministic order: tenant override → tenant setting → plan default → system default. This precedence is the same everywhere it is applied.
3. **Critical config versioning** — Changes to AI profiles, escalation configs, and rule overrides automatically trigger `SnapshotTenantConfig` to create a new `tenant_config_versions` record. This provides rollback capability and audit trail.
4. **AI profile controls behavior** — The `automation_level` field directly controls how aggressively the AI module acts:
   - `conservative`: AI suggests, human decides
   - `assisted`: AI decides low-risk, human reviews high-risk
   - `semi_automatic`: AI decides most, human reviews exceptions
   - `highly_automated`: AI decides all, human reviews post-facto
5. **Media strategy enforcement** — The `media_strategy` field controls whether the AI waits for media before making decisions:
   - `optional`: Decide immediately regardless of media
   - `preferred`: Wait briefly for media if expected, decide without if timeout
   - `required_for_critical`: Block critical decisions until media arrives
   - `wait_before_deciding`: Always wait for media before any decision
6. **Rule override types are composable** — Multiple overrides can apply to the same base rule (e.g., `change_threshold` + `change_priority`). Overrides are applied in order of `override_type` specificity.
7. **Schedule-aware operations** — After-hours behavior from `tenant_schedule_profiles` affects notification routing, escalation timing, and automation aggressiveness. The `ResolveTenantSchedule` service determines the current operational context.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Decisions** | Calls `ApplyTenantRuleOverrides` to modify rule evaluation based on tenant overrides. Reads `ResolveTenantAIProfile` for automation level and media strategy. |
| **AI** | Reads `ResolveTenantAIProfile` for prompt overrides, risk tolerance, and human review policy. The AI module adjusts its behavior based on the tenant's automation level. |
| **Automation** | Reads escalation configs via `ResolveTenantSetting` and `tenant_escalation_configs`. Uses schedule profiles to determine escalation timing. |
| **Notifications** | Reads tenant notification policies via `ResolveTenantNotificationPolicy`. Quiet hours, channel preferences, and fallback rules come from this domain. |
| **Tenancy** | Plan defaults feed into the resolution chain. `team_id` FK on all entities. Uses `BelongsToTenant` trait. |
| **Audit** | `TenantSettingUpdated` and `TenantAIProfileChanged` events are captured for compliance. Config version snapshots provide historical record. |
| **Access** | Policies check team membership and admin-level permissions for modifying tenant configuration. |

## 12. Usage Metering

None. Tenant configuration is a platform capability, not a metered activity.

## 13. Technical Considerations

### Caching

- Tenant settings are cached in Valkey by `(team_id, setting_key)` with 5-minute TTL.
- AI profiles are cached by `team_id` with 5-minute TTL.
- Notification policies are cached by `(team_id, notification_type)` with 5-minute TTL.
- All caches are invalidated on write via `UpdateTenantSetting`, `TenantAIProfileController@update`, etc.

```php
Cache::tags(["tenant_config:{$teamId}"])->flush();
```

### Validation

- `value_json` is validated against the expected `value_type` before saving. A `number` type setting rejects non-numeric JSON values.
- `override_config_json` is validated against the base rule's expected parameter schema.
- AI profile enums are validated at the form request level.

### Default Seeding

System defaults are seeded via a `TenantConfigSeeder` that runs during deployment:

```php
// Default AI profile for new tenants
$defaults = [
    'risk_tolerance' => 'medium',
    'false_positive_tolerance' => 'medium',
    'automation_level' => 'assisted',
    'media_strategy' => 'preferred',
];
```

When a new tenant is created, `CreateTenant` action seeds initial settings and an AI profile based on the selected plan.

### Config Snapshot Structure

```json
{
    "version": 12,
    "captured_at": "2026-04-11T14:30:00Z",
    "settings": {
        "operational.max_concurrent_incidents": 50,
        "ai.confidence_threshold": 0.75
    },
    "rule_overrides": [
        {
            "base_rule_code": "speed_violation",
            "override_type": "change_threshold",
            "config": { "threshold_mph": 80 }
        }
    ],
    "ai_profile": {
        "automation_level": "semi_automatic",
        "risk_tolerance": "medium"
    },
    "notification_policies": [],
    "escalation_configs": [],
    "schedule_profiles": []
}
```

### Migration from Hard-Coded Config

Any hard-coded threshold, toggle, or behavior flag in other modules should be extracted into a `tenant_settings` key so it can be configured per-tenant. The `ResolveTenantSetting` service provides a clean fallback chain that allows incremental migration.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_setting_resolves_with_override_precedence` | A tenant setting overrides the plan default, which overrides the system default |
| `test_ai_profile_controls_evaluation_behavior` | An AI profile with `automation_level = conservative` results in the AI module requesting human review for all decisions |
| `test_notification_policy_determines_channels` | A notification policy with `allowed_channels_json = ["email", "sms"]` restricts delivery to those channels only |
| `test_config_version_snapshot_created_on_change` | Updating an AI profile triggers `SnapshotTenantConfig` and creates a new `tenant_config_versions` record |
| `test_schedule_profile_identifies_operating_hours` | `ResolveTenantSchedule` correctly identifies the current time as within or outside operating hours |
| `test_rule_override_disables_specific_rule` | A `DisableRule` override for `speed_violation` causes `ApplyTenantRuleOverrides` to exclude that rule |
| `test_rule_override_changes_threshold` | A `ChangeThreshold` override modifies the threshold parameter returned by `ApplyTenantRuleOverrides` |
| `test_multiple_overrides_compose_on_same_rule` | Two overrides (`ChangeThreshold` + `ChangePriority`) for the same rule both apply correctly |
| `test_media_strategy_wait_before_deciding` | An AI profile with `media_strategy = wait_before_deciding` causes the AI module to defer evaluation until media arrives |
| `test_after_hours_behavior_applied_correctly` | During after-hours, `ResolveTenantSchedule` returns the configured after-hours behavior (suppress low priority, on-call only) |
| `test_setting_cache_invalidated_on_update` | Updating a tenant setting invalidates the Valkey cache for that key |
| `test_tenant_config_is_strictly_isolated` | A tenant cannot read or modify another tenant's settings, overrides, or profiles |
| `test_default_ai_profile_seeded_for_new_tenant` | Creating a new tenant via `CreateTenant` seeds an AI profile with plan-appropriate defaults |
| `test_invalid_value_type_rejected` | Attempting to save a non-numeric value for a `number` type setting is rejected by validation |
