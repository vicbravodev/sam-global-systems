# ROADMAP — SAM Global Systems

> Documento vivo de next steps para frontend y backend. Actualizado al **2026-06-09** (PR #50, B3 cerrado), verificado contra el código (no contra docs viejas).
> Úsalo al inicio de cada sesión para decidir qué sigue. Cuando un ítem se complete, anótale el PR que lo cerró y muévelo a §6. Actualiza este documento en el mismo PR que cierra cada ítem.

---

## 1. Scope del producto (recordatorio)

SAM es una plataforma multi-tenant de gestión de flotas. El flujo central:

```
Proveedor externo (Samsara) → Ingestion (raw events) → Normalization → Context (enriquecimiento + media)
  → AI (evaluación, SDK Laravel AI) → Decisions (motor de reglas) → Incidents (bandeja operativa)
  → Automation (workflows) + Notifications (multicanal) + Audit + Analytics
  → Billing metered local (usage events por tenant; cobro por transferencia, sin Stripe)
```

El producto terminado es: un operador de flota abre SAM, ve su flota en vivo (mapa + telemetría), recibe incidentes generados/triageados por IA, los gestiona desde una bandeja en tiempo real, configura automatizaciones y notificaciones, y consulta analytics — todo tenant-scoped, facturado por uso.

---

## 2. Estado actual (auditoría 2026-06-09, verificada en código)

### Backend — ✅ COMPLETO (specs 01–16 + I1/I2/I3 + diferidos cerrados)

- ~754+ tests verdes. 16 dominios + 3 specs de infra implementados y mergeados (PRs #1–#24).
- **Los diferidos que CLAUDE.md §3 listaba como pendientes YA se cerraron** (PRs #18–#24):
  - IA real: `laravel/ai ^0.6.7` instalado, `SdkEventEvaluationAgent` + `SdkMediaAssessmentAgent` bindeados condicionalmente en `AIServiceProvider` (caen a `Null*` solo si el SDK no está configurado).
  - Multimodal: `ai_media_assessments` + `EvaluateEventMediaJob` shippeados.
  - Reportes: render real PDF (DomPDF) y XLSX en `GenerateReport`.
  - Canal `jobs.{jobId}` con authz real vía modelo `Job` (`routes/channels.php`).
- Pipeline Samsara validado end-to-end con eventos reales: webhook con firma verificada (Base64), replay, seeders, sync periódica de assets/drivers/posiciones (PR #42), scheduler dedicado en compose (PR #46).
- Consola super-admin completa (PRs #37, #41/#43/#44/#45): tenants, suscripción/plan, topes con enforcement, features, miembros, operadores, auditoría cross-tenant, impersonación, ciclo de vida.

**Gaps backend reales que quedan:**

| Gap | Detalle |
|-----|---------|
| Billing/Branding tenant-facing (spec 01 §9) | No existen `BillingController` ni `BrandingController` — el tenant no puede ver su consumo/facturas ni configurar branding. Único endpoint web pendiente del spec 01. |
| Billing local (sin Stripe) | Decisión 2026-06-09: el cobro será por transferencia bancaria — **Stripe queda fuera**. Falta el modelo local: registro de facturas/comprobantes por periodo (FileObject ya existe), estado de pago, y activar/desactivar tenants fácil por impago o factura no subida (la suspensión manual del super-admin, PRs #41–#45, es la base). Evaluar retirar Cashier/Billable al implementarlo. |
| Policies Tenancy | Subscription/TenantBranding/TenantFeature sin policies — crearlas junto con sus controllers (ítem Billing/Branding). |
| Segundo provider | Adapter pattern probado solo con Samsara; Geotab/Motive cuando haya demanda real. |

### Frontend — 🟡 PARCIAL (~35% de las vistas del producto)

**Infra frontend lista:** Echo/Soketi cableado (`resources/js/echo.ts` + hooks `use-team-broadcasts` / `use-echo-channel` / `use-realtime-connection`); la bandeja de incidentes ya consume realtime.

| Página | Estado |
|--------|--------|
| `auth/*` (7 vistas Fortify) | ✅ Real |
| `incidents/index` (bandeja + detalle, 3 layouts, SLA vivo, realtime) | ✅ Conectada al backend (PRs #27–#30, #40) |
| `integrations/index` | ✅ Conectada (PR #31) — **patrón de referencia para páginas nuevas** |
| `admin/*` (tenants, plans, operators, audit) | ✅ Completa (PRs #37, #41–#45) |
| `teams/*`, `settings/{profile,appearance,security}` | ✅ Real (starter kit) |
| `dashboard` | ✅ Conectado (PR #49) — `DashboardController` con KPIs/stream/integraciones/uso reales + realtime |
| `settings/roles/index` | ✅ Conectada (PR #48) — CRUD de roles + cambio de rol de miembros, con `RolePolicy` |

**Vistas del producto que NO existen aún:** Assets/Flota (mapa + lista + detalle), Drivers, Analytics/Reportes, Notificaciones (centro + preferencias + canales), Automation (workflows), TenantConfig (settings del tenant), Billing/Usage + Branding.

---

## 3. NEXT STEPS — Frontend (orden recomendado)

Patrón obligatorio (el de `integrations/index`, PR #31): controller web dedicado en `routes/web.php` (grupo web = sesión + CSRF; NUNCA `/api` para acciones del navegador), props Inertia tipadas, Wayfinder, policy aplicada, `sam-fetch` + `router.reload({ only: [...] })` para acciones, tests de feature del controller.

### F1. ✅ Fix: página `settings/roles/index` faltante — CERRADO (PR #48)
Página creada (CRUD de roles, permisos por módulo, cambio de rol de miembros) + `RolePolicy` nueva: el CRUD no tenía NINGUNA autorización y `MemberRoleController` aceptaba memberships de otros teams (ambos huecos cerrados en el mismo PR). Ver §6.

### F2. ✅ Dashboard real (sustituir `MOCK_DASHBOARD`) — CERRADO (PR #49)
`DashboardController` con KPIs reales (abiertos, críticos, SLA 7d, precisión IA 7d), top-5 incidentes, stream con decisiones del motor, salud de integraciones + eventos 24h, panel nuevo de uso del tenant, y realtime con reloads parciales debounced. Ver §6.

### F3. Assets / Flota — la vista más visible del producto
- `assets/index`: lista con estado, tipo, dispositivo, última posición (datos ya sincronizados desde Samsara, PR #42).
- Mapa en vivo: posiciones + `AssetLocationUpdatedBroadcast` / `AssetStatusChangedBroadcast`. Decidir librería de mapa (Leaflet/MapLibre — **requiere aprobación** por regla de dependencias).
- `assets/{id}`: detalle con telemetría, historial de ubicaciones, incidentes vinculados.
- **Esfuerzo: 3–4 sesiones (separar lista/detalle del mapa en PRs distintos).**

### F4. Drivers
`drivers/index` + `drivers/{id}`: perfil, assignments, documentos (FileObject + `temporaryUrl` listos), risk profile, status log. `DriverPolicy` ya cubre los 6 endpoints. **Esfuerzo: 2 sesiones.**

### F5. Notificaciones en la UI
Campanita/centro (driver Web ya persiste en DB) + preferencias por usuario (`NotificationPreference`) + gestión de canales del tenant (Slack/Twilio/FCM, secrets cifrados con `EncryptedChannelConfigCast`). **Esfuerzo: 2 sesiones.**

### F6. Analytics
Dashboards de KPIs (`KpiRecord` + snapshots de `BuildAnalyticsSnapshotJob`), definición/ejecución de reportes con download PDF/XLSX (el render backend ya es real). **Esfuerzo: 2–3 sesiones.**

### F7. Billing + Branding del tenant (en pareja con B1)
Página de consumo/uso (meters, agregados diarios, invoice snapshots) y settings de branding. Depende de B1 (controllers). **Esfuerzo: 2 sesiones.**

### F8. TenantConfig + Automation UI (cola de prioridad)
Settings del tenant (AI profile, políticas de notificación/escalación, rule overrides, schedules — spec 16 backend completo) y lista/builder de workflows (puede empezar read-only). **Esfuerzo: 3+ sesiones; vistas de power-user, al final.**

---

## 4. NEXT STEPS — Backend (orden recomendado)

### B1. Billing + Branding tenant-facing (spec 01 §9) — único endpoint web pendiente
`BillingController` (uso, meters, agregados, invoice snapshots del team actual) + `BrandingController` (logo vía FileObject, colores), con policies de Tenancy (Subscription/TenantBranding/TenantFeature) que hoy no existen. Alimenta F7. **Esfuerzo: 1–2 sesiones.**

### B2. Billing local-only (cobro por transferencia) — re-scoped 2026-06-09, Stripe CANCELADO
El cobro será por transferencia bancaria; todo lo relacionado a Stripe queda fuera del roadmap. Modelo local: facturas/comprobantes subidos por periodo (FileObject + `temporaryUrl` listos), estado de pago por tenant, y **activar/desactivar tenants de forma sencilla** cuando no paguen o no hayan subido su factura (reutilizar la suspensión/ciclo de vida del super-admin, PRs #41–#45). Los usage meters locales (`ai_calls`, `ai_tokens_*`, assets) siguen siendo la fuente del consumo a cobrar. Al implementar: evaluar retirar Cashier/Billable del código. **Esfuerzo: 2 sesiones.**

### B3. ✅ IA real en operación — CERRADO (PR #50)
Provider OpenAI configurable por env (`AI_DEFAULT`/`OPENAI_API_KEY`/`OPENAI_TEXT_MODEL`), binding config:cache-safe, latencia real (hrtime) y costo real (`ModelPricing` + `ai.pricing`) en ambos agentes SDK, validado end-to-end con `samsara:replay` (facturación `ai_calls`/`ai_tokens_in`/`ai_tokens_out` verificada). Ver §6. Follow-up pendiente: routing de modelo por tenant (`TenantAIProfileData.preferredModel`) y afinado fino de prompts.

### B4. Endpoints de soporte para las vistas nuevas
A demanda de F3–F6: lecturas que falten (historial de posiciones paginado, agregados para dashboard, etc.). Siempre Queries DB-backed en el dominio dueño (patrón `Db*MetricsQuery`), nunca lógica en el controller. **Esfuerzo: incremental.**

### B5. Hardening / expansión (cola de prioridad)
Segundo provider de integración (Geotab/Motive) para validar que el adapter pattern generaliza — solo con demanda real. Revisión de retention jobs (audit/analytics) en producción.

---

## 5. Orden global sugerido (fases por sesiones)

| Fase | Ítems | Resultado |
|------|-------|-----------|
| **1. Quick wins ✅** | F1 (roles rota) → F2 (dashboard real) | App sin páginas rotas ni mocks |
| **2. IA encendida ✅** | B3 (IA real operando) | Incidentes triageados por IA de verdad — demo del corazón del producto |
| **3. Flota visible** | F3 (assets + mapa) → F4 (drivers) | El operador ve su flota en vivo — demo-able a clientes |
| **4. Cierre operativo** | F5 (notificaciones UI) → F6 (analytics) | Producto operativo completo |
| **5. Monetización** | B1+F7 (billing/branding) → B2 (billing local por transferencia) | Listo para facturar |
| **6. Power-user** | F8 (tenant config / automation UI) → B5 | Configurabilidad avanzada |

**Regla de decisión al abrir sesión:** si la fase actual tiene un ítem a medias, continuarlo; si no, tomar el siguiente de la tabla. Un PR por ítem (o sub-ítem), CI verde antes de merge, y actualizar este documento en el mismo PR.

> ⚠️ Nota de fiabilidad: la tabla de estado §3 de `CLAUDE.md` quedó congelada en la auditoría 2026-04-29 y su lista de "diferidos" estaba obsoleta (se corrigió el 2026-06-09). Ante duda, verificar contra el código, no contra docs.

---

## 6. Completados (histórico resumido)

- Backend specs 01–16 + I1/I2/I3 y cierre de diferidos: AI SDK, multimodal, PDF/XLSX, drivers de notificación, queries DB-backed, Echo wiring, DemoSeeder (PRs #1–#24).
- UI Incidents con realtime (PRs #27–#30, #40) · UI Integraciones (PR #31).
- Pipeline Samsara real: adapter, firma webhook, replay, seeders, sync periódica, scheduler dedicado (PRs #28, #32, #34, #35, #42, #46).
- Consola super-admin completa (PRs #37, #39, #41, #43–#45).
- **F1** — Página `settings/roles` (CRUD de roles + permisos por módulo + cambio de rol de miembros) con `RolePolicy` nueva cerrando el hueco de autorización del CRUD y el scoping cross-team de `MemberRoleController` (PR #48).
- **F2** — Dashboard con datos reales: `DashboardController` (KPIs honestos incl. SLA 7d y precisión IA con la fórmula de `EvaluateAIEffectiveness`, top-5, stream con decisiones, integraciones + eventos 24h, uso del tenant) + queries nuevas en Incidents/Normalization + realtime debounced. Fase 1 (quick wins) completa: app sin páginas rotas ni mocks (PR #49).
- **B3** — IA real en operación: `AI_DEFAULT`/`OPENAI_API_KEY`/`OPENAI_TEXT_MODEL` en env (default `gpt-5.4`), `isAiSdkConfigured()` leyendo config en vez de `env()` (config:cache-safe), `ModelPricing` + sección `ai.pricing` (USD/1M tokens, fallback por prefijo para ids versionados), latencia real con hrtime y `cost_estimate` real en `SdkEventEvaluationAgent`/`SdkMediaAssessmentAgent`, tests nuevos (pricing, media via SDK, bindings) y pipeline validado con `samsara:replay` + verificación de `ai_inference_logs` y `usage_events`. Fase 2 (IA encendida) completa (PR #50).
