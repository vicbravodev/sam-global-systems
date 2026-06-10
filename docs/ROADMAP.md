# ROADMAP — SAM Global Systems

> Documento vivo de next steps para frontend y backend. Actualizado al **2026-06-09** (post-merge PR #46).
> Úsalo al inicio de cada sesión para decidir qué sigue. Cuando un ítem se complete, márcalo con el PR que lo cerró y muévelo a la sección de completados.

---

## 1. Scope del producto (recordatorio)

SAM es una plataforma multi-tenant de gestión de flotas. El flujo central:

```
Proveedor externo (Samsara) → Ingestion (raw events) → Normalization → Context (enriquecimiento + media)
  → AI (evaluación) → Decisions (motor de reglas) → Incidents (bandeja operativa)
  → Automation (workflows) + Notifications (multicanal) + Audit + Analytics
  → Billing metered por Stripe (usage events por tenant)
```

El producto terminado es: un operador de flota abre SAM, ve su flota en vivo (mapa + telemetría), recibe incidentes generados/triageados por IA, los gestiona desde la bandeja, configura automatizaciones y notificaciones, y consulta analytics — todo tenant-scoped, facturado por uso.

---

## 2. Estado actual (auditoría 2026-06-09)

### Backend — ✅ COMPLETO (specs 01–16 + I1/I2/I3)

- ~754 tests verdes. 16 dominios + 3 specs de infra implementados y mergeados.
- Pipeline Samsara validado end-to-end con eventos reales (webhook con firma verificada, replay, seeders, sync periódica de assets/drivers/posiciones, scheduler dedicado en compose).
- Consola super-admin completa (PRs #41/#43/#44/#45): tenants, suscripción/plan, topes con enforcement, features, miembros, operadores, auditoría cross-tenant, impersonación, ciclo de vida (soft-delete).

**Diferidos backend explícitos (los únicos gaps de backend):**

| Código | Qué falta | Dónde |
|--------|-----------|-------|
| `SPEC-09-SDK-DEFERRED` | IA real: `Laravel\AI\Agent`, `ai_conversation_links`, streaming SSE, `AIEvaluationProgressBroadcast`. Hoy evalúa `NullEventEvaluationAgent` (determinístico). | `app/Domains/AI/AIServiceProvider.php` |
| `SPEC-09-MULTIMODAL-DEFERRED` | `ai_media_assessments`, `EvaluateEventMultimodally`, `EvaluateEventMediaJob`. El media pipeline de Context (spec 08 PR #2) ya provee los `EventMediaContext` de entrada. | `app/Contracts/NullImplementations/NullMediaAssessmentAgent.php` |
| `SPEC-15-PDF-DEFERRED` | Render real PDF/XLSX de reportes. | `app/Domains/Analytics/Actions/GenerateReport.php` |
| Authz canal `jobs.{jobId}` | Hoy `$user !== null`; requiere modelo Job dedicado. | `routes/channels.php` |
| Policies Tenancy | Subscription/TenantBranding/TenantFeature sin policies (no hay controllers tenant-facing aún). | — |

### Frontend — 🟡 PARCIAL (~30% de las vistas del producto)

**Páginas existentes** (`resources/js/pages/`):

| Página | Estado |
|--------|--------|
| `auth/*` (7 vistas Fortify) | ✅ Real |
| `incidents/index` (bandeja + panel detalle, 3 layouts, SLA vivo) | ✅ Conectada al backend (PRs #27–#30, #40) |
| `integrations/index` | ✅ Conectada al backend (PR #31) — **patrón de referencia para páginas nuevas** |
| `admin/*` (tenants, plans, operators, audit) | ✅ Completa (PRs #37, #41–#45) |
| `teams/*`, `settings/{profile,appearance,security}` | ✅ Real (starter kit) |
| `dashboard` | ❌ **100% datos mock** (`MOCK_DASHBOARD` hardcodeado, ruta `Route::inertia` sin controller) |
| `settings/roles/index` | ❌ **ROTA**: `RoleController@index` renderiza una página que NO existe en `resources/js/pages` |

**Vistas del producto que NO existen aún:** Assets/Flota (mapa + lista + detalle), Drivers, Analytics/Reportes, Notificaciones (centro + preferencias), Automation (workflows), TenantConfig (settings del tenant), Billing/Usage (consumo del tenant).

**Infra frontend pendiente:** wiring de Echo (`resources/js/echo.ts`) — el backend ya emite 7+ eventos broadcast que nadie escucha en el cliente.

---

## 3. NEXT STEPS — Frontend (orden recomendado)

El backend ya expone casi todo; el valor ahora está en el frontend. Patrón a seguir en todas: el de `integrations/index` (PR #31) — controller web dedicado, props Inertia tipadas, Wayfinder, policy aplicada, tests de feature del controller.

### F1. 🔥 Fix inmediato: página `settings/roles/index` faltante
La ruta `GET /{team}/settings/roles` existe y `RoleController` la renderiza, pero el `.tsx` no existe → error en runtime. Crear la página (CRUD de roles + asignación de permisos + cambio de rol de miembros; los endpoints POST/PUT/DELETE ya existen). **Esfuerzo: 1 sesión.**

### F2. Dashboard real (sustituir `MOCK_DASHBOARD`)
- Crear `DashboardController` (reemplaza el `Route::inertia`) que agregue: contadores de incidentes por estado/prioridad (existe `DbIncidentMetricsQuery`), salud de integraciones, últimos eventos normalizados, uso del tenant (UsageMeter).
- Mantener el diseño actual de `dashboard.tsx`; solo cambiar la fuente de datos.
- **Esfuerzo: 1–2 sesiones.**

### F3. Wiring de Echo + tiempo real
- Configurar `resources/js/echo.ts` (Soketi, `VITE_PUSHER_HOST=localhost` ya documentado en memoria de entorno).
- Suscribir: bandeja de incidentes (`accounts.{teamId}` → `IncidentCreated`/`DecisionMade`), presencia en `incidents.{incidentId}`, `UsageUpdatedBroadcast` en dashboard.
- Esto convierte la bandeja en una bandeja viva — diferenciador clave del producto.
- **Esfuerzo: 1–2 sesiones.**

### F4. Assets / Flota (la vista más visible del producto)
- `assets/index`: lista con estado, tipo, dispositivo, última posición (datos ya sincronizados desde Samsara por PR #42).
- Mapa en vivo: posiciones desde `AssetLocation` + updates por `AssetLocationUpdatedBroadcast` (depende de F3). Decidir librería de mapa (Leaflet/MapLibre — requiere aprobación por regla de dependencias).
- `assets/{id}`: detalle con telemetría, historial de ubicaciones, eventos/incidentes vinculados.
- **Esfuerzo: 3–4 sesiones (separar lista/detalle del mapa).**

### F5. Drivers
- `drivers/index` + `drivers/{id}`: perfil, assignments, documentos (FileObject + temporaryUrl ya existen), risk profile, status log. `DriverPolicy` ya cubre los 6 endpoints.
- **Esfuerzo: 2 sesiones.**

### F6. Notificaciones en la UI
- Campanita/centro de notificaciones (driver Web ya persiste notificaciones en DB) + preferencias por usuario (`NotificationPreference`) + gestión de canales del tenant (config de Slack/Twilio/FCM con secrets cifrados).
- **Esfuerzo: 2 sesiones.**

### F7. Analytics
- Dashboards de KPIs (`KpiRecord`, snapshots ya generados por `BuildAnalyticsSnapshotJob`), definición/ejecución de reportes. El download de PDF/XLSX queda bloqueado por `SPEC-15-PDF-DEFERRED` (B3) — la UI puede listar ejecuciones mientras tanto.
- **Esfuerzo: 2–3 sesiones.**

### F8. TenantConfig + Automation UI (cola de prioridad)
- Settings del tenant: AI profile, políticas de notificación/escalación, rule overrides, schedule profiles (spec 16 — backend completo).
- Builder/lista de workflows de automation (puede empezar read-only).
- **Esfuerzo: 3+ sesiones; hacer al final, son vistas de power-user.**

---

## 4. NEXT STEPS — Backend (orden recomendado)

### B1. 🔥 Spec 09 PR #2 — IA real (`SPEC-09-SDK-DEFERRED`)
El corazón del producto sigue siendo un stub determinístico. Integrar el SDK de IA de Laravel (`Laravel\AI\Agent`), tabla `ai_conversation_links`, streaming SSE y `AIEvaluationProgressBroadcast`. Mantener `NullEventEvaluationAgent` como fallback de tests/config. **Es el next step de mayor valor de todo el proyecto** junto con F2–F4. **Esfuerzo: 2–3 sesiones.**

### B2. Spec 09 multimodal (`SPEC-09-MULTIMODAL-DEFERRED`)
Después de B1: `ai_media_assessments`, `EvaluateEventMultimodally`, `EvaluateEventMediaJob` consumiendo los `EventMediaContext` que el pipeline de media de Context ya produce (dashcam clips de Samsara). **Esfuerzo: 2 sesiones.**

### B3. Spec 15 PR #2 — Render PDF/XLSX (`SPEC-15-PDF-DEFERRED`)
Render real en `GenerateReport` (elegir lib: dompdf/laravel-excel — requiere aprobación de dependencias). Desbloquea el download en F7. **Esfuerzo: 1–2 sesiones.**

### B4. Endpoints de soporte para las vistas nuevas
A medida que F4–F7 avanzan, pueden faltar endpoints web de lectura (p. ej. historial de posiciones paginado para el mapa, métricas agregadas para dashboard). Crearlos en el dominio dueño con Queries DB-backed (patrón `Db*MetricsQuery`), nunca lógica en el controller. **Esfuerzo: incremental, por demanda del frontend.**

### B5. Billing end-to-end con Stripe real
Los usage events y agregados existen; falta validar el ciclo completo contra Stripe test mode: sync de meters a Stripe, invoice snapshots, webhook de Stripe (Cashier). Más página de Billing del tenant (frontend). **Esfuerzo: 2–3 sesiones.**

### B6. Hardening menor (cola de prioridad)
- Authz real del canal `jobs.{jobId}` (requiere modelo Job dedicado).
- Policies de Tenancy (Subscription/TenantBranding/TenantFeature) cuando existan sus controllers tenant-facing.
- Segundo provider de integración (Geotab/Motive) para validar que el adapter pattern generaliza — solo cuando haya demanda real.

---

## 5. Orden global sugerido (fases por sesiones)

| Fase | Ítems | Resultado |
|------|-------|-----------|
| **1. Quick wins** | F1 (roles page rota) → F2 (dashboard real) | App sin páginas rotas ni mocks |
| **2. Producto vivo** | F3 (Echo) → B1 (IA real) | Bandeja en tiempo real con evaluaciones de IA reales |
| **3. Flota visible** | F4 (assets + mapa) → F5 (drivers) | El operador ve su flota — demo-able a clientes |
| **4. Cierre operativo** | F6 (notificaciones UI) → B2 (multimodal) → B3+F7 (analytics+PDF) | Producto operativo completo |
| **5. Power-user & monetización** | F8 (tenant config/automation UI) → B5 (Stripe e2e) → B6 (hardening) | Listo para facturar |

**Regla de decisión rápida al abrir sesión:** si la fase actual tiene un ítem a medias, continuarlo; si no, tomar el siguiente de la tabla. Un PR por ítem (o sub-ítem si es grande), CI verde antes de merge, actualizar este documento en el mismo PR que cierra cada ítem.

---

## 6. Completados (histórico resumido)

- Backend specs 01–16 + I1/I2/I3 (PRs #1–#24) · UI Incidents (PRs #27–#30, #40) · UI Integraciones (PR #31) · Pipeline Samsara real: adapter, firma, replay, seeders, sync periódica, scheduler (PRs #28, #32, #34, #35, #42, #46) · Consola super-admin completa (PRs #37, #39, #41, #43–#45).
