# Tenancy (COMPLETADO)

## 1. Purpose

Extend the existing `Team` model into a full SaaS tenancy system with subscriptions, plans, usage metering, feature flags, and branding. Every billable entity in SAM Global Systems is a Team (tenant). This module owns the billing lifecycle, usage tracking pipeline, and tenant configuration.

## 2. Responsibilities

- Define plans and their billing rates (base price + metered overage)
- Manage subscription lifecycle (trial → active → past_due → suspended → canceled → expired)
- Record, aggregate, and report usage events across all modules
- Maintain per-tenant feature flags derived from plan defaults, manual overrides, promos, and beta access
- Store tenant branding (logo, colors, custom domain)
- Generate invoice snapshots for audit and reconciliation
- Integrate with Cashier Stripe for payment processing and metered billing
- Broadcast usage updates to connected clients in real time

## 3. Inputs / Outputs

### Inputs


| Source        | Data                                                     | Channel                           |
| ------------- | -------------------------------------------------------- | --------------------------------- |
| Other domains | Usage events via `RecordUsageEvent` action               | Synchronous call / dispatched job |
| Stripe        | Webhooks (`invoice.upcoming`, `customer.subscription.*`) | HTTP POST `/stripe/webhook`       |
| Admin         | Plan/feature configuration                               | Inertia pages / API               |
| Scheduler     | Daily aggregation trigger                                | `AggregateUsageJob`               |


### Outputs


| Target        | Data                                           | Channel                                                |
| ------------- | ---------------------------------------------- | ------------------------------------------------------ |
| Stripe        | Metered usage reports via `reportMeterEvent()` | Cashier Stripe SDK                                     |
| Frontend      | Usage counter updates                          | `UsageUpdatedBroadcast` on `private-accounts.{teamId}` |
| Access module | Subscription status for permission evaluation  | Eloquent relationship / service call                   |
| Audit module  | Billing lifecycle events                       | Domain events                                          |


## 4. Entities

### 4.1 Plans (`plans`)

Defines the catalog of available subscription tiers.

```php
Schema::create('plans', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('base_price', 10, 2);
    $table->string('currency', 3)->default('usd');
    $table->string('billing_cycle'); // monthly, yearly
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Enum `BillingCycle`**: `Monthly`, `Yearly`

### 4.2 Subscriptions (`subscriptions`)

Tracks a team's subscription state. One active subscription per team at a time.

```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
    $table->string('status'); // trialing, active, past_due, suspended, canceled, expired
    $table->string('billing_cycle');
    $table->timestamp('starts_at');
    $table->timestamp('renews_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamp('trial_ends_at')->nullable();
    $table->boolean('cancel_at_period_end')->default(false);
    $table->string('external_provider')->nullable();
    $table->string('external_subscription_id')->nullable();
    $table->string('external_customer_id')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'status']);
});
```

**Enum `SubscriptionStatus`**: `Trialing`, `Active`, `PastDue`, `Suspended`, `Canceled`, `Expired`

### 4.3 Tenant Features (`tenant_features`)

Per-tenant feature flags controlling access to capabilities. Source tracks why a feature is enabled.

```php
Schema::create('tenant_features', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('feature_key');
    $table->boolean('enabled')->default(true);
    $table->string('source'); // default_plan, manual_override, promo, beta_access
    $table->jsonb('limits_json')->nullable();
    $table->timestamps();

    $table->unique(['team_id', 'feature_key']);
});
```

**Enum `FeatureSource`**: `DefaultPlan`, `ManualOverride`, `Promo`, `BetaAccess`

### 4.4 Tenant Branding (`tenant_brandings`)

White-label customization per tenant.

```php
Schema::create('tenant_brandings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete()->unique();
    $table->string('logo_url')->nullable();
    $table->string('primary_color', 7)->nullable();
    $table->string('secondary_color', 7)->nullable();
    $table->string('display_name')->nullable();
    $table->text('email_signature')->nullable();
    $table->string('custom_domain')->nullable();
    $table->timestamps();
});
```

### 4.5 Usage Meters (`usage_meters`)

Catalog of all metered dimensions. Each meter defines how events are counted and whether they are billable.

```php
Schema::create('usage_meters', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('unit');
    $table->string('aggregation_type'); // sum, max, unique_count
    $table->boolean('is_billable')->default(true);
    $table->string('reset_period'); // monthly, daily
    $table->string('provider_meter_event_name')->nullable();
    $table->string('provider_meter_id')->nullable();
    $table->timestamps();
});
```

**Enum `AggregationType`**: `Sum`, `Max`, `UniqueCount`

**Enum `ResetPeriod`**: `Monthly`, `Daily`

### 4.6 Usage Events (`usage_events`)

Granular, immutable log of every billable action. Idempotent via unique `(team_id, event_key)`.

```php
Schema::create('usage_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
    $table->string('event_key');
    $table->unsignedBigInteger('quantity')->default(1);
    $table->jsonb('metadata_json')->nullable();
    $table->timestamp('occurred_at');
    $table->string('billing_period_key')->nullable();
    $table->timestamps();

    $table->unique(['team_id', 'event_key']);
    $table->index(['team_id', 'occurred_at']);
    $table->index(['usage_meter_id', 'occurred_at']);
});
```

### 4.7 Usage Daily Aggregates (`usage_daily_aggregates`)

Pre-computed daily roll-ups for dashboards and billing snapshots.

```php
Schema::create('usage_daily_aggregates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
    $table->date('day');
    $table->unsignedBigInteger('quantity_sum')->default(0);
    $table->unsignedBigInteger('quantity_max')->default(0);
    $table->timestamps();

    $table->unique(['team_id', 'usage_meter_id', 'day']);
});
```

### 4.8 Tenant Usage Counters (`tenant_usage_counters`)

Current-period running totals per meter per tenant. Updated by `AggregateUsageJob` and on-demand recalculation.

```php
Schema::create('tenant_usage_counters', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
    $table->date('period_start');
    $table->date('period_end');
    $table->unsignedBigInteger('consumed_value')->default(0);
    $table->unsignedBigInteger('included_value')->default(0);
    $table->unsignedBigInteger('overage_value')->default(0);
    $table->timestamp('last_calculated_at')->nullable();
    $table->timestamps();

    $table->unique(['team_id', 'usage_meter_id', 'period_start']);
});
```

### 4.9 Billing Rates (`billing_rates`)

Links plans to meters with pricing rules.

```php
Schema::create('billing_rates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
    $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('included_quantity')->default(0);
    $table->decimal('overage_unit_price', 10, 4)->default(0);
    $table->string('billing_model'); // included_only, metered, tiered, flat_plus_overage
    $table->jsonb('tiers_json')->nullable();
    $table->timestamps();
});
```

**Enum `BillingModel`**: `IncludedOnly`, `Metered`, `Tiered`, `FlatPlusOverage`

### 4.10 Invoice Snapshots (`invoice_snapshots`)

Point-in-time billing records for audit and dispute resolution.

```php
Schema::create('invoice_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
    $table->date('period_start');
    $table->date('period_end');
    $table->decimal('subtotal', 10, 2);
    $table->decimal('overage_total', 10, 2);
    $table->decimal('total', 10, 2);
    $table->string('currency', 3);
    $table->string('status'); // draft, finalized, invoiced, paid, disputed, void
    $table->jsonb('breakdown_json')->nullable();
    $table->timestamp('generated_at');
    $table->timestamps();
});
```

**Enum `InvoiceStatus`**: `Draft`, `Finalized`, `Invoiced`, `Paid`, `Disputed`, `Void`

### 4.11 Extend Existing Team (separate migration)

```php
Schema::table('teams', function (Blueprint $table) {
    $table->string('timezone')->nullable()->after('is_personal');
    $table->string('country', 2)->nullable()->after('timezone');
    $table->string('currency', 3)->default('usd')->after('country');
    $table->string('onboarding_state')->nullable()->after('currency');
});
```

### Extend Existing Team Model

Add relationships to `app/Models/Team.php`:

```php
public function subscription(): HasOne
{
    return $this->hasOne(\App\Domains\Tenancy\Models\Subscription::class)
        ->whereIn('status', ['trialing', 'active', 'past_due'])
        ->latest('starts_at');
}

public function plan(): HasOneThrough
{
    return $this->hasOneThrough(
        \App\Domains\Tenancy\Models\Plan::class,
        \App\Domains\Tenancy\Models\Subscription::class,
        'team_id',
        'id',
        'id',
        'plan_id',
    );
}

public function features(): HasMany
{
    return $this->hasMany(\App\Domains\Tenancy\Models\TenantFeature::class);
}

public function branding(): HasOne
{
    return $this->hasOne(\App\Domains\Tenancy\Models\TenantBranding::class);
}

public function usageCounters(): HasMany
{
    return $this->hasMany(\App\Domains\Tenancy\Models\TenantUsageCounter::class);
}

public function usageEvents(): HasMany
{
    return $this->hasMany(\App\Domains\Tenancy\Models\UsageEvent::class);
}
```

## 5. Services / Actions

### 5.1 `RecordUsageEvent`

**Path**: `app/Domains/Tenancy/Actions/RecordUsageEvent.php`

```php
public function execute(
    int $teamId,
    string $meterCode,
    int $quantity,
    string $eventKey,
    ?array $metadata = null,
    ?\DateTimeInterface $occurredAt = null,
): void
```

- Resolve `usage_meter_id` from `$meterCode` (cache lookup)
- Insert into `usage_events` with `insertOrIgnore` for idempotency on `(team_id, event_key)`
- Dispatch `UsageRecorded` domain event on successful insert
- Set `occurred_at` to `$occurredAt ?? now()`
- Set `billing_period_key` based on meter's `reset_period`

### 5.2 `CreateTenant`

**Path**: `app/Domains/Tenancy/Actions/CreateTenant.php`

```php
public function execute(
    string $name,
    User $owner,
    ?string $planCode = null,
): Team
```

- Create `Team` with `is_personal = false`
- Create `Membership` for `$owner` with `TeamRole::Owner`
- If `$planCode` provided, create `Subscription` with status `trialing` and 14-day trial
- Seed default `tenant_features` from plan configuration
- Create empty `TenantBranding` record
- Dispatch `TenantCreated` domain event
- Return the created `Team`

### 5.3 `ResolveTenantContext`

**Path**: `app/Domains/Tenancy/Actions/ResolveTenantContext.php`

```php
public function execute(User $user): Team
```

- Return `$user->currentTeam` (already set by middleware)
- Throw `TenantContextException` if no team is resolved

### 5.4 `RegisterUsageEvent`

**Path**: `app/Domains/Tenancy/Actions/RegisterUsageEvent.php`

Alias/wrapper around `RecordUsageEvent` intended for use by other modules. Accepts the same parameters and delegates internally. Exists to provide a stable public API for cross-domain usage event recording.

## 6. Jobs

### 6.1 `AggregateUsageJob`

- **Queue**: `billing`
- **Schedule**: Daily at 02:00 UTC via `routes/console.php`
- **Logic**:
  1. For each active team, for each meter, query `usage_events` since last aggregation
  2. Group by day, compute `SUM(quantity)` and `MAX(quantity)`
  3. Upsert into `usage_daily_aggregates`
  4. Recalculate `tenant_usage_counters` for the current billing period
  5. Compute `overage_value = max(0, consumed_value - included_value)` using plan's `billing_rates`
  6. If overage threshold exceeded, dispatch `UsageLimitExceeded` event

### 6.2 `GenerateInvoiceSnapshotJob`

- **Queue**: `billing`
- **Trigger**: Dispatched when Stripe `invoice.upcoming` webhook is received, or on-demand
- **Logic**:
  1. Load the team's current subscription and billing rates
  2. Sum usage counters for the billing period
  3. Calculate subtotal (base price), overage_total, and total
  4. Build `breakdown_json` with per-meter detail
  5. Insert `invoice_snapshots` with status `draft`

### 6.3 `ReportUsageToStripeJob`

- **Queue**: `billing`
- **Trigger**: Dispatched on `invoice.upcoming` webhook or by `AggregateUsageJob` for metered plans
- **Logic**:
  1. For each billable meter with `provider_meter_event_name` set
  2. Call `$team->reportMeterEvent($meterEventName, $quantity, $options)` via Cashier
  3. Log the report in `metadata_json` on the usage counter

## 7. Domain Events


| Event                  | Payload                                                           | Dispatched When                     |
| ---------------------- | ----------------------------------------------------------------- | ----------------------------------- |
| `TenantCreated`        | `Team $team, User $owner`                                         | New tenant is created               |
| `UsageRecorded`        | `int $teamId, string $meterCode, int $quantity, string $eventKey` | Usage event successfully inserted   |
| `SubscriptionUpdated`  | `Subscription $subscription, string $previousStatus`              | Subscription status changes         |
| `SubscriptionCanceled` | `Subscription $subscription`                                      | Subscription is canceled            |
| `UsageLimitExceeded`   | `int $teamId, string $meterCode, int $consumed, int $included`    | Overage detected during aggregation |


## 8. Broadcasting Events

### `UsageUpdatedBroadcast`

- **Channel**: `private-accounts.{teamId}`
- **Trigger**: When `AggregateUsageJob` updates a usage counter with significant change (>5% delta or overage threshold crossed)
- **Payload**:
  ```json
  {
      "meter_code": "ai_tokens_in",
      "consumed": 8500,
      "included": 10000,
      "overage": 0,
      "period_start": "2026-04-01",
      "period_end": "2026-04-30"
  }
  ```

## 9. APIs / Endpoints

Endpoints are registered in the `TenancyServiceProvider` or existing route files. All tenant-scoped routes are prefixed with `/{current_team}`.


| Method | URI                                 | Controller                    | Purpose                                           |
| ------ | ----------------------------------- | ----------------------------- | ------------------------------------------------- |
| GET    | `/{current_team}/billing`           | `BillingController@index`     | Billing dashboard (subscription, usage, invoices) |
| GET    | `/{current_team}/billing/usage`     | `BillingController@usage`     | Detailed usage breakdown                          |
| POST   | `/{current_team}/billing/subscribe` | `BillingController@subscribe` | Create/change subscription                        |
| POST   | `/{current_team}/billing/cancel`    | `BillingController@cancel`    | Cancel subscription                               |
| GET    | `/{current_team}/billing/invoices`  | `BillingController@invoices`  | Invoice history                                   |
| GET    | `/{current_team}/branding`          | `BrandingController@edit`     | Branding settings                                 |
| PUT    | `/{current_team}/branding`          | `BrandingController@update`   | Update branding                                   |
| POST   | `/stripe/webhook`                   | Cashier webhook handler       | Stripe webhook (CSRF bypassed)                    |


## 10. Business Rules

1. Every data entity in the system MUST have a `team_id` foreign key (enforced by `BelongsToTenant` trait).
2. Usage events are idempotent — duplicate `event_key` per team is silently ignored via `insertOrIgnore` on the unique constraint.
3. Usage aggregation does not replace granular events — both `usage_events` (immutable log) and `usage_daily_aggregates` (roll-ups) coexist.
4. Subscription status determines feature availability: only `trialing`, `active`, or `past_due` statuses grant access to operational features.
5. A `suspended` subscription blocks operational modules (incidents, AI, automation) but allows billing access so the tenant can resolve payment issues.
6. Usage counters are eventually consistent — updated by the daily `AggregateUsageJob` and can be recalculated on-demand.
7. Cashier Stripe webhook at `/stripe/webhook` must bypass CSRF verification (configured in `bootstrap/app.php`) and verify the Stripe signature.
8. The `Billable` trait is added to `Team` (not `User`) because the Team is the billing entity.
9. Only one active subscription per team at a time. Creating a new subscription cancels/expires the previous one.
10. Trial period defaults to 14 days. Trial expiration triggers `SubscriptionUpdated` event with status change to `expired` if no payment method is on file.

## 11. Integration with Other Modules


| Module            | Integration Point                                                                                 |
| ----------------- | ------------------------------------------------------------------------------------------------- |
| **Access**        | Subscription status feeds into permission evaluation — `suspended` blocks operational permissions |
| **Integrations**  | Third-party provider sync emits `api_requests` usage events                                       |
| **Ingestion**     | Raw event ingestion emits `api_requests` usage events                                             |
| **AI**            | AI SDK events emit `ai_tokens_in`, `ai_tokens_out`, `ai_calls` usage events                       |
| **Assets**        | Daily snapshot job emits `monitored_assets`, `active_cameras` usage events                        |
| **Notifications** | Notification delivery emits `outbound_notifications` usage events                                 |
| **Automation**    | Automation execution emits `incident_workflows` usage events                                      |
| **Analytics**     | Report export emits `generated_reports` usage events                                              |
| **Audit**         | All billing lifecycle events are logged                                                           |
| **Tenant Config** | Reads tenant features for module-level configuration                                              |


## 12. Usage Metering

This module owns the entire usage metering pipeline. Self-metered items:


| Meter Code     | When Recorded                                                  |
| -------------- | -------------------------------------------------------------- |
| `active_users` | Daily snapshot of unique users who authenticated in the period |


All other meters are recorded by their respective source modules via `RecordUsageEvent`.

## 13. Technical Considerations

### Cashier Stripe Integration

- **Install**: `composer require laravel/cashier`
- **Add `Billable` trait** to `Team` model (not `User`)
- **Webhook route**: Register `/stripe/webhook` and bypass CSRF:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'stripe/*',
    ]);
})
```

- **Metered subscription creation**:

```php
$team->newSubscription('default', [])
    ->meteredPrice($priceId)
    ->create();
```

- **Report usage to Stripe**:

```php
$team->reportMeterEvent($meterEventName, $quantity, $options);
```

### Performance

- `usage_events` will grow rapidly. Partition by `occurred_at` month if table exceeds 50M rows.
- `usage_daily_aggregates` keeps dashboard queries fast — never query raw events for UI.
- Cache `usage_meters` lookup (code → id) in Valkey with 1-hour TTL.
- Cache tenant feature flags in Valkey with 5-minute TTL, invalidated on update.

### Idempotency

- `RecordUsageEvent` uses `insertOrIgnore` leveraging the `unique(team_id, event_key)` constraint.
- `AggregateUsageJob` uses `upsert` on the unique constraint `(team_id, usage_meter_id, day)`.
- `GenerateInvoiceSnapshotJob` checks for existing snapshot for the same period before creating.

### Stripe Webhook Events Handled


| Stripe Event                    | Action                                                             |
| ------------------------------- | ------------------------------------------------------------------ |
| `customer.subscription.created` | Create/update local `Subscription` record                          |
| `customer.subscription.updated` | Update status, dispatch `SubscriptionUpdated`                      |
| `customer.subscription.deleted` | Set status to `canceled`, dispatch `SubscriptionCanceled`          |
| `invoice.upcoming`              | Dispatch `GenerateInvoiceSnapshotJob` and `ReportUsageToStripeJob` |
| `invoice.paid`                  | Update `invoice_snapshots` status to `paid`                        |
| `invoice.payment_failed`        | Set subscription status to `past_due`                              |


## 14. Test Scenarios


| Test Name                                                 | Description                                                                                               |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `test_it_creates_tenant_with_default_plan_and_features`   | `CreateTenant` with a plan code seeds subscription (trialing), default features, and empty branding       |
| `test_it_records_usage_event_idempotently`                | `RecordUsageEvent` inserts a new event and returns without error                                          |
| `test_duplicate_usage_event_key_is_ignored`               | Calling `RecordUsageEvent` with the same `(teamId, eventKey)` twice does not create a second row          |
| `test_daily_aggregation_sums_usage_events_correctly`      | `AggregateUsageJob` produces correct `quantity_sum` and `quantity_max` in `usage_daily_aggregates`        |
| `test_usage_counter_calculates_overage`                   | When `consumed_value > included_value`, `overage_value` equals the difference                             |
| `test_suspended_subscription_blocks_operational_features` | A team with `suspended` subscription cannot access operational modules but can access billing             |
| `test_stripe_webhook_processes_invoice_upcoming`          | Simulated `invoice.upcoming` webhook dispatches `GenerateInvoiceSnapshotJob` and `ReportUsageToStripeJob` |
| `test_tenant_context_resolves_from_authenticated_user`    | `ResolveTenantContext` returns the user's current team                                                    |
| `test_create_tenant_dispatches_tenant_created_event`      | `TenantCreated` event is dispatched with correct payload                                                  |
| `test_usage_limit_exceeded_event_dispatched_on_overage`   | `UsageLimitExceeded` fires when aggregation detects overage                                               |
| `test_only_one_active_subscription_per_team`              | Creating a new subscription expires the previous one                                                      |
| `test_trial_defaults_to_14_days`                          | Subscription created via `CreateTenant` has `trial_ends_at` set to 14 days from now                       |
| `test_billing_rates_determine_included_quantity`          | Usage counter `included_value` is set from the plan's `billing_rates.included_quantity`                   |
| `test_invoice_snapshot_contains_per_meter_breakdown`      | `breakdown_json` includes entries for each metered dimension                                              |


