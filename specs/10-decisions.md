# Decisions

## 1. Purpose

Take the final system decision for each evaluated event by combining AI evaluation output, tenant-specific rules, and escalation policies. Translates AI suggestions into governed, auditable, and deterministic outcomes. The decision engine ensures AI does not act alone — business rules always mediate between AI recommendations and operational actions.

## 2. Responsibilities

- Evaluate tenant-specific rule sets against AI evaluation output
- Resolve the final decision outcome (ignore, log, alert, incident, escalate, require human review)
- Apply hierarchical rule precedence (safety rules → tenant overrides → AI recommendation → escalation → fallback)
- Resolve escalation paths when decisions require further action
- Generate full decision traces explaining how each decision was reached
- Support manual overrides by authorized users with full audit trail
- Reevaluate decisions when AI reevaluations produce updated classifications
- Seed and manage a catalog of decision outcomes

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| AI | `AIEventEvaluation` (classification, risk, confidence, recommended actions) | `AIEvaluationCompleted` domain event |
| TenantConfig | Tenant rule sets, escalation policies, automation preferences | Service call / Eloquent |
| Context | `EventContextSnapshot` for additional decision factors | Eloquent relationship |
| Users | Manual override requests | Inertia page / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Incidents | `Decision` with outcome `INCIDENT` or `ESCALATE` | `DecisionMade` domain event |
| Automation | `Decision` with associated automation actions | `DecisionMade` domain event |
| Frontend | Decision notification | `DecisionMadeBroadcast` on `private-accounts.{teamId}` |
| Audit | Decision traces and override history | `decision_traces`, `decision_overrides` tables |

## 4. Entities

### 4.1 Decision Outcomes (`decision_outcomes`)

Catalog of possible decision results. Seeded at deployment.

```php
Schema::create('decision_outcomes', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('is_terminal')->default(false);
    $table->timestamps();
});
```

**Seed values**: `IGNORE`, `LOG_ONLY`, `ALERT`, `INCIDENT`, `ESCALATE`, `REQUIRE_HUMAN_REVIEW`

### 4.2 Rule Sets (`rule_sets`)

Groups of decision rules that can be assigned to tenants or applied globally.

```php
Schema::create('rule_sets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->unsignedInteger('version')->default(1);
    $table->boolean('is_default')->default(false);
    $table->boolean('is_active')->default(true);
    $table->jsonb('applies_to_json')->nullable();
    $table->timestamps();

    $table->unique(['team_id', 'code']);
    $table->index(['team_id', 'is_active']);
});
```

### 4.3 Decision Rules (`decision_rules`)

Individual rules within a rule set that match conditions and produce outcomes.

```php
Schema::create('decision_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('ruleset_id')->constrained('rule_sets')->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('scope'); // global, tenant, event_type, category, asset_type, operation_profile
    $table->unsignedTinyInteger('priority')->default(0);
    $table->jsonb('conditions_json');
    $table->foreignId('outcome_override')->nullable()->constrained('decision_outcomes');
    $table->foreignId('escalation_policy_id')->nullable()->constrained('escalation_policies');
    $table->unsignedBigInteger('automation_action_id')->nullable();
    $table->boolean('stop_processing')->default(false);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['ruleset_id', 'priority']);
});
```

**Enum `RuleScope`**: `Global`, `Tenant`, `EventType`, `Category`, `AssetType`, `OperationProfile`

### 4.4 Escalation Policies (`escalation_policies`)

Defines multi-step escalation workflows triggered by decisions.

```php
Schema::create('escalation_policies', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->jsonb('trigger_conditions_json')->nullable();
    $table->jsonb('escalation_steps_json');
    $table->unsignedInteger('max_wait_seconds')->nullable();
    $table->boolean('requires_acknowledgement')->default(false);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 4.5 Decisions (`decisions`)

The core decision record linking an evaluated event to a governed outcome.

```php
Schema::create('decisions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ai_evaluation_id')->nullable()->constrained('ai_event_evaluations')->nullOnDelete();
    $table->foreignId('ruleset_id')->nullable()->constrained('rule_sets')->nullOnDelete();
    $table->string('decision_code');
    $table->text('decision_reason')->nullable();
    $table->string('priority_level'); // low, normal, high, urgent, critical
    $table->boolean('requires_human_review')->default(false);
    $table->boolean('is_automated')->default(true);
    $table->foreignId('escalation_policy_id')->nullable()->constrained('escalation_policies')->nullOnDelete();
    $table->foreignId('outcome_id')->constrained('decision_outcomes');
    $table->unsignedBigInteger('context_snapshot_id')->nullable();
    $table->timestamp('decided_at');
    $table->timestamps();

    $table->index(['team_id', 'decided_at']);
    $table->index('normalized_event_id');
});
```

**Enum `DecisionPriority`**: `Low`, `Normal`, `High`, `Urgent`, `Critical`

### 4.6 Decision Traces (`decision_traces`)

Step-by-step audit trail of how a decision was reached.

```php
Schema::create('decision_traces', function (Blueprint $table) {
    $table->id();
    $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
    $table->string('rule_code')->nullable();
    $table->string('source_type'); // ai, rule, tenant_policy, escalation_policy, fallback, manual_override
    $table->unsignedBigInteger('source_reference_id')->nullable();
    $table->unsignedTinyInteger('step_order');
    $table->jsonb('input_fragment_json')->nullable();
    $table->jsonb('output_fragment_json')->nullable();
    $table->text('explanation')->nullable();
    $table->timestamps();
});
```

**Enum `DecisionSourceType`**: `Ai`, `Rule`, `TenantPolicy`, `EscalationPolicy`, `Fallback`, `ManualOverride`

### 4.7 Decision Overrides (`decision_overrides`)

Records when a user manually overrides a system decision.

```php
Schema::create('decision_overrides', function (Blueprint $table) {
    $table->id();
    $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
    $table->foreignId('overridden_by_user_id')->constrained('users');
    $table->string('previous_outcome');
    $table->string('new_outcome');
    $table->text('reason');
    $table->timestamps();
});
```

## 5. Services / Actions

### 5.1 `EvaluateDecisionRules`

**Path**: `app/Domains/Decisions/Actions/EvaluateDecisionRules.php`

```php
public function execute(AIEventEvaluation $eval, EventContextSnapshot $context): Decision
```

- Resolves the tenant's active rule set via `ApplyTenantRuleSet`
- Runs matched rules through `ResolveDecisionOutcome`
- Checks for escalation paths via `ResolveEscalationPath`
- Creates `Decision` record with outcome, priority, and reason
- Generates decision trace via `GenerateDecisionTrace`
- Dispatches `DecisionMade` domain event

### 5.2 `ResolveDecisionOutcome`

**Path**: `app/Domains/Decisions/Actions/ResolveDecisionOutcome.php`

```php
public function execute(AIEventEvaluation $eval, Collection $matchedRules): DecisionOutcome
```

- Applies hierarchical rule evaluation order:
  1. Hard safety rules (non-overridable, `stop_processing = true`)
  2. Tenant-specific overrides
  3. AI recommendation (maps classification + priority to outcome)
  4. Escalation policies
  5. Fallback default outcome (`LOG_ONLY`)
- Returns the resolved `DecisionOutcome` model

### 5.3 `ResolveEscalationPath`

**Path**: `app/Domains/Decisions/Actions/ResolveEscalationPath.php`

```php
public function execute(Decision $decision): ?EscalationPolicy
```

- Checks if the decision's outcome or matched rules reference an escalation policy
- Returns the resolved `EscalationPolicy` or `null` if no escalation is needed
- Dispatches `EscalationTriggered` event when an escalation path is resolved

### 5.4 `ApplyTenantRuleSet`

**Path**: `app/Domains/Decisions/Actions/ApplyTenantRuleSet.php`

```php
public function execute(int $teamId, AIEventEvaluation $eval): Collection
```

- Loads the tenant's active rule set (or the default global rule set if none configured)
- Evaluates each rule's `conditions_json` against the evaluation's classification, risk, confidence, and event context
- Returns matched `DecisionRule` models sorted by priority (highest first)

### 5.5 `GenerateDecisionTrace`

**Path**: `app/Domains/Decisions/Actions/GenerateDecisionTrace.php`

```php
public function execute(Decision $decision, Collection $steps): void
```

- Creates `DecisionTrace` records for each step in the decision process
- Each trace records the source type, rule code, input/output fragments, and explanation
- Steps are ordered sequentially via `step_order`

### 5.6 `OverrideDecision`

**Path**: `app/Domains/Decisions/Actions/OverrideDecision.php`

```php
public function execute(Decision $decision, User $user, string $newOutcomeCode, string $reason): DecisionOverride
```

- Creates a `DecisionOverride` record preserving the original outcome
- Adds a trace step with `source_type = manual_override`
- Dispatches `DecisionOverridden` domain event

## 6. Jobs

### 6.1 `RunDecisionEngineJob`

- **Queue**: `decisions`
- **Retry**: 2
- **Payload**: `ai_evaluation_id`
- **Logic**:
  1. Load `AIEventEvaluation` with normalized event and context snapshot
  2. Call `EvaluateDecisionRules::execute()`
  3. If outcome is `INCIDENT` or `ESCALATE`, the downstream listener creates the incident

### 6.2 `ReevaluateDecisionJob`

- **Queue**: `decisions`
- **Retry**: 1
- **Payload**: `ai_evaluation_id` (from reevaluation)
- **Logic**:
  1. Load the updated `AIEventEvaluation`
  2. Run `EvaluateDecisionRules::execute()` to produce a new `Decision`
  3. Does NOT mutate the previous decision — creates a new record

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `DecisionMade` | `Decision $decision` | Decision engine completes and persists a decision |
| `DecisionOverridden` | `DecisionOverride $override, Decision $decision` | User manually overrides a decision outcome |
| `EscalationTriggered` | `Decision $decision, EscalationPolicy $policy` | Decision resolves to an escalation path |

## 8. Broadcasting Events

### `DecisionMadeBroadcast`

- **Channel**: `private-accounts.{teamId}`
- **Trigger**: When `DecisionMade` domain event fires
- **Payload**:
  ```json
  {
      "decision_id": 55,
      "normalized_event_id": 101,
      "outcome_code": "INCIDENT",
      "priority_level": "high",
      "requires_human_review": false,
      "decided_at": "2026-04-11T14:30:00Z"
  }
  ```

## 9. APIs / Endpoints

All tenant-scoped routes are prefixed with `/{current_team}`.

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| GET | `/{current_team}/decisions` | `DecisionController@index` | List decisions for team |
| GET | `/{current_team}/decisions/{decision}` | `DecisionController@show` | View decision detail with trace |
| POST | `/{current_team}/decisions/{decision}/override` | `DecisionController@override` | Manually override a decision |
| GET | `/{current_team}/decisions/rules` | `DecisionRuleController@index` | List tenant rule sets and rules |
| POST | `/{current_team}/decisions/rules` | `DecisionRuleController@store` | Create a new decision rule |
| PUT | `/{current_team}/decisions/rules/{rule}` | `DecisionRuleController@update` | Update an existing rule |
| DELETE | `/{current_team}/decisions/rules/{rule}` | `DecisionRuleController@destroy` | Deactivate a rule |
| GET | `/{current_team}/decisions/escalation-policies` | `EscalationPolicyController@index` | List escalation policies |
| POST | `/{current_team}/decisions/escalation-policies` | `EscalationPolicyController@store` | Create escalation policy |
| PUT | `/{current_team}/decisions/escalation-policies/{policy}` | `EscalationPolicyController@update` | Update escalation policy |

## 10. Business Rules

1. AI does NOT decide alone — the decision engine always applies business rules on top of AI recommendations. AI output is one input among many.
2. Each tenant can customize rule sets and escalation policies. Tenants without custom rules fall back to the global default rule set.
3. Same event + same rule set version = same decision. The engine is deterministic given identical inputs.
4. Every decision MUST have a trace explaining how it was reached. A decision without trace records is invalid.
5. Overrides by users are tracked and auditable — the original decision is preserved, and a `DecisionOverride` record links the old and new outcomes.
6. Reevaluation creates new decisions, does not mutate existing ones. Historical decisions remain intact for audit.
7. Hard safety rules (e.g., panic button events) cannot be overridden by tenant rules or AI recommendations and always produce `INCIDENT` or `ESCALATE` outcomes.
8. Low-confidence AI evaluations (below configurable threshold) automatically set `requires_human_review = true`.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **AI** | Receives `AIEvaluationCompleted` domain event to trigger `RunDecisionEngineJob` |
| **TenantConfig** | Reads tenant rule sets, escalation policies, and automation preferences |
| **Incidents** | `DecisionMade` with `INCIDENT` or `ESCALATE` outcome triggers incident creation |
| **Automation** | `DecisionMade` with automation actions triggers automation execution |
| **Context** | Reads `EventContextSnapshot` for additional decision factors |
| **Audit** | Decision traces and overrides provide full audit trail |
| **Broadcasting** | `DecisionMadeBroadcast` notifies frontend clients on `private-accounts.{teamId}` |

## 12. Usage Metering

No direct usage metering in this module. Decision processing is a core platform operation included in all plans. Downstream actions (incidents, automations, notifications) are metered by their respective modules.

## 13. Technical Considerations

### Rule Evaluation Engine

- Rules are evaluated in priority order (highest `priority` first within a rule set)
- `conditions_json` uses a structured condition format:
  ```json
  {
      "all": [
          { "field": "classification", "operator": "eq", "value": "real_event" },
          { "field": "risk_score", "operator": "gte", "value": 0.8 },
          { "field": "event_type", "operator": "in", "value": ["panic", "collision"] }
      ]
  }
  ```
- When a rule with `stop_processing = true` matches, no further rules are evaluated
- Supported operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `contains`, `is_null`, `is_not_null`

### Performance

- Cache active rule sets per tenant in Valkey with 5-minute TTL, invalidated on rule update
- `decisions` indexed on `(team_id, decided_at)` for dashboard queries and `(normalized_event_id)` for event lookups
- Rule evaluation is CPU-bound and fast (no I/O) — no need for separate queue processing of individual rules

### Idempotency

- `RunDecisionEngineJob` checks for an existing decision with the same `ai_evaluation_id` before creating a new one
- Decision overrides are append-only — multiple overrides on the same decision are allowed and tracked chronologically

### Seeder

```php
// database/seeders/DecisionOutcomeSeeder.php
$outcomes = [
    ['code' => 'IGNORE', 'name' => 'Ignore', 'is_terminal' => true],
    ['code' => 'LOG_ONLY', 'name' => 'Log Only', 'is_terminal' => true],
    ['code' => 'ALERT', 'name' => 'Alert', 'is_terminal' => false],
    ['code' => 'INCIDENT', 'name' => 'Create Incident', 'is_terminal' => false],
    ['code' => 'ESCALATE', 'name' => 'Escalate', 'is_terminal' => false],
    ['code' => 'REQUIRE_HUMAN_REVIEW', 'name' => 'Require Human Review', 'is_terminal' => false],
];
```

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_panic_event_high_risk_creates_incident_decision` | Panic event with high risk score produces `INCIDENT` outcome via safety rule |
| `test_low_confidence_forces_human_review` | AI evaluation with confidence below threshold sets `requires_human_review = true` |
| `test_tenant_rule_overrides_ai_recommendation` | Tenant-specific rule overrides AI's suggested outcome to a different decision |
| `test_decision_trace_records_all_steps` | Decision trace contains ordered steps for each source (AI, rules, escalation, fallback) |
| `test_manual_override_preserves_original_decision` | `OverrideDecision` creates override record and preserves original outcome |
| `test_deterministic_same_input_same_output` | Same evaluation + same rule set version produces identical decision on repeated runs |
| `test_safety_rule_cannot_be_overridden_by_tenant_rule` | Hard safety rule with `stop_processing = true` takes precedence over all tenant rules |
| `test_fallback_outcome_when_no_rules_match` | When no rules match, decision defaults to `LOG_ONLY` fallback outcome |
| `test_escalation_triggered_dispatches_event` | Decision with escalation policy dispatches `EscalationTriggered` domain event |
| `test_reevaluation_creates_new_decision` | Reevaluated AI evaluation produces a new decision without mutating the original |
| `test_decision_scoped_to_tenant` | Decisions are filtered by `team_id` via `BelongsToTenant` trait |
| `test_decision_made_broadcasts_to_tenant_channel` | `DecisionMadeBroadcast` is dispatched to `private-accounts.{teamId}` |
