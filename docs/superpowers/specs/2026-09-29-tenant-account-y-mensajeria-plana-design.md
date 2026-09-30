# Cuenta única del tenant + mensajería con tarifa plana — diseño

**Fecha:** 2026-09-29 · **Estado:** diseño aprobado en lo general por el usuario (partes 1 y decisiones de producto); partes 2–4 son la propuesta del autor y se validan al escribir el plan.
**Contexto:** hallazgos de la fase 5 del logging narrativo (PR #157). Pre-producción: cambios que rompen son aceptables, sin shims de compatibilidad.

## 1. Problema

La configuración comercial de un tenant vive en cinco sitios (`plans` + `billing_rates`, `team_subscriptions`, `tenant_features`, `tenant_billing_terms`, `teams` sin estado) y se resuelve distinto según quién la lea:

- El tope de activos es una cascada de tres tablas (`ResolveAssetLimit`); un `TenantFeature.limits_json.included_quantity = 0` significa "tope 0" mientras que en términos/plan `0` es "sin tope" (bug F).
- "La suscripción vigente" se elige de seis maneras distintas (orden por `starts_at` o por `id`, con o sin filtro de estado) (bug G).
- El margen de Twilio que se factura (`terms → plan → default`) no es el que se muestra (`CostPlusPricing::markupFor` ignora los términos) (bug E).
- La factura a demanda de un tenant sin suscripción operativa encadena un `AggregateUsageJob` que hace `return` y sale con contadores viejos (bug B).
- `FinalizeMessagingCharge` marca `finalized_at` antes de medir: si la medición falla, el costo no se cobra nunca (bug A).
- El precio de Twilio se asume en USD (bug C).
- Un tenant suspendido puede iniciar sesión; el estado no se aplica de forma uniforme.

## 2. Decisiones de producto (usuario, 2026-09-29)

1. **Mensajería con tarifa plana por canal**, igual para todos los tenants: WhatsApp por mensaje, SMS por mensaje, llamada por llamada (sin importar duración). Email y web siguen gratis.
2. **Todo envío se cobra desde el primero.** No hay paquete incluido.
3. **Límite mensual combinado por cuenta** (mensajes + llamadas), fijado por el super-admin. **Tope suave**: al cruzarlo se sigue enviando, se registra el exceso y se avisa al admin del tenant y al super-admin; el envío se cobra igual.
4. **Sin planes.** Se eliminan por completo; los valores iniciales de un tenant nuevo salen de `config/billing.php`.
5. **Configuración única en el tenant**, editada sólo por super-admin: estado, topes, features y precios negociados.
6. **Cuatro estados** (ver §4.1). "Expirada" desaparece.
7. **Precios del tracto-día negociables por tenant** (precio unitario, escalones, mínimo, uso justo de IA y su exceso). La mensajería no se negocia.
8. El costo real de Twilio **se sigue registrando internamente** (margen visible para el super-admin), pero no se le cobra al cliente.

## 3. Modelo de datos (parte 1 — aprobada)

### 3.1 Tabla `tenant_accounts` (una fila por team, `team_id` único, creada con el tenant)

| Grupo | Columnas | Vacío (`null`) significa |
|---|---|---|
| Estado | `status` (`active` \| `past_due` \| `suspended` \| `canceled`), `status_changed_at`, `status_reason` (texto interno del super-admin) | — (no nulo) |
| Topes | `asset_cap`, `messaging_monthly_limit`, `ai_monthly_quota` (consultas del copiloto) | sin tope. Un `0` explícito no se admite en validación (evita la ambigüedad del bug F) |
| Precios negociados | `unit_price`, `volume_tiers_json`, `min_billable_assets`, `ai_fair_use_per_asset`, `ai_overage_unit_price` | valor de `config/billing.php` |
| Features | `features_json` (`{módulo: bool}`) | módulo ausente = encendido (comportamiento actual) |
| Notas | `notes` | — |

Modelo `App\Domains\Tenancy\Models\TenantAccount` con `BelongsToTenant`. Relación `Team::account()` (hasOne).

### 3.2 Se eliminan

Tablas `plans`, `billing_rates`, `team_subscriptions`, `tenant_features`, `tenant_billing_terms` (con migración de datos a `tenant_accounts`), sus modelos, `ChangeTenantPlan`, `UpdatePlanLimits`, `UpdateSubscriptionStatus`, `SetTenantFeature`, `UpdateTenantBillingTerms`, `SubscriptionStatus`, `SubscriptionPolicy`, `CostPlusPricing`, `Admin/PlanController`, `Admin/TenantSubscriptionController`, `Admin/TenantFeatureController`, `Admin/TenantBillingTermsController` y la página `admin/plans`.

`currency` y `fx_usd_rate` quedan sólo en config (una moneda de facturación por plataforma).

### 3.3 Config

`config/billing.php` gana:

```php
'messaging_fees' => [          // por envío, en `currency`, igual para todos
    'whatsapp' => (float) env('BILLING_FEE_WHATSAPP', 0),
    'sms'      => (float) env('BILLING_FEE_SMS', 0),
    'voice'    => (float) env('BILLING_FEE_VOICE', 0),
],
'defaults' => [                // valores iniciales de un tenant nuevo
    'messaging_monthly_limit' => (int) env('BILLING_DEFAULT_MESSAGING_LIMIT', 500),
    'asset_cap' => null,
    'ai_monthly_quota' => null,
],
```

`services.twilio.markup_percent` deja de usarse para cobrar.

### 3.4 Lectura única

`App\Domains\Tenancy\Actions\ResolveTenantAccount::for(int $teamId): TenantAccountData` devuelve los valores efectivos (defaults aplicados) y, por cada valor, su origen (`tenant` | `config`) para el log (`billing.account.resolved`). Reemplaza `ResolveBillingTerms`, `ResolveAssetLimit`, `BillingTermsData`, todas las selecciones de "suscripción vigente" y `CostPlusPricing::markupFor`. Memoizado por request/job y por `team_id`.

## 4. Comportamiento (parte 2 — propuesta)

### 4.1 Estado

| Estado | Opera (pipeline, alertas, mensajes) | Acumula tracto-días | Se factura | Entra a la app |
|---|---|---|---|---|
| `active` | sí | sí | sí | sí |
| `past_due` | sí, con aviso de pago pendiente | sí | sí | sí |
| `suspended` | no; **las emergencias siguen abriendo incidente** y notificando | no (salvo el día de una emergencia, que se cobra como hoy) | sí, lo consumido | sólo facturación |
| `canceled` | no | no | sólo el último periodo | no |

- `TenantCanSend::blockedReason` lee `tenant_accounts.status` (razones `account_suspended`, `account_canceled`); emergencias exentas.
- `AuthorizeAction::checkSubscriptionAccess` → `checkAccountAccess` sobre el mismo estado.
- Middleware nuevo en el grupo autenticado del tenant: `suspended` sólo deja pasar las rutas de facturación; `canceled` cierra sesión con mensaje.
- Agregación, factura mensual y analytics recorren tenants en `active`, `past_due` y `suspended` (lo consumido se factura); `canceled` sólo en el periodo en que se canceló. **Arregla B:** la factura a demanda del super-admin agrega siempre el tenant pedido, sin filtro de estado.
- `features_json` sustituye a `tenant_features` en `AuthorizeAction::checkFeatureAccess`.

### 4.2 Topes

- `asset_cap`: el mismo tope suave de hoy (`SetAssetMonitoring` audita y despacha `UsageLimitExceeded`), leído de un solo sitio.
- `ai_monthly_quota`: la cuota del copiloto (`CopilotQuotaQuery`) sale de aquí.
- `messaging_monthly_limit`: ver §5.3.

## 5. Mensajería con tarifa plana (parte 3 — propuesta)

### 5.1 Qué se cobra

Cada envío que el proveedor **acepta** (el SID existe) cuenta una unidad en su meter por canal, que ya existe desde PR #112: `whatsapp_messages`, `sms_messages`, `voice_notification_calls`. `event_key` idempotente por delivery (`messaging_send:{delivery_id}`). Un reintento o un fallback a otro canal que también sale es otro envío y se cobra; un fallo antes de salir (sin SID) no se cobra. Los OTP de acceso (`otp_sms_sent`, `voice_calls`) quedan fuera del cobro al cliente (costo de plataforma), sólo se miden.

### 5.2 Factura

Línea por canal: `unidades × messaging_fees[canal]`, redondeo a centavos igual que el resto de líneas. Se retiran `messaging_cost_micros` como línea de cobro, `AssetDayPricing::messagingLine`, `cost_plus` y el `fx_usd_rate` de la mensajería. `EstimatePeriodCharges` y la página de facturación usan la misma fórmula (se acaba la incoherencia E).

### 5.3 Límite mensual

Suma de las tres unidades del mes contra `messaging_monthly_limit`. En el primer cruce del mes: `UsageLimitExceeded` (ya existe para activos) → aviso al admin del tenant y a los super-admins, una sola vez por periodo (dedupe por `team_id` + meter + periodo). Nunca bloquea.

### 5.4 Costo interno de Twilio

`messaging_charges` + reconciliador se conservan como **contabilidad interna** (margen por tenant en la consola super-admin), no como facturación. Arreglos:
- **A:** `finalized_at` y la medición del costo interno en la misma transacción; si la medición falla, el cargo no queda finalizado y el reconciliador lo retoma.
- **C:** `price_unit` distinto de USD se registra tal cual con su moneda y se marca `currency_mismatch` (log `degraded`); no se convierte en silencio.

## 6. Migración y consola (parte 4 — propuesta)

- Migración de datos: por cada team, crear su `tenant_accounts` con estado = el de su suscripción más reciente (`expired`/`trialing` → `canceled`/`active`), `asset_cap` = el tope efectivo que resuelve hoy `ResolveAssetLimit`, precios = `tenant_billing_terms`, features = `tenant_features.enabled`. Después se eliminan las tablas viejas. Requiere `sail artisan migrate` en dev.
- `CreateTenant` crea la fila con `config('billing.defaults')`; ya no recibe plan.
- Consola super-admin (`admin/tenants/show`): una sola sección "Cuenta" con estado (acciones suspender/reactivar/cancelar con motivo), topes, precios negociados (con el default visible al lado) y features. Se retira la página de planes y el selector de plan.
- Página de facturación del tenant: tarifas de mensajería y consumo del mes vs. límite.
- Seeders (`sam:showcase`, demo) y factories actualizados.

## 7. Logging, pruebas y seguridad

- Mismo estándar narrativo de las fases 1–5: `billing.account.resolved` (cada valor con su origen), `billing.account.status_changed` (afterCommit, actor, estado anterior/nuevo, sin el texto libre del motivo), `billing.messaging_send.recorded` / `duplicate_ignored`, `billing.messaging_limit.crossed`, `billing.invoice_line.calculated` con `strategy = flat_fee`, `billing.messaging_charge.finalized` como contabilidad interna. Catálogo `docs/SAM/logging.md` y `LoggingConventionsTest`.
- Tests: factura recomputable al centavo con las tarifas planas; límite cruzado una sola vez por periodo; emergencias en tenant suspendido; login bloqueado en cancelado; factura a demanda con contadores frescos (B); bug A con medición fallida → cargo retomado; migración de datos (fixtures de cada estado viejo); aislamiento (`assertNoTenantLeak`) en `ResolveTenantAccount`, recorridos de facturación y consola.
- Revisión `tenant-isolation-reviewer` antes del PR.

## 8. Fuera de alcance

Cobro automático, pasarela de pago, multi-moneda por tenant, paquetes comerciales (se podrían añadir luego como plantillas de alta sin cambiar este modelo), fase 6 del logging.

## 9. Orden sugerido de implementación

1. `tenant_accounts` + `ResolveTenantAccount` + migración de datos, con todos los lectores migrados (sin cambiar cobro).
2. Estado: `TenantCanSend`, `AuthorizeAction`, middleware de sesión, recorridos de facturación (arregla B).
3. Mensajería plana: medición por envío, línea de factura, estimación, página de facturación, límite (arregla E).
4. Contabilidad interna de Twilio (arregla A y C).
5. Consola super-admin y retiro de planes/suscripciones/features/términos.
