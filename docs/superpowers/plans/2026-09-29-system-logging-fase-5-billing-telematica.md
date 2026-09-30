# Logging narrativo — Fase 5: Billing/Tenancy + Assets/telemática — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el cobro y la telemática dejen su línea narrativa en cada rama y en cada número. En billing: cada uso medido o ignorado por duplicado, los términos con la fuente de cada campo, el escalón de precio, la línea de tracto-día (mínimo, tope, días extra), el resto de líneas de la factura (recargo de emergencias, IA, Twilio con FX y margen, medidores del plan), la factura con subtotal, extra y total, la agregación con el primer cruce del incluido, la estimación del mes, el cierre diario, los días no cobrados con su motivo, el recargo por emergencias en unidades no vigiladas, el bloqueo del tenant y el costo real de Twilio. Cada importe se registra término a término, de modo que se rehaga **al centavo** contra la factura persistida. En telemática: el resumen de cada ciclo del feed (fallo, pausa, circuito, páginas), los puntos descartados por motivo, los feeds despachados por tick, la evaluación de movimiento fuera de horario, los barridos de offline y de parada no autorizada con sus umbrales, la conectividad, la sincronización de activos, los cambios de vigilancia y las purgas con su corte.

**Architecture:** Sobre la fundación de la fase 1 (PR #150), el tramo de entrada de la fase 2 (PR #151), la IA/decisiones de la fase 3 (PR #152) y el tramo de salida de la fase 4: `App\Support\SystemLog`, `App\Support\LoggableCode`, `Tests\Concerns\AssertsSystemLog` y el catálogo `docs/SAM/logging.md`, que `LoggingConventionsTest` exige que contenga cada código literal. No hay componentes nuevos: sólo métodos públicos de solo lectura (`explain`, `record`, `outcome`, `evaluate`) a los que delegan los originales. Hay tres patrones:
- **Líneas de cálculo** (`billing.terms.resolved`, `billing.tier.selected`, `billing.asset_day.calculated`, `billing.invoice_line.calculated`, `billing.estimate.calculated`, `billing.overage.computed`, `assets.after_hours.evaluated`, `telematics.points.dropped`…) se emiten donde se calcula el número. Afirman un cálculo sobre filas que ya existían: es cierto al emitirse aunque la transacción del llamador revierta.
- **Líneas de hecho persistido** (`billing.usage.recorded`, `billing.invoice.generated`, `billing.invoice.status_changed`, `billing.messaging_charge.finalized`, `assets.monitoring.changed`) se emiten con `DB::afterCommit(fn () => SystemLog::…)`, registrado justo después de la escritura. Laravel ejecuta el callback en el acto si no hay transacción abierta, tras el commit de la más externa si la hay, y lo descarta si revierte. Es necesario porque `RecordUsageEvent` se llama dentro de transacciones ajenas (`CreateIncidentFromEvent::createOrLink`, `ExecuteAction`, la ingesta), y porque `SetAssetMonitoring` o la factura pueden quedar dentro de una en el futuro sin que nadie revise sus logs. Los valores se capturan por valor en el closure; nunca se releen en el callback.
- **Resúmenes de ciclo y de barrido** (`telematics.cycle.completed` / `failed`, `telematics.feeds.dispatched`, `assets.offline_sweep.completed`, `billing.daily_close.completed`, `billing.aggregate.fanned_out`…) se emiten al terminar el recorrido, con conteos por motivo. Los recorridos de plataforma (varios tenants) sólo llevan conteos; las líneas por tenant se emiten dentro de `TenantContext::for` de ese tenant.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-09-28-system-logging-design.md` (§3 esquema, §4 componentes, §5 "Fase 5", §7 volumen).

## Global Constraints

- Código `dominio.etapa.resultado` (regex `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`), en inglés snake_case. `reason` obligatorio en snake_case cuando `outcome` ≠ `ok`. Nivel: ok/skipped → info, degraded → warning, failed → error.
- **Se reutilizan los códigos existentes**, ampliándolos en vez de duplicarlos:
  - `telematics.cycle.completed` y `telematics.cycle.failed` ganan campos; `telematics.cycle.failed` pasa de un único reason `provider_error` a `rate_limited` | `provider_unavailable` | `cursor_rejected` | `unauthorized` | `provider_error` (este último sólo para los fallos sin clase propia). Ningún test existente afirma el reason anterior.
  - `telematics.backfill.completed` gana `dropped_count_by_reason` y la clase de salida.
  - `billing.messaging_charge.reconcile_failed` gana `calc` con el reintento (`check_attempts`, `delay_minutes`).
  - `billing.messaging_usage.not_metered`, `billing.messaging_charge.record_failed`, `ingestion.usage.recorded` / `not_metered` y `context.usage.recorded` / `not_metered` no cambian. `billing.usage.recorded` es el **libro mayor** (una línea por fila realmente insertada en `usage_events`, para todos los meters); las líneas `ingestion.usage.*` y `context.usage.*` siguen diciendo **por qué** se pidió medir. El catálogo lo explica con una frase en la sección Billing.
  - `drivers.sync.external_id_conflict` es el modelo de `assets.sync.external_id_conflict` (mismo reason `owned_by_other_tenant`, mismos campos).
- **Una línea nunca afirma algo que puede ser falso al emitirse.**
  - "Registrado" sale del resultado real del insert: `billing.usage.recorded` sólo si `insertOrIgnore` devolvió `> 0`; con `0` es `billing.usage.duplicate_ignored`. Nunca se deduce de que se llamó a la acción.
  - Todo hecho persistido (uso registrado, factura generada o cambiada de estado, cargo finalizado, vigilancia cambiada) va por `DB::afterCommit(...)`.
  - Nada anuncia el futuro: se registra lo pedido (`job_requested`, `chain_requested`, `backfill_requested`, `limit_event_dispatched`), nunca "se cobrará" ni "se facturará".
  - Los conteos llevan el alcance en el nombre: `assets_monitored_count` (del tenant, antes del cambio), `teams_closed_count` (tenants del cierre), `readings_reported_count` frente a `assets_matched_count`, `jobs_dispatched_count`, `charges_due_count` frente a `charges_processed_count`.
  - `outcome` refleja la realidad: un cierre diario con algún tenant fallido es `degraded` (`tenant_failures`), no `ok`; un cargo finalizado cuyo uso no se pudo medir es `degraded` (`not_metered`).
- **Dinero exacto y recomputable.**
  - El código calcula con `float` y `round(…, 2)` (líneas de `AssetDayPricing`, `breakdown_json`) y persiste con casts `decimal:2` (`InvoiceSnapshot.subtotal`, `overage_total`, `total`). Los logs llevan **exactamente** esos valores: los términos de `calc` son los mismos `float` que quedan en `breakdown_json` (misma variable, nunca recalculados aparte), y los totales persistidos en `result` son el string decimal del modelo creado (`$snapshot->total` → `"322.00"`). Nunca `(float)` sobre un decimal persistido ni un `round` distinto del del código.
  - `price_micros` y `messaging_micros` son enteros; el precio de Twilio se registra como el string que devolvió el proveedor (`"-0.00790"`).
  - Cada línea de cálculo lleva su fórmula como string constante (`formula`) y **todos** sus términos. Los tests rehacen cada importe con esos términos, lo formatean con `number_format($x, 2, '.', '')` y lo comparan con el registrado **y** con el persistido (`breakdown_json` y columnas de `invoice_snapshots`).
  - Trampa de redondeo que el `calc` debe reflejar tal cual: `assetDayLine` calcula `amount` con la tarifa diaria **sin redondear** (`unit_price / days_in_period`) pero guarda `daily_rate` redondeada a 6 decimales, y la línea de emergencias parte de esa `daily_rate` **redondeada**. La fórmula de cada una lo dice (`round(billable_days * unit_price / days_in_period, 2)` frente a `round(asset_days * round(daily_rate * (1 + surcharge_percent / 100), 6), 2)`), y el test usa esa, no la otra.
- **Claves que el redactor no enmascara.** `RedactSensitiveLogData` enmascara toda clave con un segmento `name`, `address`, `token`, `body`, `payload`, `raw`… salvo que el último segmento sea técnico (`id`, `ids`, `count`, `type`, `status`, `class`, `mode`, `variant`, `present`, `length`, `bytes`, `source`, `strategy`, `key`). Por eso:
  - las líneas de la factura se registran **sin** `meter_name` (es texto en español y la clave lleva `name`); basta `meter_code` (que está en la allowlist exacta);
  - mapas por motivo con sufijo `_count` (`unknown_vehicle_count`, `tenant_blocked_count`);
  - `reason_present` en vez del `reason` libre de `SetAssetMonitoring`; `actor_user_id` en vez de `actor_email`;
  - `event_key` sí se registra (último segmento `key`, y no lleva calificador secreto): su valor es una clave idempotente con ids propios del tenant (`monitored_asset_day:{team}:{asset}:{fecha}`, `twilio_charge:{sid}`).
- **Nunca en un log:** CLABE, beneficiario o banco de `billing.transfer`; nombre, clave o ruta del comprobante de pago (`original_filename`, `object_key`, `receiptKey`) ni `payment_note`; contenido de una factura fuera de sus números; `metadata` de un `UsageEvent`; emails, nombres de personas, nombre o placa de un activo (`asset.name`, `asset.code`), `last_formatted_location`, `subject`/`body_preview` del aviso de emergencia no vigilada; coordenadas (solo `location_source`, edades en segundos y distancias en metros; ninguna decisión de esta fase se explica con la coordenada en sí); `last_error`/`last_error_message` del cursor o de la integración; la zona horaria del perfil de horario (texto del tenant); `getMessage()` de cualquier excepción. Texto del proveedor (códigos de estado, `price_unit`, `health_status`, ids externos) sólo vía `LoggableCode::guard()`. Los enums se registran con `->value`.
- **`error:` solo en excepciones sin texto de terceros en el mensaje.** `ProviderRequestFailed` y sus hijas llevan mensajes propios (`Provider rate limit hit; retry after …s.`), así que siguen como `error: $failure`. `TwilioException` y las excepciones del proveedor de sync van como `error_class` (`class_basename`) más su código numérico.
- **Nunca el id de otro tenant.**
  - Los recorridos de plataforma (`AggregateUsageJob` sin team, `GenerateMonthlyInvoicesJob`, `RecordAssetUsageMeters`, `DispatchTelematicsFeedsJob`, `PollAllDeviceConnectivityJob`, `DetectOfflineAssetsJob`, `ReconcileMessagingChargesJob`, las purgas) registran **solo conteos**, nunca listas de `team_id`.
  - Las líneas por tenant se emiten dentro de `TenantContext::for($teamId, …)` del propio tenant (así `extra.tenant_id` es el suyo) y llevan su `team_id` en `input`, porque un callback de `afterCommit` puede correr fuera de ese contexto.
  - `SetAssetMonitoring::executeMany` con un activo de otro tenant registra `calc: ['team_matches' => false]` sin `asset_id`.
  - `assets.sync.external_id_conflict` registra el `team_id` que **pidió** el id (el propio), `provider_id` y el `external_id` guardado; nunca el tenant dueño.
- **Volumen (telemática cada 5 s por tenant).** Resúmenes por ciclo en `info` y en el canal `telematics` (`channel: 'telematics'`); el detalle en `debug`. `AutomaticSystemLog` sólo envía al canal `telematics` las líneas automáticas de los jobs de esa cola: las narrativas pasan `channel: 'telematics'` explícito. Van a `debug`, y así se listan en el catálogo:
  - `telematics.points.dropped` con reason `already_stored`, `unchanged_value` y `unknown_vehicle` (normales en cada ciclo);
  - `telematics.feeds.dispatched` con `dispatched_count = 0` y el skipped `no_active_integrations`;
  - `assets.after_hours.evaluated` skipped con `within_operating_hours`, `no_schedule_profile`, `no_moving_positions`, `not_moving`, `stale_position` y `cooldown_active`;
  - `assets.unauthorized_stop_sweep.completed` con `candidates_count = 0`;
  - `assets.offline.skipped` con `already_raised`;
  - `billing.monitored_day.skipped` cuando lo pide el cierre diario (`already_recorded`, `not_monitored`, `inactive`);
  - `billing.overage.computed` con `overage = 0` y sin primer cruce;
  - `billing.messaging_charge.reconciled` con `branch = rescheduled`;
  - `ai.usage.not_metered` con reason `no_conversation_link` y `zero_tokens`.
  `telematics.circuit.opened` va al canal por defecto (es un cambio de estado de la integración, poco frecuente, y tiene que verse en `system.json`).
- **Cálculos recomputables:** cada `calc` trae todos los términos, los umbrales y los límites. El test de cada cálculo rehace el resultado con esos términos y lo compara con el resultado registrado **y** con lo persistido (`breakdown_json`, `invoice_snapshots`, `tenant_usage_counters`, `telematics_feed_cursors.paused_until`, `messaging_charges.price_micros` / `next_check_at`).
- Cada código nuevo tiene:
  - su fila en `docs/SAM/logging.md` (código | outcome | reason posibles | campos clave);
  - al menos un test de feature que recorre la rama real (DB real, factories, seeders y fixtures de los tests existentes listados en cada tarea) y la afirma con `assertSystemLogged(code, fn (array $c) => …)`, comprobando `reason` y los campos de `calc` clave.
- Cada archivo de test nuevo o tocado añade el trait `AssertsSystemLog` y llama `assertNoSensitiveDataLogged()` en al menos un test.
- **No cambiar comportamiento.** Solo se añaden líneas y las refactorizaciones mínimas que se nombran en cada tarea:
  - se conservan las firmas públicas; se extienden solo con parámetros opcionales o con métodos públicos nuevos de solo lectura (`explain`, `explainUnitPriceFor`, `record`, `outcome`, `evaluate`), y el método original delega en el nuevo. La única firma que cambia es el retorno de `App\Contracts\AssetSyncHandler::syncFromIntegration` (`void` → `?string`, Task 7): el único llamador ignoraba el retorno y los mocks que devuelven `null` siguen valiendo;
  - los métodos privados pueden cambiar su tipo de retorno para sacar los términos del cálculo;
  - los mensajes de excepción, los textos de consola, las entradas de auditoría y lo que se guarda en la DB no cambian;
  - los tests existentes siguen verdes sin tocarlos, salvo para añadir el trait y aserciones nuevas.
- Sin directorios nuevos en `app/`, sin cambios en `composer.json`/`package.json`. Commits `type: subject en minúsculas` sin trailers. PHPUnit, sin Pest. No mockear la DB. Nunca `git stash`.
- El worktree `.claude/worktrees/logging-fase-5` no tiene `.env` y no se crea: los tests se corren con `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact …`. Los warnings de dotenv son esperados; 0 fallos = verde.

## Review Focus

1. **Registrado = insertado.** `billing.usage.recorded` sale sólo del `insertOrIgnore > 0` y por `DB::afterCommit`; el duplicado es `billing.usage.duplicate_ignored`. Tests de rollback: `DB::transaction(function () { app(RecordUsageEvent::class)->execute(…); throw new RuntimeException('boom'); })` afirma `UsageEvent::withoutGlobalScopes()->count() === 0` y `assertSystemNotLogged('billing.usage.recorded')` (Task 1). Lo mismo para `assets.monitoring.changed` con `SetAssetMonitoring` dentro de una transacción que revierte (Task 4).
2. **Factura al centavo:** el test rehace desde el log `subtotal = round(Σ subtotal_terms, 2)`, `overage_total = round(Σ overage_terms, 2)` y `total = round(subtotal + overage_total, 2)`, y cada término con la fórmula de su línea, y los compara con `InvoiceSnapshot::subtotal/overage_total/total` y con `breakdown_json` (Task 2). La estimación rehace `projected_asset_days = asset_days + monitored_now × remaining_days` y `total_projected` (Task 3). El costo de Twilio rehace `price_micros = round(abs(provider_price) × 1 000 000)` o el estimado por unidad (Task 5).
3. **Silencios que dejan de serlo:**
   - un meter que falta: `consumed()` de la factura y la estimación devolvían `0` sin rastro → `billing.meter.missing` (`degraded`);
   - la factura que ya existía: `return` mudo → `billing.invoice.already_exists`;
   - la espera del lock agotada: excepción sin contexto → `billing.invoice.lock_timeout`;
   - un tracto-día no cobrado (unidad no vigilada, tenant bloqueado, muestra antigua, ya cobrado) → `billing.monitored_day.skipped` con motivo;
   - un cargo de Twilio finalizado pero no medido → `billing.messaging_charge.finalized` `degraded` / `not_metered`;
   - un feed en pausa, el tope de páginas y el circuito abierto → `telematics.cycle.paused`, `max_pages_hit`, `telematics.circuit.opened`;
   - puntos descartados por un vehículo sin activo → `telematics.points.dropped`.
   Lo fijan los Tasks 1, 2, 3, 4, 5 y 6.
4. **Sin datos prohibidos:** ningún test encuentra en `json_encode($this->systemLogEntries())` la CLABE configurada (`config()->set('billing.transfer.clabe', '012180001234567891')`), el nombre de archivo del comprobante, `payment_note`, el `name` o `code` del activo, el `meter_name` de una línea, el email del actor ni una coordenada del fixture (`19.43`, `-99.13`). Lo fijan los Tasks 2, 3, 4, 6 y 7.
5. **Aislamiento:** ninguna línea lleva el id de otro tenant. Hay tests que lo afirman en `EstimatePeriodChargesTest::test_the_estimate_only_reads_the_tenants_own_usage_and_fleet`, `RecordMonitoredAssetDayTest::test_one_tenants_close_never_writes_into_another`, `AssetMonitoringTest::test_switching_monitoring_never_touches_another_tenant`, `FollowVehicleStatsFeedJobTest::test_a_cycle_never_touches_another_tenants_assets`, `DetectOfflineAssetsJobTest::test_each_tenant_is_inspected_with_its_own_thresholds_and_events`, `ReconcileMessagingChargesJobTest::test_reconciler_writes_each_charge_only_into_its_own_tenant` y `AssetSyncHandlerServiceTest::test_it_swallows_a_cross_tenant_external_id_collision`.

---

## Mapa de archivos

| Archivo | Tarea |
|---|---|
| `app/Domains/Tenancy/Actions/RecordUsageEvent.php`, `app/Domains/Tenancy/Listeners/ChargeUnmonitoredEmergency.php`, `app/Infrastructure/AI/Listeners/AIUsageListener.php` | 1 |
| `app/Domains/Tenancy/Data/BillingTermsData.php`, `Actions/ResolveBillingTerms.php`, `Actions/ResolveAssetLimit.php`, `Jobs/GenerateInvoiceSnapshotJob.php` | 2 |
| `app/Domains/Tenancy/Jobs/AggregateUsageJob.php`, `Jobs/GenerateMonthlyInvoicesJob.php`, `Actions/EstimatePeriodCharges.php`, `app/Http/Controllers/Admin/TenantInvoiceController.php` | 3 |
| `app/Domains/Assets/Actions/RecordMonitoredAssetDay.php`, `Commands/RecordAssetUsageMeters.php`, `Actions/SetAssetMonitoring.php` | 4 |
| `app/Domains/Notifications/Actions/FinalizeMessagingCharge.php`, `Jobs/ReconcileMessagingChargesJob.php` | 5 |
| `app/Domains/Assets/Data/VehicleStatsIngestResult.php`, `Actions/IngestVehicleStatsPage.php`, `Jobs/FollowVehicleStatsFeedJob.php`, `Jobs/DispatchTelematicsFeedsJob.php`, `Jobs/BackfillVehicleStatsJob.php`, `Actions/RaiseAfterHoursMovement.php` | 6 |
| `app/Domains/Assets/Jobs/DetectOfflineAssetsJob.php`, `Jobs/DetectUnauthorizedStopJob.php`, `Jobs/PollAssetConnectivityJob.php`, `Jobs/PollAllDeviceConnectivityJob.php`, `Exceptions/AssetExternalReferenceConflictException.php`, `Services/AssetSyncHandlerService.php`, `Actions/SyncAssetFromIntegration.php`, `Jobs/SyncAssetsFromProviderJob.php`, `Jobs/PurgeOldAssetLocationsJob.php`, `Jobs/PurgeOldAssetTelemetryJob.php`, `app/Contracts/AssetSyncHandler.php`, `app/Contracts/NullImplementations/NullAssetSyncHandler.php`, `app/Domains/Integrations/Actions/SyncIntegration.php` | 7 |
| `docs/SAM/logging.md` | todas (secciones Billing, Telemática y una nueva `### Activos (assets)`; párrafo "Líneas en debug") |
| Tests: métodos nuevos en los tests existentes del área (listados en cada tarea), que ya traen los fixtures | todas |

---

### Task 1: Uso medido — libro mayor, recargo de emergencias y tokens de IA

**Files:**
- Modify: `app/Domains/Tenancy/Actions/RecordUsageEvent.php`
- Modify: `app/Domains/Tenancy/Listeners/ChargeUnmonitoredEmergency.php`
- Modify: `app/Infrastructure/AI/Listeners/AIUsageListener.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Tenancy/{RecordUsageEventTest,RegisterUsageEventTest}.php`, `tests/Feature/Domains/Normalization/UnmonitoredAssetEmergencyTest.php`, `tests/Feature/Domains/AI/AiUsageMeteringTest.php`

**`RecordUsageEvent` — refactor mínimo.** Nuevo `public function record(int $teamId, string $meterCode, int $quantity, string $eventKey, ?array $metadata = null, ?DateTimeInterface $occurredAt = null): bool` con el cuerpo actual; devuelve `$inserted > 0` (lo que devuelve el closure de `TenantContext::for`). `execute()` conserva su firma `void` y pasa a `$this->record(...)`. Así `RegisterUsageEventTest`, que mockea `execute`, no cambia.

| Rama | Llamada |
|---|---|
| `resolveMeter` lanza `ModelNotFoundException` | se envuelve en `try/catch` sólo esa llamada: `SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: ['team_id' => $teamId, 'meter_code' => $meterCode, 'event_key' => $eventKey, 'stage' => 'record_usage'])` y `throw $e;` (misma excepción, mismo comportamiento) |
| `$inserted > 0` | tras `UsageRecorded::dispatch`: `DB::afterCommit(fn () => SystemLog::ok('billing.usage.recorded', input: ['team_id' => $teamId, 'meter_code' => $meterCode, 'event_key' => $eventKey], calc: ['quantity' => $quantity, 'reset_period' => $meter->reset_period?->value, 'occurred_at' => $occurredAtIso, 'billing_period_key' => $billingPeriodKey], result: ['recorded' => true]))` |
| `$inserted === 0` | `SystemLog::skipped('billing.usage.duplicate_ignored', reason: 'event_key_exists', input: [...mismo input], calc: ['quantity' => $quantity, 'billing_period_key' => $billingPeriodKey])`, directo: que este insert no escribió nada es cierto aunque la transacción revierta |

- `$occurredAtIso = CarbonImmutable::instance($occurredAt)->toIso8601String()`, capturado antes del closure.
- Nunca `$metadata`: puede traer `provider_sid`, `asset_id` o lo que el llamador ponga; el `event_key` ya identifica el uso.
- `billing_period_key` se rehace en el test: `Y-m` para `ResetPeriod::Monthly` y el default, `Y-m-d` para `Daily`.

**`ChargeUnmonitoredEmergency::handle`** (job de cola, sin transacción; todo dentro de `TenantContext::for($teamId, …)` salvo la primera rama):

| Rama | Llamada |
|---|---|
| `$assetId === null` | `SystemLog::skipped('billing.emergency_surcharge.skipped', reason: 'no_asset', input: ['team_id' => $teamId, 'normalized_event_id' => $normalized->id])` |
| tras `record(...)` (sustituye a `execute`) = `true` | `SystemLog::ok('billing.emergency_surcharge.charged', input: ['team_id' => $teamId, 'asset_id' => $assetId, 'normalized_event_id' => $normalized->id], calc: ['local_date' => $localDate, 'occurred_at_source' => $normalized->occurred_at !== null ? 'event' : 'now', 'surcharge_percent' => AssetDayPricing::unmonitoredEmergencySurchargePercent(), 'meter_code' => AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE], result: ['event_key' => $eventKey, 'recorded' => true])` |
| `record(...)` = `false` | `SystemLog::skipped('billing.emergency_surcharge.skipped', reason: 'already_charged_today', input: [...], calc: ['local_date' => $localDate], result: ['event_key' => $eventKey])` |
| `$recipients === []` | `SystemLog::skipped('billing.emergency_surcharge.notified', reason: 'no_supervisors', input: ['team_id' => $teamId, 'asset_id' => $assetId])` |
| tras `sendNotification->execute` | `SystemLog::ok('billing.emergency_surcharge.notified', input: [...], result: ['notification_id' => $notification?->id, 'recipients_count' => count($recipients)])`. `$notification` es el retorno que hoy se descarta. |

- `$eventKey` se extrae a una variable (misma cadena `"unmonitored_emergency:{$teamId}:{$assetId}:{$localDate}"`).
- El importe del recargo **no** se registra aquí: la tarifa diaria se conoce al cerrar la factura (Task 2, `billing.invoice_line.calculated` con `billing_model = asset_day_surcharge`). El catálogo lo dice.
- Nunca `$assetName`, `subject` ni `bodyPreview`.

**`AIUsageListener::handle`.** `input` común: `['team_id' => $link?->team_id, 'invocation_id' => $invocationId]` (el `team_id` del link es el del propio uso).

| Rama | Llamada |
|---|---|
| `$link === null` | `SystemLog::skipped('ai.usage.not_metered', reason: 'no_conversation_link', input: ['invocation_id' => $event->invocationId], calc: ['conversation_id_present' => ($event->response->conversationId ?? null) !== null], debug: true)`. Es el caso normal de los wrappers propios: persisten el link después de la llamada y miden `ai_calls` por su cuenta (docblock). |
| por dirección (`in`/`out`) con tokens `<= 0` | `… reason: 'zero_tokens', calc: ['direction' => 'in'|'out'], debug: true` |
| por dirección con meter ausente (`! UsageMeter::where(...)->exists()`) | `SystemLog::degraded('ai.usage.not_metered', reason: 'meter_missing', input: …, calc: ['direction' => …, 'meter_code' => 'ai_tokens_in'|'ai_tokens_out', 'tokens' => (int) …])`. Es un hueco de cobro. |
| medido | nada propio: lo narra `billing.usage.recorded` / `duplicate_ignored`. |

Para separar las ramas, cada `if` se parte en dos (`$tokensIn > 0` y `$meterInExists`), con el mismo orden de evaluación que hoy.

- [ ] **Step 1: Write the failing tests**
  - `RecordUsageEventTest`:
    - `test_it_records_usage_event_idempotently` → `billing.usage.recorded` una vez con `event_key`, `quantity` y `billing_period_key === now()->format('Y-m')`; la segunda llamada da `billing.usage.duplicate_ignored` / `event_key_exists`;
    - `test_it_sets_billing_period_key_based_on_reset_period` → con el meter diario, `calc.billing_period_key === $occurredAt->format('Y-m-d')` y `reset_period === 'daily'`;
    - test nuevo: meter inexistente → `expectException(ModelNotFoundException::class)` y `billing.meter.missing` con `stage === 'record_usage'`;
    - **rollback** (test nuevo): `DB::transaction` que llama `execute` y lanza → `UsageEvent::withoutGlobalScopes()->count() === 0`, `assertSystemNotLogged('billing.usage.recorded')`;
    - test nuevo: `record()` devuelve `true` y luego `false` con la misma clave.
  - `RegisterUsageEventTest`: sin cambios de aserción; sólo se comprueba que sigue verde (mockea `execute`).
  - `UnmonitoredAssetEmergencyTest`:
    - `test_the_extra_day_is_charged_once_per_unit_and_day_and_the_admin_is_told` → un `billing.emergency_surcharge.charged` con `surcharge_percent === 10.0` y `local_date`, un `billing.emergency_surcharge.skipped` / `already_charged_today` en la segunda emergencia y `billing.emergency_surcharge.notified` ok; el JSON no contiene el `name` ni el `code` del activo;
    - `test_charging_an_emergency_never_touches_another_tenant` → ninguna entrada de `billing.*` lleva el id del otro team como `team_id`.
  - `AiUsageMeteringTest` (con `fakeAgentPrompted`):
    - `test_usage_listener_records_tokens_via_conversation_link` → dos `billing.usage.recorded` (`ai_tokens_in`, `ai_tokens_out`);
    - `test_usage_listener_no_ops_when_no_link_registered` → `ai.usage.not_metered` / `no_conversation_link` con `level === 'debug'`;
    - `test_usage_listener_idempotent_on_duplicate_event` → `billing.usage.duplicate_ignored` en la segunda;
    - test nuevo: borrar el meter `ai_tokens_out` → `ai.usage.not_metered` / `meter_missing` con `direction === 'out'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact tests/Feature/Domains/Tenancy/RecordUsageEventTest.php tests/Feature/Domains/Tenancy/RegisterUsageEventTest.php tests/Feature/Domains/Normalization/UnmonitoredAssetEmergencyTest.php tests/Feature/Domains/AI/AiUsageMeteringTest.php` → FAIL (`No se registró [billing.usage.recorded]`, etc.).
- [ ] **Step 3: Implement** — `record()`, el `try/catch` de `resolveMeter` y las tablas.
- [ ] **Step 4: Catalog + run**
  - En `### Billing (billing)`: filas de `billing.usage.recorded`, `billing.usage.duplicate_ignored`, `billing.meter.missing` (stages `record_usage`, `invoice`, `estimate`: las dos últimas se usan en los Tasks 2 y 3), `billing.emergency_surcharge.charged`, `billing.emergency_surcharge.skipped` y `billing.emergency_surcharge.notified`.
  - Frase: "`billing.usage.recorded` es el libro mayor (una fila insertada en `usage_events`); `ingestion.usage.*` y `context.usage.*` explican por qué se pidió medir".
  - En `### IA (ai) y copiloto (copilot)`: fila de `ai.usage.not_metered`.
  - Corre `tests/Feature/Domains/Tenancy`, `tests/Feature/Domains/AI`, `tests/Feature/Domains/Normalization` y `tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del uso medido con libro mayor, recargo de emergencias y tokens de ia`

---

### Task 2: Factura — términos, escalón, tope, líneas y total al centavo

**Files:**
- Modify: `app/Domains/Tenancy/Data/BillingTermsData.php`
- Modify: `app/Domains/Tenancy/Actions/ResolveBillingTerms.php`
- Modify: `app/Domains/Tenancy/Actions/ResolveAssetLimit.php`
- Modify: `app/Domains/Tenancy/Jobs/GenerateInvoiceSnapshotJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Tenancy/{InvoiceSnapshotTest,UnmonitoredEmergencyInvoiceLineTest,CostPlusMessagingBillingTest,AssetDayPricingTest,GenerateMonthlyInvoicesJobTest}.php`

**Refactors de solo lectura** (los originales delegan; el resultado no cambia):
- `BillingTermsData::explainUnitPriceFor(float $averageAssets): array` devuelve `array{unit_price: float, source: 'volume_tier'|'flat_unit_price', tier_assets: int, tier_index: ?int, tier_from: ?int, tier_to: ?int, tiers_count: int}` con el bucle actual; `tier_assets = (int) ceil(max(0, $averageAssets))`. `unitPriceFor()` pasa a `return $this->explainUnitPriceFor($averageAssets)['unit_price'];`.
- `ResolveBillingTerms::explain(int $teamId): array` devuelve `array{terms: BillingTermsData, sources: array<string, 'tenant'|'config'|'unset'>}` con la misma lectura. `execute()` pasa a `return $this->explain($teamId)['terms'];`. Fuente por campo: `tenant` si la columna de la fila no es null; si no, `config` para `unit_price`, `currency`, `min_billable_assets`, `ai_fair_use_per_asset`, `ai_overage_unit_price`, `fx_usd_rate` y `volume_tiers`, y `unset` para `included_assets` y `messaging_markup_percent` (no tienen default de plataforma).
- `ResolveAssetLimit::explain(int $teamId): array` devuelve `array{cap: ?int, source: 'billing_terms'|'tenant_feature'|'plan_rate'|'none', calc: array}` con la misma cascada. `execute()` pasa a `return $this->explain($teamId)['cap'];`. `calc`:
  - `contracted_value` (el `included_assets` leído, o null) y `unlimited_by_terms` (`contracted <= 0`);
  - `feature_limit` (si es numérico);
  - `none_reason` cuando `source = 'none'`: `meter_missing` (`$meterId === null`), `no_plan` (sin suscripción o `plan_id` null) o `no_included_quantity` (tarifa sin incluido o `<= 0`).

**`GenerateInvoiceSnapshotJob`.** `input` común: `['team_id' => $this->teamId, 'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString()]`. El job no abre transacción; las líneas de hecho van igual por `DB::afterCommit` (se ejecutan en el acto).

| Sitio | Llamada |
|---|---|
| `handle`: `Cache::lock(...)->block(30, …)` lanza `LockTimeoutException` | `try/catch` sólo de esa excepción: `SystemLog::degraded('billing.invoice.lock_timeout', reason: 'lock_wait_exceeded', input: …, calc: ['lock_seconds' => 120, 'wait_seconds' => 30])` y `throw $e;` (el job reintenta como hoy) |
| `$existingSnapshot` | `SystemLog::skipped('billing.invoice.already_exists', reason: 'period_already_invoiced', input: [..., 'stage' => 'invoice_job'])` antes del `return` |
| tras `$terms` | `SystemLog::ok('billing.terms.resolved', input: [..., 'stage' => 'invoice'], calc: ['values' => $terms->toArray(), 'sources' => $explained['sources']])`. `$explained = $resolveTerms->explain(...)`; `$terms = $explained['terms']`. |
| tras `$cap` | `SystemLog::ok('billing.asset_limit.resolved', input: [..., 'stage' => 'invoice'], calc: ['source' => $limit['source'], ...$limit['calc']], result: ['cap' => $cap])`; con `none_reason === 'meter_missing'` es `SystemLog::degraded(..., reason: 'meter_missing', …)`. `$limit = $resolveAssetLimit->explain(...)`; `$cap = $limit['cap']`. |
| tras `$assetLine` | `SystemLog::ok('billing.tier.selected', input: …, calc: ['asset_days' => $assetLine['consumed'], 'days_in_period' => $daysInPeriod, 'average_assets' => $assetDays / $daysInPeriod, ...$tier], result: ['unit_price' => $assetLine['unit_price']])` con `$tier = $terms->explainUnitPriceFor($assetDays / max(1, $daysInPeriod))`. `average_assets` va sin redondear: es el valor que decidió el escalón. |
| tras `$assetLine` | `SystemLog::ok('billing.asset_day.calculated', input: …, calc: [...self::loggableLine($assetLine), 'min_billable_assets' => $terms->minBillableAssets, 'cap_source' => $limit['source'], 'formula' => 'amount = round(billable_days * unit_price / days_in_period, 2); billable_days = max(consumed, min_billable_assets * days_in_period); included = cap === null ? consumed : min(consumed, cap * days_in_period); overage = consumed - included'], result: ['amount' => $assetLine['amount'], 'currency' => $terms->currency])` |
| cada línea que se añade a `$breakdown` después (emergencias, IA, mensajería, medidores del plan) | `SystemLog::ok('billing.invoice_line.calculated', input: …, calc: [...self::loggableLine($line), 'formula' => …], result: ['amount' => $line['amount'], 'counts_towards' => 'subtotal'|'overage_total'])` |
| `consumed()` con `$meterId === null` | `SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: [..., 'meter_code' => $meterCode, 'stage' => 'invoice'])` |
| `$messagingMeter === null` | `SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: [..., 'meter_unit' => CostPlusPricing::MICRO_UNIT, 'stage' => 'invoice'])` |
| tras `InvoiceSnapshot::query()->create(...)` | `DB::afterCommit(fn () => SystemLog::ok('billing.invoice.generated', …))`, ver abajo |

- **`consumed()`** (privado) pasa a devolver `array{consumed: int, counter_found: bool}`: `counter_found = false` cuando no hay fila en `tenant_usage_counters` (el agregado no corrió para ese periodo). El `calc` de `billing.asset_day.calculated` y de cada `billing.invoice_line.calculated` añade `counter_found` junto a la línea; la línea que va a `breakdown_json` no cambia. Los llamadores usan `['consumed']`.
- **`AssetDayPricing::loggable(array $line): array`** (público, estático, puro; nuevo) devuelve la línea sin `meter_name` (texto en español; la clave lleva `name`). Nada más cambia: los números son los mismos `float` que van a `breakdown_json`. En las tablas de este task y del Task 3, `self::loggableLine($x)` significa `AssetDayPricing::loggable($x)`.
- **`$assetDays`:** el primer `$this->consumed(...)` se extrae a `$assetDays = …['consumed']` antes de `assetDayLine`, que recibe la misma variable.
- **Fórmulas por `billing_model`** (constantes privadas del job):
  - `asset_day_surcharge`: `overage_unit_price = round(daily_rate * (1 + surcharge_percent / 100), 6); amount = round(consumed * overage_unit_price, 2)`. `daily_rate` es la de la línea principal, **ya redondeada a 6 decimales**;
  - `fair_use`: `included = floor(fair_use_per_asset * average_assets); overage = max(0, consumed - included); amount = round(overage * overage_unit_price, 2)`. `average_assets` es el de la línea principal, **redondeado a 2**;
  - `cost_plus`: `provider_cost = round(consumed / 1e6, 6); charged_usd = round(provider_cost * (1 + markup_percent / 100), 4); charged = currency == usd ? round(charged_usd, 4) : round(charged_usd * fx_usd_rate, 4); amount = round(charged, 2)`. La línea lleva además `markup_source`: `tenant_terms` (`$terms->messagingMarkupPercent !== null`), `plan_rate` (`$messagingRate?->markup_percent !== null`) o `platform_default`, y `fx_usd_rate` (ya en la línea);
  - medidores del plan: `overage = max(0, consumed - included); amount = round(overage * overage_unit_price, 2)`.
- **Medidores del plan omitidos:** los `continue` por `$code === null` o `DEDICATED_METERS` no emiten línea propia; `billing.invoice.generated.calc` lleva `plan_meters_skipped_count` y `plan_meters_billed_count`.

**`billing.invoice.generated`** (`DB::afterCommit`, valores capturados antes):

```php
SystemLog::ok('billing.invoice.generated',
    input: ['team_id' => $team->id, 'period_start' => …, 'period_end' => …, 'subscription_id' => $subscription?->id],
    calc: [
        'days_in_period' => $daysInPeriod,
        'currency' => $terms->currency,
        'subtotal_terms' => $subtotalTerms,   // [asset_day amount, asset_day_surcharge amount?] en orden de suma
        'overage_terms' => $overageTerms,     // [fair_use amount, cost_plus amount?, plan meter amounts…] en orden de suma
        'lines_count' => count($breakdown),
        'plan_meters_billed_count' => …,
        'plan_meters_skipped_count' => …,
        'formula' => 'subtotal = round(Σ subtotal_terms, 2); overage_total = round(Σ overage_terms, 2); total = round(subtotal + overage_total, 2)',
    ],
    result: [
        'invoice_id' => $snapshot->id,
        'subtotal' => $snapshot->subtotal,        // string decimal:2 del modelo
        'overage_total' => $snapshot->overage_total,
        'total' => $snapshot->total,
        'status' => $snapshot->status->value,
    ],
);
```

`$subtotalTerms[]` y `$overageTerms[]` se llenan en los mismos puntos donde hoy se hace `+=`, con el mismo valor. `$snapshot` es el retorno de `create()`, que hoy se descarta. Nunca `breakdown_json` entero en `result`.

- [ ] **Step 1: Write the failing tests**
  - `AssetDayPricingTest` (unit, con `terms()`): `test_volume_tiers_pick_the_price_by_average_fleet_size` → `explainUnitPriceFor` devuelve `source === 'volume_tier'`, `tier_index`, `tier_assets === (int) ceil(avg)` y el mismo `unit_price` que `unitPriceFor`; con `volume_tiers = []` → `flat_unit_price` y `tier_index === null`.
  - `InvoiceSnapshotTest`:
    - `test_invoice_snapshot_contains_per_meter_breakdown` → `billing.terms.resolved` con `sources.unit_price` (`tenant` o `config` según el fixture), `billing.tier.selected`, `billing.asset_day.calculated`, un `billing.invoice_line.calculated` por línea y `billing.invoice.generated`. **Recompute:** `number_format(round(array_sum($calc['subtotal_terms']), 2), 2, '.', '') === $c['result']['subtotal'] === $invoice->subtotal`; lo mismo para `overage_total` y `total = round(subtotal + overage_total, 2)`. El `amount` de la línea de tracto-día se rehace con `round($c['calc']['billable_days'] * $c['calc']['unit_price'] / $c['calc']['days_in_period'], 2)` y se compara con el de `breakdown_json`. El JSON de las entradas no contiene ningún `meter_name` del breakdown;
    - `test_duplicate_snapshot_for_same_period_is_not_created` → `billing.invoice.already_exists` / `period_already_invoiced` con `stage === 'invoice_job'`;
    - test nuevo: `$lock = Mockery::mock(Lock::class); $lock->shouldReceive('block')->andThrow(new LockTimeoutException);` y `Cache::shouldReceive('lock')->once()->andReturn($lock);` (un doble del lock, no de la DB: esperar los 30 s reales de `block` no cabe en la suite). `handle()` → `expectException(LockTimeoutException::class)`, `billing.invoice.lock_timeout` con `lock_seconds === 120` y `wait_seconds === 30`, e `InvoiceSnapshot::withoutGlobalScopes()->count() === 0`.
  - `UnmonitoredEmergencyInvoiceLineTest::test_the_invoice_charges_the_surcharged_emergency_days` → `billing.invoice_line.calculated` con `billing_model === 'asset_day_surcharge'`, `daily_rate === 10.0`, `surcharge_percent === 10.0`; se rehace `round(2 * round(10.0 * 1.10, 6), 2) === 22.0` y `subtotal_terms === [300.0, 22.0]`.
  - `CostPlusMessagingBillingTest::test_invoice_line_is_provider_cost_plus_markup` → `billing.invoice_line.calculated` con `billing_model === 'cost_plus'`, `markup_source === 'plan_rate'`, `fx_usd_rate`, y el `amount` rehecho con la fórmula `cost_plus` desde `consumed`, `markup_percent` y `fx_usd_rate`.
  - `GenerateMonthlyInvoicesJobTest::test_single_invoice_uses_the_tenant_billing_terms_currency` → `billing.invoice.generated.calc.currency` igual a la del tenant y `sources.currency === 'tenant'`; `test_each_invoice_only_contains_its_own_tenant_usage` → ninguna línea de un tenant lleva el `team_id` del otro.
  - Test nuevo en `InvoiceSnapshotTest`: un tenant sin contador para `monitored_asset_days` → `billing.asset_day.calculated.calc.counter_found === false`; borrar el meter `unmonitored_emergency_asset_days` → `billing.meter.missing` con `stage === 'invoice'`.
  - Test nuevo: `config()->set('billing.transfer.clabe', '012180001234567891')` y el JSON de las entradas no la contiene.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Tenancy/InvoiceSnapshotTest.php tests/Feature/Domains/Tenancy/UnmonitoredEmergencyInvoiceLineTest.php tests/Feature/Domains/Tenancy/CostPlusMessagingBillingTest.php tests/Feature/Domains/Tenancy/AssetDayPricingTest.php tests/Feature/Domains/Tenancy/GenerateMonthlyInvoicesJobTest.php` → FAIL.
- [ ] **Step 3: Implement** — los tres `explain`, `consumed()`, `AssetDayPricing::loggable()`, las fórmulas y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `billing.terms.resolved` (tabla de fuentes por campo), `billing.asset_limit.resolved` (fuentes y `none_reason`), `billing.tier.selected`, `billing.asset_day.calculated`, `billing.invoice_line.calculated` (una fórmula por `billing_model`), `billing.invoice.already_exists`, `billing.invoice.lock_timeout`, `billing.invoice.generated`.
  - Nota: "los importes del log son los mismos `float` de `breakdown_json`; `subtotal`, `overage_total` y `total` son el decimal persistido. `daily_rate` se muestra redondeada a 6 decimales, pero `amount` usa `unit_price / days_in_period` sin redondear; la línea de emergencias sí parte de la redondeada".
  - Corre `tests/Feature/Domains/Tenancy`, `tests/Feature/Http/Admin/AdminBillingTermsTest.php` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de la factura con términos, escalón, tope y total recomputable al centavo`

---

### Task 3: Agregación, primer cruce del incluido, estimación y ciclo de vida de la factura

**Files:**
- Modify: `app/Domains/Tenancy/Jobs/AggregateUsageJob.php`
- Modify: `app/Domains/Tenancy/Jobs/GenerateMonthlyInvoicesJob.php`
- Modify: `app/Domains/Tenancy/Actions/EstimatePeriodCharges.php`
- Modify: `app/Http/Controllers/Admin/TenantInvoiceController.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Tenancy/{AggregateUsageJobTest,AggregateUsageJobBroadcastTest,AggregateUsageJobAggregationTypeTest,EstimatePeriodChargesTest,GenerateMonthlyInvoicesJobTest,InvoicePaymentLifecycleTest}.php`, `tests/Feature/Http/Admin/AdminBillingTermsTest.php`

**`AggregateUsageJob::handle`:**

| Rama | Llamada |
|---|---|
| `$this->teamId === null` (fan-out) | tras el `chunkById`: `SystemLog::ok('billing.aggregate.fanned_out', calc: ['for_month' => $this->forMonth, 'period_start' => $this->periodStart()->toDateString()], result: ['jobs_dispatched_count' => $dispatched])`. `$dispatched` se incrementa junto a `self::dispatch`. Sin ids. |
| `$team === null` | `SystemLog::skipped('billing.aggregate.completed', reason: 'no_operational_subscription', input: ['team_id' => $this->teamId, 'period_start' => …])` |
| tras el `foreach` de meters | `SystemLog::ok('billing.aggregate.completed', input: [...], calc: ['closed_period' => $this->isClosedPeriod()], result: ['meters_count' => …, 'meters_with_overage_count' => …, 'daily_rows_upserted_count' => …, 'limit_events_dispatched_count' => …, 'broadcasts_dispatched_count' => …])` |

- `aggregateForTeamMeter()` (privado) devuelve `array{daily_rows: int, overage: int, limit_event: bool, broadcast: bool}` para sumar.
- `recalculateCounter()` (privado) devuelve lo mismo y emite, tras el `upsert`:

```php
SystemLog::ok('billing.overage.computed',
    input: ['team_id' => $team->id, 'meter_code' => $meter->code, 'period_start' => $periodStart->toDateString()],
    calc: [
        'aggregation_type' => $meter->aggregation_type->value,
        'consumed' => $totalConsumed,
        'included' => (int) $includedValue,
        'included_source' => $subscription === null ? 'no_subscription' : ($billingRate === null ? 'no_plan_rate' : 'plan_rate'),
        'previous_overage' => $previousOverage,
        'previous_counter_found' => $previousCounter !== null,
        'closed_period' => $this->isClosedPeriod(),
        'first_crossing' => $firstCrossing,
        'formula' => 'overage = max(0, consumed - included); first_crossing = !closed_period && overage > 0 && previous_overage == 0',
    ],
    result: ['overage' => $overageValue, 'limit_event_dispatched' => $firstCrossing, 'broadcast_dispatched' => $broadcast],
    debug: $overageValue === 0 && ! $firstCrossing,
);
```

  - `$firstCrossing = ! $this->isClosedPeriod() && $overageValue > 0 && $previousOverage === 0`: es la condición del `if` actual más el `return` de periodo cerrado, sin cambiarla.
  - `broadcastIfSignificantChange()` (privado) devuelve `bool` (si despachó). El `percent_change` no se registra aparte: el test lo rehace desde `previous_counter` si lo necesita.
  - Para `included_source`, `$billingRate` se declara `null` antes del `if ($subscription)`.

**`GenerateMonthlyInvoicesJob::handle`:** tras el recorrido, `SystemLog::ok('billing.invoice.run_dispatched', calc: ['period_start' => $start, 'period_end' => $end], result: ['chains_dispatched_count' => $chains])`. `$chains` se incrementa junto a cada `Bus::chain`. Sin ids.

**`EstimatePeriodCharges::execute`.** Se llama en cada visita a la página de facturación: una línea por llamada, en info (es una acción de usuario).
- Los privados `assetDaysSoFar()`, `sum()` y `messagingMicros()` reciben `array &$missingMeters` y le añaden el código (o `usd_micros`) cuando hoy devuelven `0` por meter ausente. Tras calcular, por cada uno: `SystemLog::degraded('billing.meter.missing', reason: 'meter_missing', input: ['team_id' => $teamId, 'meter_code' => $code, 'stage' => 'estimate'])`.
- `$explainedTerms = $this->resolveTerms->explain($teamId)`, `$limit = $this->resolveAssetLimit->explain($teamId)` (Task 2).
- Antes del `return`:

```php
SystemLog::ok('billing.estimate.calculated',
    input: ['team_id' => $teamId, 'today' => $today->toDateString()],
    calc: [
        'period_start' => …, 'period_end' => …, 'days_in_period' => $daysInPeriod,
        'days_elapsed' => $daysElapsed, 'days_recorded' => $daysRecorded, 'today_sampled' => $todaySampled,
        'remaining_days' => $remainingDays, 'monitored_now' => $monitoredNow,
        'asset_days' => $assetDays, 'projected_asset_days' => $projectedAssetDays,
        'terms_sources' => $explainedTerms['sources'],
        'cap' => $cap, 'cap_source' => $limit['source'],
        'to_date' => self::loggableLine($toDate), 'projected' => self::loggableLine($projected),
        'ai' => self::loggableLine($aiProjected), 'emergency' => self::loggableLine($emergency),
        'messaging' => self::loggableLine($messaging), 'markup_source' => $terms->messagingMarkupPercent !== null ? 'tenant_terms' : 'platform_default',
        'formula' => 'remaining_days = diff(today, period_end) + (today_sampled ? 0 : 1); projected_asset_days = asset_days + monitored_now * remaining_days; total = round(assets + ai + messaging + emergency, 2)',
    ],
    result: ['currency' => $terms->currency, 'total_to_date' => $totalToDate, 'total_projected' => $totalProjected, 'over_cap' => $cap !== null && $monitoredNow > $cap],
);
```

  - `$totalToDate` / `$totalProjected` se extraen a variables: los mismos `round(...)` que hoy van al array.
  - `self::loggableLine` es `AssetDayPricing::loggable` (Task 2).
  - `markup_source` no conoce `plan_rate`: la estimación hoy no lo consulta. Se registra tal cual; ver "hallazgos" al final.
  - `dailyCloses` no se registra.

**`TenantInvoiceController`** (petición de super-admin; `input` con `team_id` del tenant de la ruta y `actor_user_id`):
- `generate`, `$existing` → `SystemLog::skipped('billing.invoice.already_exists', reason: 'period_already_invoiced', input: [..., 'period_start' => $start, 'period_end' => $end, 'stage' => 'admin_request'])`;
- `generate`, tras `Bus::chain(...)->dispatch()` → `SystemLog::ok('billing.invoice.generation_requested', input: [...], result: ['chain_requested' => true])`;
- `markPaid` y `void`, tras `save()` → `DB::afterCommit(fn () => SystemLog::ok('billing.invoice.status_changed', input: ['team_id' => …, 'invoice_id' => $invoice->id, 'actor_user_id' => …], calc: ['from_status' => $from, 'to_status' => $invoice->status->value, 'receipt_present' => $invoice->payment_receipt_file_object_id !== null]))`. `$from` = `$invoice->status->value` leído antes del `forceFill`. Nunca `payment_note`, el comprobante ni `$team->name`.

- [ ] **Step 1: Write the failing tests**
  - `AggregateUsageJobTest`:
    - `test_usage_counter_calculates_overage` → `billing.overage.computed` con `consumed`, `included`, `overage === max(0, consumed - included)` (rehecho) e igual a `TenantUsageCounter::overage_value`;
    - `test_usage_limit_exceeded_event_dispatched_on_overage` → `first_crossing === true`, `limit_event_dispatched === true`; un segundo `handle()` da `first_crossing === false` y `previous_overage > 0`;
    - `test_the_scheduled_run_fans_out_one_job_per_subscribed_tenant` → `billing.aggregate.fanned_out` con `jobs_dispatched_count` igual a los `Queue::assertPushed` y sin ningún `team_id` en la entrada.
  - `AggregateUsageJobBroadcastTest`:
    - `test_it_broadcasts_when_consumed_value_changes_more_than_five_percent` → `broadcast_dispatched === true`;
    - `test_it_treats_meter_without_subscription_or_rate_as_zero_included` → `included_source === 'no_subscription'` o `no_plan_rate`, y la línea en `debug`.
  - `AggregateUsageJobAggregationTypeTest`: en el test del gauge `Max`, `aggregation_type === 'max'` y `consumed` = el pico.
  - `GenerateMonthlyInvoicesJobTest`:
    - `test_it_closes_and_invoices_the_previous_month_for_operational_tenants` → `billing.invoice.run_dispatched` con `chains_dispatched_count` y `period_start === '2026-09-01'`;
    - en la corrida del cierre, `billing.overage.computed` con `closed_period === true` y `first_crossing === false`.
  - `EstimatePeriodChargesTest`:
    - `test_it_projects_the_month_from_asset_days_so_far_and_units_monitored_now` → `billing.estimate.calculated`; se rehace `projected_asset_days === asset_days + monitored_now * remaining_days`, `remaining_days` con la fórmula, y `number_format(round(projected.amount + ai.amount + messaging.amount + emergency.amount, 2), 2, '.', '') === number_format($estimate['totalProjected'], 2, '.', '')`;
    - `test_today_is_not_projected_twice_once_its_sample_exists` → `today_sampled === true`;
    - `test_the_estimate_only_reads_the_tenants_own_usage_and_fleet` → `input.team_id` es el propio y el JSON no contiene el id del otro team como `team_id`.
  - `AdminBillingTermsTest::test_super_admin_generates_the_previous_month_invoice_on_demand` → `billing.invoice.generation_requested`; una segunda petición con la factura ya creada → `billing.invoice.already_exists` con `stage === 'admin_request'`.
  - `InvoicePaymentLifecycleTest`:
    - `test_super_admin_marks_invoice_paid_with_audit` → `billing.invoice.status_changed` con `to_status === 'paid'`;
    - `test_tenant_can_upload_payment_receipt` → el JSON de las entradas no contiene el nombre del archivo subido, su `object_key` ni la nota.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Tenancy/AggregateUsageJobTest.php tests/Feature/Domains/Tenancy/AggregateUsageJobBroadcastTest.php tests/Feature/Domains/Tenancy/AggregateUsageJobAggregationTypeTest.php tests/Feature/Domains/Tenancy/EstimatePeriodChargesTest.php tests/Feature/Domains/Tenancy/GenerateMonthlyInvoicesJobTest.php tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php tests/Feature/Http/Admin/AdminBillingTermsTest.php` → FAIL.
- [ ] **Step 3: Implement** — los retornos privados, las variables extraídas y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `billing.aggregate.fanned_out`, `billing.aggregate.completed`, `billing.overage.computed` (con la fórmula del primer cruce), `billing.invoice.run_dispatched`, `billing.estimate.calculated`, `billing.invoice.generation_requested`, `billing.invoice.status_changed`; amplía `billing.invoice.already_exists` con el stage `admin_request`.
  - Corre `tests/Feature/Domains/Tenancy`, `tests/Feature/Http/Admin` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de la agregación, el primer cruce del incluido, la estimación y el ciclo de la factura`

---

### Task 4: Tracto-día — días no cobrados, cierre diario, bloqueo del tenant y cambios de vigilancia

**Files:**
- Modify: `app/Domains/Assets/Actions/RecordMonitoredAssetDay.php`
- Modify: `app/Domains/Assets/Commands/RecordAssetUsageMeters.php`
- Modify: `app/Domains/Assets/Actions/SetAssetMonitoring.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Assets/{RecordMonitoredAssetDayTest,AssetDayMeteringTest,AssetUsageMeteringTest,ActiveCamerasUsageMeterTest,AssetMonitoringTest,AssetMonitoringEndpointTest}.php`

**`RecordMonitoredAssetDay` — método nuevo.** `public function outcome(Asset $asset, ?string $localDate = null, ?bool $tenantBillable = null, bool $fromDailyClose = false): string` con el cuerpo actual. Devuelve `recorded`, `already_recorded`, `not_monitored`, `inactive`, `tenant_not_billable` o `legacy_sample_exists`. `execute()` conserva su firma y pasa a `return in_array($this->outcome($asset, $localDate, $tenantBillable), ['recorded', 'already_recorded'], true);`: hoy devuelve `true` siempre que llama a `RecordUsageEvent`, se haya insertado o no.
- Cambios internos: se separan `! $asset->isMonitored()` (`not_monitored`) y `status === Inactive` (`inactive`); `tenantBillable` se sustituye dentro de `outcome` por `$blocked = $tenantBillable === null ? TenantCanSend::blockedReason($teamId) : ($tenantBillable ? null : 'resolved_by_caller')`; `recordUsage->execute` pasa a `recordUsage->record` (Task 1) y su `bool` separa `recorded` de `already_recorded`.
- `input` común: `['team_id' => $teamId, 'asset_id' => $asset->id, 'local_date' => $localDate]`.

| Resultado | Llamada |
|---|---|
| `not_monitored` | `SystemLog::skipped('billing.monitored_day.skipped', reason: 'not_monitored', input: …, calc: ['monitoring_state' => $asset->monitoring_state->value], debug: $fromDailyClose)` |
| `inactive` | `… reason: 'inactive', calc: ['asset_status' => $asset->status->value], debug: $fromDailyClose` |
| `tenant_not_billable` | `… reason: 'tenant_not_billable', calc: ['blocked_reason' => $blocked]` (info: el cierre ya filtra estos tenants, así que sólo lo ve el encendido) |
| `legacy_sample_exists` | `… reason: 'legacy_sample_exists', calc: ['legacy_event_key' => …]` |
| `already_recorded` | `… reason: 'already_recorded', result: ['event_key' => self::eventKey(...)], debug: $fromDailyClose` |
| `recorded` | nada propio: lo narra `billing.usage.recorded` (meter `monitored_asset_days`). |

**`RecordAssetUsageMeters::handle`** (comando de plataforma):
- El `if (! RecordMonitoredAssetDay::tenantBillable(...))` pasa a `if (($blocked = TenantCanSend::blockedReason((int) $team->id)) !== null)`: misma condición (`tenantBillable` es exactamente `blockedReason === null`). En esa rama, dentro del contexto del tenant: `SystemLog::skipped('billing.tenant.blocked', reason: $blocked, input: ['team_id' => $team->id, 'stage' => 'daily_close', 'local_date' => $date])`. Los valores de `$blocked` ya son snake_case: `tenant_missing`, `subscription_suspended`, `subscription_canceled`, `subscription_expired`.
- `recordMonitoredAssets()` llama `$recordAssetDay->outcome($asset, $date, tenantBillable: true, fromDailyClose: true)` en lugar de `execute(...)` y cuenta por resultado; `$count` (activos recorridos) no cambia. Devuelve `array{assets_monitored_count: int, outcome_counts: array<string, int>, gauge_recorded: bool}`.
- `recordActiveCameras()` devuelve `array{attached_cameras_count: int, standalone_cameras_count: int, recorded: bool}`.
- Tras ambos, dentro del contexto del tenant: `SystemLog::ok('billing.daily_close.tenant_closed', input: ['team_id' => $team->id, 'local_date' => $date], calc: ['assets_monitored_count' => …, 'recorded_count' => …, 'already_recorded_count' => …, 'legacy_sample_exists_count' => …, 'attached_cameras_count' => …, 'standalone_cameras_count' => …], result: ['monitored_assets_gauge_recorded' => …, 'active_cameras_recorded' => …])`.
- En el `catch`: `SystemLog::failed('billing.daily_close.tenant_failed', reason: 'exception', input: ['team_id' => $team->id, 'local_date' => $date], error: $e)`, **además** del `report($e)` y el `warn` que ya existen (no se tocan; el texto de consola es comportamiento).
- Al final, fuera de todo tenant: `SystemLog::ok('billing.daily_close.completed', …)` si `$failures === 0`, o `SystemLog::degraded('billing.daily_close.completed', reason: 'tenant_failures', …)`, con `input: ['local_date' => $date, 'date_source' => $this->option('date') ? 'option' : 'today']` y `result: ['teams_scanned_count' => …, 'teams_closed_count' => …, 'teams_blocked_count' => …, 'teams_failed_count' => $failures, 'asset_days_recorded_count' => Σ recorded, 'asset_days_already_recorded_count' => Σ already_recorded, 'cameras_count' => Σ cámaras]`. Solo sumas: ningún id.
- Fecha inválida (`$date === null`): `SystemLog::failed('billing.daily_close.completed', reason: 'invalid_date', input: ['date_option_present' => true])`. Nunca el valor recibido.

**`SetAssetMonitoring`:**
- `execute` y `executeMany` usan `$limit = $this->resolveAssetLimit->explain($teamId)` y `$cap = $limit['cap']`, y emiten una vez por llamada `SystemLog::ok('billing.asset_limit.resolved', input: ['team_id' => $teamId, 'stage' => 'monitoring_toggle'], calc: ['source' => $limit['source'], ...$limit['calc']], result: ['cap' => $cap])` (`degraded` / `meter_missing` como en el Task 2).
- `$billable` pasa a `$blocked = TenantCanSend::blockedReason($teamId)` y `$billable = $blocked === null`: misma semántica. Si se va a encender algo con `$blocked !== null`: `SystemLog::skipped('billing.tenant.blocked', reason: $blocked, input: ['team_id' => $teamId, 'stage' => 'monitoring_toggle'])`, una vez por llamada, antes del bucle.
- `apply()` (privado):
  - `$previous === $state` → `SystemLog::skipped('assets.monitoring.changed', reason: 'same_state', input: ['team_id' => …, 'asset_id' => $asset->id], calc: ['state' => $state->value])`;
  - encender: `$this->recordAssetDay->outcome($asset, tenantBillable: $billable)` en lugar de `execute` (el retorno se descartaba), guardado en `$assetDayOutcome`;
  - tras el `broadcast(...)`: `DB::afterCommit(fn () => SystemLog::ok('assets.monitoring.changed', input: ['team_id' => (int) $asset->team_id, 'asset_id' => $asset->id, 'actor_user_id' => $actor?->id], calc: ['previous_state' => $previous->value, 'new_state' => $state->value, 'assets_monitored_before' => $monitoredBefore, 'assets_monitored_after' => $monitored, 'cap' => $cap, 'over_cap' => $overCap, 'overage_assets' => $cap === null ? 0 : max(0, $monitored - $cap), 'tenant_billable' => $billable, 'asset_day_outcome' => $assetDayOutcome, 'reason_present' => $reason !== null && $reason !== ''], result: ['changed' => true, 'limit_event_dispatched' => $overCap]))`. `$monitoredBefore` se captura al entrar en `apply`.
- `executeMany`, activo de otro tenant (el `continue`) → `SystemLog::skipped('assets.monitoring.changed', reason: 'other_tenant', input: ['team_id' => $teamId], calc: ['team_matches' => false])`. Sin `asset_id`.
- Nunca `$reason` (texto libre), `$actor->email` ni `$asset->name`: la auditoría ya los guarda en la DB.

- [ ] **Step 1: Write the failing tests**
  - `RecordMonitoredAssetDayTest` (setUp con `AssetMeterSeeder`; `assetDays`):
    - `test_switching_a_unit_on_records_todays_asset_day_immediately` → `billing.usage.recorded` con `meter_code === 'monitored_asset_days'` y, tras apagar y encender, `billing.monitored_day.skipped` / `already_recorded` en info (no viene del cierre); `assets.monitoring.changed` con `asset_day_outcome === 'recorded'` y luego `already_recorded`;
    - `test_the_daily_close_can_backfill_a_missed_day` → `billing.daily_close.completed` con `date_source === 'option'`, `asset_days_recorded_count === 2` en la primera corrida y `asset_days_already_recorded_count === 2` en la segunda; `billing.monitored_day.skipped` / `already_recorded` en `debug`;
    - `test_an_invalid_backfill_date_is_rejected` → `billing.daily_close.completed` failed / `invalid_date`, sin el valor `10/09/2026` en el JSON;
    - `test_a_suspended_tenant_accrues_no_asset_days` → `billing.tenant.blocked` / `subscription_suspended` con `stage === 'daily_close'`, `teams_blocked_count === 1`; el `execute` directo da `billing.monitored_day.skipped` / `tenant_not_billable` con `blocked_reason === 'subscription_suspended'`;
    - `test_a_day_already_charged_by_the_old_nightly_sample_is_not_charged_again` → `legacy_sample_exists`;
    - `test_one_tenants_close_never_writes_into_another` → cada `billing.daily_close.tenant_closed` lleva su propio `team_id`, y `billing.daily_close.completed` no lleva ningún `team_id`.
  - `AssetDayMeteringTest::test_only_monitored_units_count_and_asset_days_are_recorded` → `billing.daily_close.tenant_closed` con `assets_monitored_count` y `recorded_count`.
  - `ActiveCamerasUsageMeterTest::test_stand_alone_camera_assets_still_count_once` → `attached_cameras_count` + `standalone_cameras_count` igual a la cantidad registrada.
  - `AssetMonitoringTest` (con `setupTeam`):
    - `test_switching_on_beyond_the_cap_is_allowed_and_flagged_as_billable_extra` → `assets.monitoring.changed` con `over_cap === true`, `overage_assets === assets_monitored_after - cap` y `limit_event_dispatched === true`; `billing.asset_limit.resolved` con la fuente del fixture;
    - `test_setting_the_same_state_is_a_no_op` → `same_state`;
    - `test_switching_monitoring_never_touches_another_tenant` → el JSON no contiene el `team_id` ajeno como `team_id` ni el `name` de ningún activo;
    - **rollback** (test nuevo): `DB::transaction` con `execute(...)` y `throw` → `Asset::monitoring_state` sin cambio y `assertSystemNotLogged('assets.monitoring.changed')`.
  - `AssetMonitoringEndpointTest::test_bulk_switches_only_the_units_of_the_current_team` → un `billing.asset_limit.resolved` por petición (no por activo) y el JSON no contiene el email del usuario.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Assets/RecordMonitoredAssetDayTest.php tests/Feature/Domains/Assets/AssetDayMeteringTest.php tests/Feature/Domains/Assets/AssetUsageMeteringTest.php tests/Feature/Domains/Assets/ActiveCamerasUsageMeterTest.php tests/Feature/Domains/Assets/AssetMonitoringTest.php tests/Feature/Domains/Assets/AssetMonitoringEndpointTest.php` → FAIL.
- [ ] **Step 3: Implement** — `outcome()`, los retornos privados del comando y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `billing.monitored_day.skipped`, `billing.tenant.blocked` (stages `daily_close` y `monitoring_toggle`; nota: "los demás llamadores de `TenantCanSend` ya narran su bloqueo con su propio código: `notifications.notification.cancelled`, `notifications.escalation_guard.blocked`, `automation.action.stopped`"), `billing.daily_close.tenant_closed`, `billing.daily_close.tenant_failed`, `billing.daily_close.completed`.
  - Sección nueva `### Activos (assets)` con `assets.monitoring.changed`.
  - Corre `tests/Feature/Domains/Assets`, `tests/Feature/Domains/Tenancy` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del tracto-día, el cierre diario, el bloqueo del tenant y los cambios de vigilancia`

---

### Task 5: Costo real de Twilio — precio, estimado, cierre sin costo y reconciliación

**Files:**
- Modify: `app/Domains/Notifications/Actions/FinalizeMessagingCharge.php`
- Modify: `app/Domains/Notifications/Jobs/ReconcileMessagingChargesJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Notifications/ReconcileMessagingChargesJobTest.php`, `tests/Feature/Domains/Tenancy/CostPlusMessagingBillingTest.php`

**`FinalizeMessagingCharge`.** Aquí se fija el **costo** del proveedor (micro-USD). El margen y el tipo de cambio se aplican al cerrar la factura (`billing.invoice_line.calculated`, `cost_plus`, Task 2); el catálogo lo dice.
- `finalize()` (privado) recibe además `array $priceCalc` con el origen del precio:
  - `withProviderPrice` → `['price_source' => 'provider', 'provider_price' => $price, 'price_unit' => LoggableCode::guard($priceUnit), 'formula' => 'price_micros = round(abs(provider_price) * 1e6)']`;
  - `withEstimate` → `['price_source' => 'estimate', ...$estimate]`, con `estimateMicros()` (privado) devolviendo `array{price_micros: int, estimate_unit: 'voice_minute'|'whatsapp_message'|'sms_segment', unit_price_usd: float, units: int, formula: string}`. `units` = `max(1, (int) ceil(duration_seconds / 60))` para llamadas, `1` para WhatsApp y `max(1, segments)` para SMS; `price_micros = (int) round(unit_price_usd * units * 1_000_000)`;
  - `withoutCost` → `['price_source' => 'free']`.
- `input` común: `['team_id' => $charge->team_id, 'charge_id' => $charge->id, 'provider_sid' => $charge->provider_sid, 'resource_type' => $charge->resource_type->value, 'channel_type' => $charge->channel_type->value]`.

| Rama | Llamada |
|---|---|
| `finalized_at !== null` | `SystemLog::skipped('billing.messaging_charge.finalized', reason: 'already_finalized', input: …)` |
| `$priceMicros <= 0`, tras el `save()` | `DB::afterCommit(fn () => SystemLog::ok('billing.messaging_charge.finalized', input: …, calc: [...$priceCalc, 'price_micros' => $priceMicros, 'estimated' => $estimated], result: ['metered' => false, 'meter_skipped_reason' => 'zero_cost']))` |
| `$metered === true` | igual, con `result: ['metered' => true, 'meter_code' => self::METER_CODE, 'event_key' => "twilio_charge:{$charge->provider_sid}"]` |
| `$metered === false` | `DB::afterCommit(fn () => SystemLog::degraded('billing.messaging_charge.finalized', reason: 'not_metered', input: …, calc: […], result: ['metered' => false, 'finalized' => true]))`. El cargo queda finalizado sin uso: el reconciliador ya no lo vuelve a tomar (ver hallazgos). La causa ya la registra `billing.messaging_usage.not_metered`. |

**`ReconcileMessagingChargesJob`:**
- `reconcile()` (privado) devuelve el `branch`: `not_found_at_provider`, `priced`, `free_status`, `estimated_after_hours`, `gave_up_priced`, `gave_up_estimated` o `rescheduled`. Tras decidir, dentro del contexto del tenant: `SystemLog::ok('billing.messaging_charge.reconciled', input: ['team_id' => $charge->team_id, 'charge_id' => $charge->id, 'resource_type' => …], calc: ['branch' => $branch, 'provider_status' => LoggableCode::guard($status), 'terminal' => …, 'price_present' => $price !== null, 'age_hours' => (int) $age->diffInHours(now()), 'estimate_after_hours' => self::ESTIMATE_AFTER_HOURS, 'give_up_after_hours' => self::GIVE_UP_AFTER_HOURS], result: $branch === 'rescheduled' ? $nextCheck : [], debug: $branch === 'rescheduled')`. En `not_found_at_provider`: `calc: ['branch' => …, 'provider_error_code' => 20404]`.
- `scheduleNextCheck()` (privado) devuelve `array{check_attempts: int, delay_minutes: int, next_check_at: string, formula: 'delay_minutes = min(60, 2 ** min(check_attempts, 6))'}`.
- `billing.messaging_charge.reconcile_failed` (existente) se mueve **después** de `scheduleNextCheck` en el `catch` y gana `calc: $nextCheck`. `error: $e` se mantiene: `TwilioException` del SDK lleva la URL del recurso, que `SafeException` sanea; se añade `error_class` y `provider_error_code => $e->getCode()` para no depender del mensaje.
- `handle`, al final (fuera de todo tenant): `SystemLog::ok('billing.messaging_reconcile.completed', calc: ['batch_size' => self::BATCH_SIZE, 'time_budget_seconds' => self::TIME_BUDGET_SECONDS], result: ['charges_due_count' => $due->count(), 'charges_processed_count' => …, 'charges_failed_count' => …, 'budget_exhausted' => …, 'branch_counts' => [...]])`. `branch_counts` usa claves `{branch}_count`. Sin ids.

- [ ] **Step 1: Write the failing tests**
  - `ReconcileMessagingChargesJobTest` (con `runReconciler`, `message`, `queuedDelivery`, `costEvents`):
    - `test_real_price_is_metered_once` → `billing.messaging_charge.finalized` con `price_source === 'provider'`, `provider_price === '-0.00790'` y `price_micros === (int) round(abs((float) '-0.00790') * 1_000_000)` === `$charge->price_micros`; `metered === true`; en la segunda corrida no hay otra línea `finalized` ok;
    - `test_terminal_without_price_waits_then_is_estimated_after_24_hours` → primero `billing.messaging_charge.reconciled` con `branch === 'rescheduled'` en `debug` y `delay_minutes === min(60, 2 ** min(check_attempts, 6))` (rehecho) igual a `next_check_at` persistido; luego `estimated_after_hours` y `finalized` con `price_source === 'estimate'`, `units` y `unit_price_usd` que rehacen `price_micros`;
    - `test_failed_message_is_finalized_without_cost` → `branch === 'free_status'`, `meter_skipped_reason === 'zero_cost'`;
    - `test_unknown_sid_at_twilio_is_closed_without_cost` → `not_found_at_provider`;
    - `test_reconciler_writes_each_charge_only_into_its_own_tenant` → cada `billing.messaging_charge.*` lleva el `team_id` de su cargo, y `billing.messaging_reconcile.completed` no lleva `team_id`;
    - test nuevo: borrar el meter `messaging_cost_micros` y reconciliar un precio real → `billing.messaging_charge.finalized` degraded / `not_metered` y `billing.messaging_usage.not_metered`.
    - En todos, el JSON de las entradas no contiene el número de destino de `queuedDelivery`.
  - `CostPlusMessagingBillingTest::test_invoice_line_is_provider_cost_plus_markup`: ya cubierto en el Task 2; aquí sólo se añade `assertNoSensitiveDataLogged()` si falta.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Notifications/ReconcileMessagingChargesJobTest.php tests/Feature/Domains/Tenancy/CostPlusMessagingBillingTest.php` → FAIL.
- [ ] **Step 3: Implement** — `$priceCalc`, `estimateMicros()`, los retornos de `reconcile()` y `scheduleNextCheck()`, y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `billing.messaging_charge.finalized` (tabla de `price_source`), `billing.messaging_charge.reconciled` (tabla de `branch`), `billing.messaging_reconcile.completed`; amplía `billing.messaging_charge.reconcile_failed` con `calc` del reintento.
  - Nota: "el costo se fija aquí en micro-USD; margen y FX se aplican en la factura (`billing.invoice_line.calculated`, `cost_plus`)".
  - Corre `tests/Feature/Domains/Notifications`, `tests/Feature/Domains/Tenancy` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del costo real de twilio con precio, estimado y reconciliación`

---

### Task 6: Feed de telemática — ciclo, pausa, circuito, puntos descartados, despacho y fuera de horario

**Files:**
- Modify: `app/Domains/Assets/Data/VehicleStatsIngestResult.php`
- Modify: `app/Domains/Assets/Actions/IngestVehicleStatsPage.php`
- Modify: `app/Domains/Assets/Jobs/FollowVehicleStatsFeedJob.php`
- Modify: `app/Domains/Assets/Jobs/DispatchTelematicsFeedsJob.php`
- Modify: `app/Domains/Assets/Jobs/BackfillVehicleStatsJob.php`
- Modify: `app/Domains/Assets/Actions/RaiseAfterHoursMovement.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Assets/{FollowVehicleStatsFeedJobTest,IngestVehicleStatsPageTest,DispatchTelematicsFeedsJobTest,BackfillVehicleStatsJobTest,RaiseAfterHoursMovementTest}.php`

Todas las líneas de este task pasan `channel: 'telematics'`, salvo `telematics.circuit.opened`.

**`VehicleStatsIngestResult`** — parámetro nuevo al final del constructor: `public array $dropped = []` (`array<string, int>`, motivo → conteo). `merge()` los suma por clave. Nada más cambia.

**`IngestVehicleStatsPage::execute`** — cuenta sin consultas extra (`IngestVehicleStatsPageTest` fija el número de queries):
- `no_external_id`: puntos (ubicaciones y lecturas) con `external_id` vacío;
- `unknown_vehicle`: puntos cuyo vehículo no resolvió a un activo del tenant (incluido el `return` temprano con `$assets === []`, donde son todos los puntos con id);
- `missing_coordinates`: ubicaciones sin latitud o longitud;
- `unsupported_type` y `missing_value`: lecturas descartadas por tipo o por valor;
- `unchanged_value`: lecturas que `RecordAssetTelemetry::isNewReading` descarta;
- `already_stored`: `count($rows) - $stored` en cada tabla (los que el `insertOrIgnore` ignoró por índice único).
Los privados `storeLocations` / `storeReadings` devuelven un elemento más (el mapa de descartes). Los dos `return new VehicleStatsIngestResult` tempranos pasan el mapa.

**`FollowVehicleStatsFeedJob::handle`.** `$cycleInput` no cambia (`integration_id`, `feed`).

| Sitio | Llamada |
|---|---|
| `$cursor->isPaused()` | `SystemLog::skipped('telematics.cycle.paused', reason: 'paused', input: $cycleInput, calc: ['paused_until' => $cursor->paused_until->toIso8601String(), 'seconds_remaining' => (int) now()->diffInSeconds($cursor->paused_until), 'consecutive_failures' => $cursor->consecutive_failures], channel: 'telematics')`. Es raro: el despachador ya salta los cursores en pausa. |
| tras cada página (después del `DB::transaction`) | por cada motivo con conteo `> 0` en `$pageResult->dropped`: `SystemLog::skipped('telematics.points.dropped', reason: $motivo, input: [...$cycleInput, 'page' => $pages], calc: ['dropped_count' => $n], debug: in_array($motivo, ['already_stored', 'unchanged_value', 'unknown_vehicle'], true), channel: 'telematics')` |
| éxito | `telematics.cycle.completed` con `calc` y `result` ampliados (abajo) |
| fallo | `telematics.cycle.failed` con `reason: $failureInfo['reason']`, `calc` y `result` ampliados |

- **Refactor privado:** `handleFailure()` devuelve `array{reason: 'rate_limited'|'provider_unavailable'|'cursor_rejected'|'unauthorized'|'provider_error', failure_class: string, consecutive_failures: int, retry_after_s: ?float, pause_s: ?int, backoff_base_s: ?int, backoff_max_s: ?int, paused_until: ?string, backfill_requested: bool, circuit_opened: bool}`. El `match` y las escrituras al cursor no cambian:
  - `pause_s = (int) ceil(max(1.0, $e->retryAfterSeconds))` en `rate_limited`;
  - `pause_s = $this->backoffSeconds($failures)` en `provider_unavailable`, con `backoff_base_s`/`backoff_max_s` leídos de la misma config;
  - `restartFromHistory()` devuelve `bool` (si pidió el backfill: `$lastDataAt !== null`) y `consecutive_failures` queda en `0`, como hoy.
- `$lastHasNextPage` guarda `$page->hasNextPage` de la última página leída. `$maxPagesHit = $failure === null && $pages >= $maxPages && $lastHasNextPage`.
- **Ciclo ampliado** (sobre la línea existente, mismas claves más estas):
  - `calc`: `max_pages_per_cycle`, `max_pages_hit`, `dropped_count_by_reason` (claves `{motivo}_count`, suma del ciclo), y en fallo todo `$failureInfo`;
  - `result`: las claves actuales (`pages`, `locations`, `readings`, `moved_assets`, `lag_s`) más `cursor_advanced` (`end_cursor` distinto del inicial).
  - Nunca `last_error` del cursor.
- **`openCircuit()`**, tras `IntegrationStatusChanged::dispatch`: `SystemLog::degraded('telematics.circuit.opened', reason: 'unauthorized', input: ['team_id' => $this->integration->team_id, 'integration_id' => $this->integration->id, 'feed' => $this->feed->value], result: ['integration_status' => TenantIntegrationStatus::Error->value])`. Canal por defecto. Nunca `last_error_message`.
- **`detectAfterHoursMovement()`** (privado):
  - `! $schedule->isPersisted` → `SystemLog::skipped('assets.after_hours.evaluated', reason: 'no_schedule_profile', input: $cycleInput, debug: true, channel: 'telematics')`;
  - `withinOperatingHours` → `… reason: 'within_operating_hours', debug: true`;
  - `$moving === []` → `… reason: 'no_moving_positions', calc: ['positions_count' => count($result->positions)], debug: true`.
  `$cycleInput` se pasa como parámetro.

**`RaiseAfterHoursMovement` — método nuevo.** `public function evaluate(Asset $asset, ResolvedSchedule $schedule, float $latitude, float $longitude, ?float $speedKph, CarbonInterface $recordedAt): array` devuelve `array{raised: bool, branch: string, calc: array, raw_event_id: ?int}` con el cuerpo actual. `execute()` pasa a `return $this->evaluate(...)['raised'];`. `input` común: `['team_id' => (int) $asset->team_id, 'asset_id' => $asset->id]`. `calc` común:
- `speed_kph` y `moving_threshold_kph` (`config('telematics.moving_speed_kph')`), `motion_state_moving` (`MovementCriterion::isMoving`);
- `position_age_s` = `(int) $recordedAt->diffInSeconds(now())` y `freshness_s` = `self::FRESHNESS_MINUTES * 60`;
- `cooldown_s` = `$cooldownHours * 3600` y `last_alert_age_s` (`after_hours_alerted_at` → segundos, o null);
- `schedule_profile_code` = `LoggableCode::guard($schedule->profileCode)`. Ni la zona horaria ni la hora local.

| `branch` | Línea |
|---|---|
| `outside_schedule_gate` (la guarda de horario, redundante con el job) | `SystemLog::skipped('assets.after_hours.evaluated', reason: 'within_operating_hours', …, debug: true, channel: 'telematics')` |
| `asset_inactive` | `… reason: 'asset_inactive', calc: [..., 'asset_status' => $asset->status->value]` (info) |
| `not_moving` / `stale_position` | `… reason: 'not_moving'` / `'stale_position'`, `debug: true`. Se separa la condición `||` en dos `if` con el mismo orden. |
| `cooldown_active` | `… reason: 'cooldown_active', debug: true` |
| `raised` | `SystemLog::ok('assets.after_hours.evaluated', input: …, calc: […], result: ['raised' => true, 'raw_event_id' => $rawEvent->id, 'job_requested' => true], channel: 'telematics')` |

**`DispatchTelematicsFeedsJob::handle`** (corre en el proceso del scheduler, fuera de toda cola):
- `isDue()` (privado) devuelve `?string`: `null` si toca, `'paused'` o `'not_due'` si no. El `if` usa `=== null`.
- Contadores: `integrations_count`, `feed_disabled_count` (integraciones con `feedEnabled` falso), `dispatched_count`, `not_due_count`, `paused_count` y `dispatched_count_by_feed` (`{feed}_count`).
- `$integrations->isEmpty()` → `SystemLog::skipped('telematics.feeds.dispatched', reason: 'no_active_integrations', debug: true, channel: 'telematics')`.
- Al final → `SystemLog::ok('telematics.feeds.dispatched', calc: ['tick_seconds' => self::TICK_SECONDS], result: [...contadores], debug: $dispatched === 0, channel: 'telematics')`. Sin ids: es un recorrido de plataforma.

**`BackfillVehicleStatsJob::handle`:**
- ventana vacía (`$from >= $this->until`) → `SystemLog::skipped('telematics.backfill.completed', reason: 'empty_window', input: [...], calc: ['backfill_hours' => …, 'floor' => $floor->toIso8601ZuluString()], channel: 'telematics')`;
- `ProviderRateLimited` → `… reason: 'rate_limited', calc: ['release_s' => (int) ceil(max(1.0, $e->retryAfterSeconds)), 'pages_stored_before' => $pages]`;
- `ProviderUnauthorized` → `… reason: 'unauthorized'`;
- éxito → la línea existente gana `calc: ['max_pages' => self::MAX_PAGES, 'max_pages_hit' => $pages >= self::MAX_PAGES && $page->hasNextPage, 'window_capped' => $from->ne($this->from)]` y `result.dropped_count_by_reason` (suma de `$result->dropped`).

- [ ] **Step 1: Write the failing tests**
  - `FollowVehicleStatsFeedJobTest` (con `integration`, `linkAsset`, `page`, `gps`, `cycle`, `cursor`):
    - `test_a_cycle_stores_every_point_moves_the_live_position_and_broadcasts_once` → `telematics.cycle.completed` con `channel === 'telematics'`, `max_pages_hit === false` y `cursor_advanced === true`; el JSON no contiene `19.43` ni `-99.13`;
    - `test_it_drains_the_feed_while_there_are_more_pages_but_caps_a_cycle` → `max_pages_hit === true`, `pages === max_pages_per_cycle`;
    - `test_replaying_a_window_stores_nothing_twice` → `telematics.points.dropped` / `already_stored` en `debug` con `dropped_count` igual a los puntos repetidos;
    - `test_unchanged_readings_are_dropped_and_changed_ones_broadcast` → `unchanged_value`;
    - `test_a_rate_limit_pauses_this_feed_for_retry_after_without_moving_the_cursor` → `telematics.cycle.failed` / `rate_limited` con `pause_s === (int) ceil(max(1.0, retry_after_s))` (rehecho) y `paused_until` igual al del cursor persistido;
    - `test_server_errors_back_off_exponentially` → `provider_unavailable` con `pause_s === min(backoff_max_s, backoff_base_s * 2 ** min(16, consecutive_failures - 1))` en cada fallo;
    - `test_an_invalid_token_opens_the_circuit_for_that_tenant_only` → `telematics.circuit.opened` (canal por defecto: `channel === null`) y `cycle.failed` / `unauthorized` con `circuit_opened === true`;
    - `test_a_rejected_cursor_restarts_the_feed_and_backfills_the_gap` → `cursor_rejected` con `backfill_requested === true` y `consecutive_failures === 0`;
    - test nuevo: un cursor con `paused_until` en el futuro → `telematics.cycle.paused`;
    - test nuevo: una página con un vehículo sin activo → `unknown_vehicle` en `debug`, y con una ubicación sin latitud → `missing_coordinates` en info;
    - `test_moving_outside_operating_hours_raises_the_event_within_the_cycle` → `assets.after_hours.evaluated` ok con `raised === true` y `raw_event_id`;
    - `test_a_cycle_never_touches_another_tenants_assets` → ninguna entrada lleva el `team_id` ajeno.
  - `IngestVehicleStatsPageTest::test_a_page_costs_a_flat_number_of_queries_whatever_the_fleet_size` sigue verde sin tocar el umbral.
  - `DispatchTelematicsFeedsJobTest` (con `integration`, `polled`, `dispatched`):
    - `test_a_new_integration_gets_one_cycle_per_feed_on_the_telematics_queue` → `telematics.feeds.dispatched` con `dispatched_count === count($this->dispatched())`;
    - `test_a_paused_feed_is_skipped_until_its_pause_ends` → `paused_count === 1`;
    - `test_each_feed_keeps_its_own_cadence` → `not_due_count` y `dispatched_count_by_feed`;
    - el JSON no contiene ningún `team_id`.
  - `BackfillVehicleStatsJobTest::test_the_window_is_capped_at_the_configured_hours` → `window_capped === true`; test nuevo con `from >= until` → `empty_window`.
  - `RaiseAfterHoursMovementTest` (con `makeSchedule`, `makeMovingAsset`, `runJob`):
    - `test_moving_asset_outside_operating_hours_raises_an_internal_event` → `raised` con `speed_kph`, `moving_threshold_kph` y `position_age_s <= freshness_s`;
    - `test_one_event_per_asset_per_local_day` → segunda evaluación `cooldown_active` en `debug` con `last_alert_age_s < cooldown_s`;
    - `test_slow_or_stale_positions_do_not_count_as_movement` → `not_moving` y `stale_position`;
    - `test_inactive_assets_are_ignored` → `asset_inactive`;
    - el JSON no contiene el `name` ni el `code` del activo.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Assets/FollowVehicleStatsFeedJobTest.php tests/Feature/Domains/Assets/IngestVehicleStatsPageTest.php tests/Feature/Domains/Assets/DispatchTelematicsFeedsJobTest.php tests/Feature/Domains/Assets/BackfillVehicleStatsJobTest.php tests/Feature/Domains/Assets/RaiseAfterHoursMovementTest.php` → FAIL.
- [ ] **Step 3: Implement** — `$dropped`, los retornos privados, `evaluate()` y las líneas.
- [ ] **Step 4: Catalog + run**
  - En `### Telemática (telematics)`: amplía `telematics.cycle.completed` y `telematics.cycle.failed` (tabla de reasons con su pausa: `rate_limited` → Retry-After; `provider_unavailable` → backoff exponencial; `cursor_rejected` → reinicio + backfill; `unauthorized` → circuito; `provider_error` → sin pausa) y `telematics.backfill.completed` (reasons `empty_window`, `rate_limited`, `unauthorized`); filas nuevas `telematics.cycle.paused`, `telematics.circuit.opened`, `telematics.points.dropped` (tabla de motivos) y `telematics.feeds.dispatched`.
  - En `### Activos (assets)`: `assets.after_hours.evaluated` (tabla de branches).
  - Corre `tests/Feature/Domains/Assets` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del feed de telemática con pausa, circuito, descartes, despacho y fuera de horario`

---

### Task 7: Barridos, conectividad, sincronización de activos y purgas

**Files:**
- Modify: `app/Domains/Assets/Jobs/DetectOfflineAssetsJob.php`
- Modify: `app/Domains/Assets/Jobs/DetectUnauthorizedStopJob.php`
- Modify: `app/Domains/Assets/Jobs/PollAssetConnectivityJob.php`
- Modify: `app/Domains/Assets/Jobs/PollAllDeviceConnectivityJob.php`
- Modify: `app/Domains/Assets/Exceptions/AssetExternalReferenceConflictException.php`
- Modify: `app/Domains/Assets/Services/AssetSyncHandlerService.php`
- Modify: `app/Domains/Assets/Actions/SyncAssetFromIntegration.php`
- Modify: `app/Domains/Assets/Jobs/SyncAssetsFromProviderJob.php`
- Modify: `app/Contracts/AssetSyncHandler.php`, `app/Contracts/NullImplementations/NullAssetSyncHandler.php`
- Modify: `app/Domains/Integrations/Actions/SyncIntegration.php`
- Modify: `app/Domains/Assets/Jobs/PurgeOldAssetLocationsJob.php`, `Jobs/PurgeOldAssetTelemetryJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Assets/{DetectOfflineAssetsJobTest,DetectUnauthorizedStopJobTest,PollAssetConnectivityJobTest,AssetSyncHandlerServiceTest,SyncAssetFromIntegrationTest,SyncAssetsFromProviderJobTest,PurgeOldAssetLocationsJobTest,PurgeOldAssetTelemetryJobTest}.php`, `tests/Feature/Domains/Integrations/SyncIntegrationTest.php`

**`DetectOfflineAssetsJob`** (recorrido de plataforma; inspecciona cada activo en su tenant):
- `thresholdMinutesFor()` (privado) devuelve `array{minutes: int, source: 'asset_override'|'tenant_setting', in_motion_minutes: int, parked_minutes: ?int, applied: 'in_motion'|'parked'|'disabled'}` con la misma lógica; `parked_minutes` es null cuando no se consultó.
- `inspectAsset()` (privado) devuelve `raised`, `within_threshold`, `disabled` o `already_raised`, y emite dentro del contexto del tenant:
  - `already_raised` → `SystemLog::skipped('assets.offline.skipped', reason: 'already_raised', input: ['team_id' => …, 'asset_id' => $asset->id], result: ['deduplication_key' => $deduplicationKey], debug: true)`;
  - `raised`, tras `queueForProcessing` → `SystemLog::ok('assets.offline.raised', input: ['team_id' => …, 'asset_id' => …], calc: ['silent_minutes' => (int) $lastConnectedAt->diffInMinutes(now()), 'threshold_minutes' => $t['minutes'], 'threshold_source' => $t['source'], 'threshold_applied' => $t['applied'], 'in_motion_minutes' => …, 'parked_minutes' => …, 'was_in_motion' => $wasInMotion, 'location_age_s' => …], result: ['raw_event_id' => $rawEvent->id, 'job_requested' => true])`. `location_age_s` = edad de `latestLocation.recorded_at` respecto de `device_last_connected_at`, o null. Nunca `asset_name`, `asset_code` ni `location`.
- `resolveRecoveredEpisodes()` (privado) devuelve el número resuelto y, por cada episodio cerrado, dentro de `TenantContext::for($event->team_id, …)`: `SystemLog::ok('assets.offline.resolved', input: ['team_id' => $event->team_id, 'normalized_event_id' => $event->id, 'asset_id' => $event->asset_id], calc: ['proof_of_life_source' => 'heartbeat'|'gps_fix', 'silent_minutes' => …], result: ['job_requested' => true])`. `lastProofOfLife()` devuelve además su fuente.
- Al final de `handle`, fuera de todo tenant: `SystemLog::ok('assets.offline_sweep.completed', calc: ['connectivity_freshness_minutes' => self::CONNECTIVITY_FRESHNESS_MINUTES, 'max_episode_age_hours' => self::MAX_EPISODE_AGE_HOURS, 'default_moving_threshold_minutes' => self::DEFAULT_OFFLINE_MINUTES, 'default_parked_threshold_minutes' => self::DEFAULT_PARKED_OFFLINE_MINUTES], result: ['scanned_count' => …, 'raised_count' => …, 'already_raised_count' => …, 'within_threshold_count' => …, 'disabled_count' => …, 'in_motion_count' => …, 'resolved_count' => …])`. Los umbrales efectivos van por activo en `assets.offline.raised`: son por tenant.

**`DetectUnauthorizedStopJob`:**
- `sweepTeam()` (privado, ya dentro del contexto del tenant):
  - `$stopMinutes <= 0` → `SystemLog::skipped('assets.unauthorized_stop_sweep.completed', reason: 'disabled', input: ['team_id' => $teamId], calc: ['stop_minutes' => $stopMinutes])`;
  - al final → `SystemLog::ok('assets.unauthorized_stop_sweep.completed', input: ['team_id' => $teamId], calc: ['stop_minutes' => $stopMinutes, 'freshness_minutes' => self::FRESHNESS_MINUTES, 'max_anchor_hours' => self::MAX_ANCHOR_HOURS, 'realert_hours' => …, 'realert_radius_m' => …, 'geofences_count' => …], result: ['candidates_count' => …, 'inside_geofence_count' => …, 'same_place_count' => …, 'raised_count' => …, 'already_raised_count' => …], debug: $candidates->isEmpty())`. Con candidatos vacíos se emite igual (en debug) antes del `return`, con `geofences_count = null` (no se cargaron).
- `inspectAsset()` (privado) devuelve `inside_geofence`, `same_place`, `raised` o `already_raised`; en `raised`: `SystemLog::ok('assets.unauthorized_stop.raised', input: ['team_id' => $teamId, 'asset_id' => $asset->id], calc: ['stopped_minutes' => (int) $anchor->diffInMinutes(now()), 'stop_minutes' => …], result: ['raw_event_id' => $rawEvent->id, 'job_requested' => true])`. `isSamePlaceAsLastAlert()` no cambia; la distancia no se registra (se calcula desde coordenadas).
- `handle` no emite línea de plataforma: los tenants sin geocercas no se recorren (el `pluck` los excluye).

**`PollAssetConnectivityJob::handle`** — al final, dentro de `TenantContext::for($this->integration->team_id, …)`: `SystemLog::ok('assets.connectivity.polled', input: ['team_id' => …, 'integration_id' => $this->integration->id], result: ['readings_reported_count' => count($readings), 'assets_matched_count' => count($assets), 'readings_unmatched_count' => count($readings) - count($assets), 'without_heartbeat_count' => …])`. `without_heartbeat_count` = lecturas casadas sin `last_connected_at`. Nunca `health_status` crudo.

**`PollAllDeviceConnectivityJob::handle`:** contadores `dispatched_count` y `sync_disabled_count`; al final, fuera de todo tenant: `SystemLog::ok('assets.connectivity.dispatched', result: [...])`.

**Sincronización:**
- `AssetExternalReferenceConflictException::logSkipped(): void` (nuevo, como el de drivers): `SystemLog::skipped('assets.sync.external_id_conflict', reason: 'owned_by_other_tenant', input: ['team_id' => $this->teamId, 'provider_id' => $this->providerId, 'external_id' => LoggableCode::guard($this->externalId)])`. El `team_id` es el que **pidió** el id; el dueño nunca se consulta ni se registra.
- `App\Contracts\AssetSyncHandler::syncFromIntegration` pasa de `void` a `?string`, documentado como "resultado del sync: `created`, `updated`, `conflict`, o null si la implementación no sincroniza". `NullAssetSyncHandler` devuelve `null`. Es la única firma pública que cambia en esta fase (Global Constraints).
- `AssetSyncHandlerService::syncFromIntegration`: devuelve `$asset->wasRecentlyCreated ? 'created' : 'updated'`; en el `catch (AssetExternalReferenceConflictException $e)`: `$e->logSkipped(); return 'conflict';`.
- `SyncAssetFromIntegration::execute`, antes de `return $asset` (dentro del contexto del tenant): `SystemLog::ok('assets.sync.asset_applied', input: ['team_id' => $teamId, 'asset_id' => $asset->id, 'integration_id' => $integrationId], calc: ['branch' => $existingAsset ? 'updated' : 'created', 'devices_reported' => array_key_exists('devices', $assetData) && is_array($assetData['devices'])], debug: true)`. Una por activo: en debug.
- `SyncIntegration::forwardAssets()` (privado) cuenta los retornos y, tras el bucle: `SystemLog::ok('assets.sync.completed', input: ['team_id' => $integration->team_id, 'integration_id' => $integration->id, 'stage' => 'integration_sync'], result: ['assets_reported_count' => count($assets), 'created_count' => …, 'updated_count' => …, 'conflict_count' => …, 'not_handled_count' => …])`.
- `SyncAssetsFromProviderJob::handle`: el `catch` pasa a `catch (AssetExternalReferenceConflictException $e) { $e->logSkipped(); continue; }` y, al final, la misma línea `assets.sync.completed` con `stage = 'provider_job'` y `discovered_count` (hoy `$discovered`). Nadie despacha este job hoy (ver hallazgos); se narra igual porque tiene tests y puede volver a usarse.
- Nunca `name`, `code`, `metadata` ni `external_type` del activo.

**Purgas** (recorridos de plataforma, sin tenant):
- `PurgeOldAssetLocationsJob::handle`, antes del `return`: `SystemLog::ok('assets.purge.completed', input: ['table' => 'asset_location_snapshots'], calc: ['retention_days' => $days, 'retention_source' => $this->retentionDays !== null ? 'argument' : 'config', 'cutoff' => $cutoff->toIso8601String(), 'chunk_size' => self::CHUNK], result: ['removed_count' => $deleted, 'batches_count' => $batches])`. `$days` se extrae a variable; `$batches` cuenta vueltas con `$removed > 0`.
- `PurgeOldAssetTelemetryJob::handle`: igual, con `table = 'asset_telemetry_snapshots'` y `retention_source` `argument` o `constant`.

- [ ] **Step 1: Write the failing tests**
  - `DetectOfflineAssetsJobTest` (con `makeAsset`, `moving`, `parkedSetting`, `runJob`):
    - `test_device_dropping_mid_trip_raises_an_internal_event` → `assets.offline.raised` con `was_in_motion === true`, `threshold_applied === 'in_motion'` y `silent_minutes >= threshold_minutes`; `assets.offline_sweep.completed` con `raised_count === 1`; el JSON no contiene el `name` del activo ni sus coordenadas;
    - `test_parked_vehicle_silent_beyond_the_parked_grace_raises_an_event` → `threshold_applied === 'parked'` y `threshold_minutes === max(parked_minutes, in_motion_minutes)` (rehecho);
    - `test_per_asset_override_beats_the_tenant_threshold` → `threshold_source === 'asset_override'`;
    - `test_one_event_per_silence_episode_no_matter_how_many_ticks` → `assets.offline.skipped` / `already_raised` en debug;
    - `test_zero_threshold_disables_the_watchdog` → `disabled_count === 1`;
    - `test_reconnected_device_resolves_its_offline_episode` → `assets.offline.resolved` con `proof_of_life_source === 'heartbeat'`; `test_episode_raised_before_the_connectivity_feed_resolves_on_a_new_gps_fix` → `gps_fix`;
    - `test_each_tenant_is_inspected_with_its_own_thresholds_and_events` → cada `assets.offline.raised` lleva el `team_id` de su activo y `assets.offline_sweep.completed` no lleva `team_id`.
  - `DetectUnauthorizedStopJobTest` (con `makeGeofence`, `makeStoppedAsset`, `runJob`):
    - `test_prolonged_stop_outside_geofences_raises_a_suspicious_stop_event` → `assets.unauthorized_stop.raised` y `assets.unauthorized_stop_sweep.completed` con `raised_count === 1`;
    - `test_stop_inside_a_known_geofence_is_authorized` → `inside_geofence_count === 1`;
    - `test_zero_threshold_disables_the_detector` → skipped `disabled`;
    - `test_short_stops_do_not_alert` → la línea en `debug` con `candidates_count === 0`;
    - `test_a_new_stop_at_the_place_already_alerted_is_not_alerted_again` → `same_place_count === 1`;
    - `test_it_only_reads_and_writes_the_swept_tenant` → sin `team_id` ajeno.
  - `PollAssetConnectivityJobTest` (con `makeSamsaraIntegration`, `linkAsset`, `fakeGateways`):
    - `test_it_stores_the_device_heartbeat_on_known_assets` → `assets.connectivity.polled` con `readings_reported_count`, `assets_matched_count` y `readings_unmatched_count` que suman;
    - `test_it_never_writes_connectivity_onto_another_tenants_asset` → `assets_matched_count === 0` y sin `team_id` ajeno.
  - `AssetSyncHandlerServiceTest`:
    - `test_it_creates_the_asset_for_its_own_tenant` → retorno `'created'` y `assets.sync.asset_applied` en debug;
    - `test_it_swallows_a_cross_tenant_external_id_collision` → retorno `'conflict'` y `assets.sync.external_id_conflict` con el `team_id` que pidió; el JSON no contiene el id del tenant dueño.
  - `SyncAssetFromIntegrationTest::test_it_updates_existing_asset_on_duplicate_external_id` → `branch === 'updated'`.
  - `SyncAssetsFromProviderJobTest::test_it_skips_assets_claimed_by_another_tenant_and_finishes_the_batch` → `assets.sync.external_id_conflict` y `assets.sync.completed` con `stage === 'provider_job'` y `conflict_count === 1`.
  - `SyncIntegrationTest::test_it_completes_sync_and_records_processed_count` → `assets.sync.completed` con `stage === 'integration_sync'` y `assets_reported_count`. Si el test usa un doble de `AssetSyncHandler` que devuelve `null`, `not_handled_count` cuenta esos casos.
  - `PurgeOldAssetLocationsJobTest::test_it_removes_points_past_retention_and_keeps_the_rest` → `assets.purge.completed` con `removed_count` igual al retorno del job y `cutoff` = `now()->subDays(retention_days)` (rehecho).
  - `PurgeOldAssetTelemetryJobTest`:
    - `test_it_accepts_an_explicit_retention_window` → `retention_source === 'argument'`;
    - `test_it_is_a_no_op_when_nothing_is_stale` → `removed_count === 0`, `batches_count === 0`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Assets/DetectOfflineAssetsJobTest.php tests/Feature/Domains/Assets/DetectUnauthorizedStopJobTest.php tests/Feature/Domains/Assets/PollAssetConnectivityJobTest.php tests/Feature/Domains/Assets/AssetSyncHandlerServiceTest.php tests/Feature/Domains/Assets/SyncAssetFromIntegrationTest.php tests/Feature/Domains/Assets/SyncAssetsFromProviderJobTest.php tests/Feature/Domains/Assets/PurgeOldAssetLocationsJobTest.php tests/Feature/Domains/Assets/PurgeOldAssetTelemetryJobTest.php tests/Feature/Domains/Integrations/SyncIntegrationTest.php` → FAIL.
- [ ] **Step 3: Implement** — los retornos privados, `logSkipped()`, el contrato `?string` y las líneas.
- [ ] **Step 4: Catalog + gate completo**
  - En `### Activos (assets)`: `assets.offline.raised`, `assets.offline.skipped`, `assets.offline.resolved`, `assets.offline_sweep.completed`, `assets.unauthorized_stop.raised`, `assets.unauthorized_stop_sweep.completed`, `assets.connectivity.polled`, `assets.connectivity.dispatched`, `assets.sync.asset_applied`, `assets.sync.external_id_conflict`, `assets.sync.completed`, `assets.purge.completed`.
  - Añade al párrafo "Líneas en debug" todas las líneas en debug que lista Global Constraints.
  - Run: `vendor/bin/pint --dirty --format agent`, la suite completa (`APP_KEY=… php artisan test --compact`) y `npm run types:check && npm run lint:check && npm run format:check` → todo verde.
- [ ] **Step 5: Commit** — `feat: log narrativo de barridos, conectividad, sincronización de activos y purgas`

---

## Cierre de la fase

- [ ] Revisión de aislamiento con el subagente `tenant-isolation-reviewer`. Comprueba que ninguna línea lleve ids de otro tenant, con atención a:
  - los recorridos de plataforma (`AggregateUsageJob` sin team, `GenerateMonthlyInvoicesJob`, `RecordAssetUsageMeters`, `DispatchTelematicsFeedsJob`, `PollAllDeviceConnectivityJob`, `DetectOfflineAssetsJob`, `ReconcileMessagingChargesJob`, purgas): sólo conteos;
  - `assets.sync.external_id_conflict`: el `team_id` que pidió, nunca el dueño;
  - `SetAssetMonitoring::executeMany` con `other_tenant`: sin `asset_id`;
  - las líneas por `DB::afterCommit` llevan su propio `team_id` en `input`.
- [ ] `git push -u origin feat/logging-billing-telematica`, PR con el resumen de códigos, `gh pr checks --watch`, merge con la autorización amplia vigente y `git pull --ff-only` en el checkout principal.
- [ ] Siguiente: plan de la fase 6 (seguridad/acceso, clientes externos y resto de dominios). Incluye `billing.receipt.uploaded`, que esta fase deja fuera.

## Hallazgos que este plan no arregla (quedan visibles en el log)

- **Margen de Twilio incoherente:** la factura usa `terms.messaging_markup_percent ?? tarifa del plan ?? default` (`GenerateInvoiceSnapshotJob.php:146-147`); la estimación ignora la tarifa del plan (`EstimatePeriodCharges.php:71`) y la página de facturación ignora el término del tenant (`CostPlusPricing::markupFor`, `BillingPageController.php:177`). Queda visible en `markup_source`.
- **Cargo finalizado sin medir:** `FinalizeMessagingCharge.php:51-77` marca `finalized_at` antes de medir; si la medición falla, el reconciliador (`whereNull('finalized_at')`) no lo retoma y el costo no se cobra nunca. Queda visible como `billing.messaging_charge.finalized` / `not_metered`.
- **Tope 0 por feature:** `ResolveAssetLimit.php:45-46` devuelve `0` para un `TenantFeature` con `included_quantity = 0`, mientras que en términos y plan `0` significa sin tope.
- **Suscripción "vigente" distinta por sitio:** `CostPlusPricing::markupFor` ordena por `id` sin filtrar estado, `ResolveAssetLimit` por `starts_at` sin filtrar, `TenantCanSend` por `starts_at, id`, y la factura/agregación solo `active`/`past_due`.
- **Factura a demanda de un tenant sin suscripción operativa:** `TenantInvoiceController::generate` encadena `AggregateUsageJob`, que para ese tenant hace `return` (`AggregateUsageJob.php:72-76`), y la factura sale con contadores viejos o en cero. Queda visible como `billing.aggregate.completed` / `no_operational_subscription` + `counter_found = false`.
- **`SyncAssetsFromProviderJob` sin despachador:** nadie lo encola; el sync real va por `SyncIntegration` → `AssetSyncHandler`.
