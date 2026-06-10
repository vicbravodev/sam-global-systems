# ROADMAP — SAM Global Systems

> Documento vivo de next steps para frontend y backend. Actualizado al **2026-06-10 (tarde)** tras auditoría completa del código: PR #58 (rutina nocturna) cerró B6 completo (P1–P8), F4 (drivers), F5a/F5b (notificaciones) y B1a (policies Tenancy).
> Úsalo al inicio de cada sesión para decidir qué sigue. Cuando un ítem se complete, anótale el PR que lo cerró y muévelo a §7. Actualiza este documento en el mismo PR que cierra cada ítem.

---

## 1. Scope del producto (recordatorio)

SAM es una plataforma multi-tenant de gestión de flotas. El flujo central:

```
Proveedor externo (Samsara) → Ingestion (webhooks + safety events feed) → Normalization → Context (enriquecimiento + media + GPS fresco)
  → AI (evaluación texto + multimodal) → Decisions (motor de reglas + falsa alarma) → Incidents (bandeja + SLA + escalación)
  → Automation (workflows) + Notifications (multicanal + on-call) + Audit + Analytics
  → Billing metered local (usage events por tenant; cobro por transferencia, sin Stripe)
```

El producto terminado es: un operador de flota abre SAM, ve su flota en vivo, recibe incidentes triageados por IA **con evidencia visual (footage de cámara) solicitada y evaluada automáticamente**, los gestiona desde una bandeja en tiempo real con SLA y escalación reales, puede **confirmar/descartar incidentes respondiendo un SMS/WhatsApp**, configura reglas y automatizaciones desde la UI, y consulta analytics — todo tenant-scoped, facturado por uso.

---

## 2. Estado actual (auditoría 2026-06-10, verificada en código)

### Backend — ✅ ~98% (specs 01–16 + I1/I2/I3 + B6 P1–P8 completos)

- ~800+ tests verdes. 16 dominios + 3 specs de infra + pipeline de emergencias completo, mergeados (PRs #1–#61).
- **B6 (pipeline de emergencias) CERRADO COMPLETO**: `isResolved` (P1), safety events feed con cursor (P2), media on-demand con `MediaRetrievalAdapter` real de Samsara (P3), GPS fresco en críticos (P4), notificación rica + auto-asignación on-call (P5), SLA real con ack + escalación por niveles (P6), validación de falsa alarma opt-in (P7), vínculo histórico de incidentes (P8).
- IA real operando (B3, PR #50): OpenAI por env, costo/latencia reales, multimodal vía `SdkMediaAssessmentAgent`.
- **La superficie API tenant-scoped está COMPLETA**: CRUD de decision rules, mapping rules, escalation policies, automation workflows, tenant config (settings/AI profile/escalación/schedules/versions), audit logs, analytics reports con download PDF/XLSX, eventos normalizados, media de eventos (`GET/POST /events/{id}/media`). **El gap del producto NO es API: es UI + 4 cabos sueltos de backend (abajo).**

**Gaps backend reales que quedan (en orden de impacto):**

| # | Gap | Detalle |
|---|-----|---------|
| ~~B7~~ | ~~Ejecutores de Automation son stubs~~ | ✅ **CERRADO (PR #63)** — ver §7. Solo `CreateTicket`/`UpdateAssetState` quedan en V2 (§5). |
| ~~B8~~ | ~~El loop multimodal no se cierra~~ | ✅ **CERRADO (PR #63)** — ver §7. |
| ~~B9~~ | ~~Twilio bidireccional~~ | ✅ **CERRADO (PR #63)** — ver §7. |
| ~~B1b~~ | ~~Billing/Branding tenant-facing~~ | ✅ **CERRADO (PR #63)** — ver §7. |
| B2 | Billing local (transferencia) | Facturas/comprobantes por periodo (FileObject listo), estado de pago, activar/desactivar tenant por impago (suspensión super-admin como base). Evaluar retirar Cashier. |
| B3b | Afinado IA | Routing de modelo por tenant (`TenantAIProfileData.preferredModel`) + tuning de prompts. Menor. |

**Nota operativa media:** el auto-request de footage en críticos está gateado por `TenantSetting media.auto_request_on_critical` con **default OFF** (consume cuota). Para verlo operar en dev/demo hay que activarlo por tenant — y la UI para hacerlo es parte de F-TenantConfig.

### Frontend — 🟡 PARCIAL (~50% de las vistas; 6 links muertos en el sidebar)

**Páginas reales conectadas:** `auth/*`, `dashboard` (PR #49), `incidents/index` (bandeja 3 layouts + realtime), `integrations/index` (patrón de referencia, PR #31), `assets/{index,show,map}` (PRs #53–#55), `drivers/{index,show}` (F4), `notifications/index` + `settings/notifications` (F5a/F5b), `settings/roles` (PR #48), `teams/*`, `settings/*`, y consola super-admin completa (`admin/*`, PRs #37/#41–#45).

**Links del OpsSidebar que apuntan a `#` (módulo sin página, API ya lista):**

| Link muerto | API que ya existe |
|-------------|-------------------|

**Gaps de la UI existente (no son páginas nuevas, son deudas de lo ya shippeado):**

- ~~Incidentes — detalle apretado y sin media (F9)~~ — ✅ **CERRADO (PR #63)**: detalle full-page con galería de media, assessments IA, solicitar media e historial relacionado; el panel de la bandeja conserva el JSON y gana CTA "Abrir detalle".
- ~~Notificaciones — falta gestión de canales del tenant (F5c)~~ — ✅ **CERRADO (PR #63)**: tab Canales en Configuración con CRUD, secrets enmascarados y probar canal.

---

## 3. V1 — lo que falta para el producto operativo (orden recomendado)

Patrón obligatorio (el de `integrations/index`, PR #31): controller web dedicado en `routes/web.php` (grupo web = sesión + CSRF; NUNCA `/api` para acciones del navegador), props Inertia tipadas, Wayfinder, policy aplicada, `sam-fetch` + `router.reload({ only: [...] })` para acciones, tests de feature del controller.

### Fase A — Cerrar el corazón del monitoreo automatizado (media + automation + 2-vías)

**B8 — Cerrar el loop multimodal. ✅ COMPLETADO (PR #63)** — ver §7.

**B7 — Ejecutores reales de Automation. ✅ COMPLETADO (PR #63)** — ver §7. `CreateTicket`/`UpdateAssetState` movidos a V2 (§5).

**B9 — Twilio bidireccional. ✅ COMPLETADO (PR #63)** — ver §7. **Fase A cerrada: el monitoreo automatizado opera de punta a punta.**

### Fase B — La bandeja a la altura del pipeline

**F9 — Rediseño del detalle de incidente + media viewer. ✅ COMPLETADO (PR #63)** — ver §7.

**F10 — Página Eventos. ✅ COMPLETADO (PR #63)** — ver §7.

### Fase C — Inteligencia configurable desde la UI

**F11 — Página Reglas. ✅ COMPLETADO (PR #63)** — ver §7.

**F12 — Página Automatizaciones. ✅ COMPLETADO (PR #63)** — ver §7.

**F-TC — Página Configuración del tenant. ✅ COMPLETADO (PR #63)** — ver §7.

### Fase D — Cierre operativo y monetización

**F5c — Gestión de canales de notificación del tenant. ✅ COMPLETADO (PR #63)** — ver §7.

**F13 — Analítica. ✅ COMPLETADO (PR #63)** — ver §7.

**F14 — Auditoría del tenant. ✅ COMPLETADO (PR #63)** — ver §7. **Fase D completa: el sidebar no tiene links muertos.**

**B1b + F7 — Billing + Branding tenant-facing. ✅ COMPLETADO (PR #63)** — ver §7.

**B2 — Billing local (transferencia).** Facturas/comprobantes por periodo, estado de pago, suspensión por impago reutilizando el ciclo de vida del super-admin. Evaluar retirar Cashier/Billable. **Esfuerzo: 2 sesiones.**

---

## 4. Orden global sugerido (fases por sesiones)

| Fase | Ítems | Resultado |
|------|-------|-----------|
| **A. Monitoreo automatizado real** ✅ COMPLETA (PR #63) | ~~B8~~ ✅ → ~~B7~~ ✅ → ~~B9~~ ✅ | El pipeline completo opera solo: detecta, pide footage, lo evalúa, re-decide, notifica, y el operador confirma/descarta desde el teléfono |
| **B. Bandeja a la altura** ✅ COMPLETA (PR #63) | ~~F9~~ ✅ → ~~F10~~ ✅ | El operador VE todo lo que el pipeline produce (footage, visión IA, historial) en una UI espaciosa |
| **C. Inteligencia configurable** ✅ COMPLETA (PR #63) | ~~F-TC~~ ✅ → ~~F11~~ ✅ → ~~F12~~ ✅ | Cero links muertos de inteligencia; el tenant se autoconfigura sin tinker |
| **D. Cierre operativo** ✅ COMPLETA (PR #63) | ~~F5c~~ ✅ → ~~F13~~ ✅ → ~~F14~~ ✅ | Producto operativo completo, sidebar 100% vivo |
| **E. Monetización 🔵 ACTUAL** | ~~B1b+F7~~ ✅ (PR #63) → B2 (billing local) | Listo para facturar |

**Regla de decisión al abrir sesión:** si la fase actual tiene un ítem a medias, continuarlo; si no, tomar el siguiente de la tabla. Un PR por ítem (o sub-ítem), CI verde antes de merge, y actualizar este documento en el mismo PR.

---

## 5. V2 — aplazado deliberadamente (no trabajar sin decisión del usuario)

- **Segundo provider de integración (Geotab/Motive)** — solo con demanda real; valida que el adapter pattern generaliza.
- **Acciones de automation `CreateTicket` / `UpdateAssetState`** — si B7 no encuentra caso de uso claro, quedan aquí (ticketing externo = integración nueva).
- **Contacto directo al driver en panic (SMS/WhatsApp al conductor)** — la infra quedó como `ActionTemplate` opt-in (P5); activarlo es configuración, no código, pero el flujo conversacional con el driver (no solo el operador) es V2.
- **Builder visual avanzado de workflows** (drag & drop) — V1 entrega lista + builder simple.
- **B3b — routing de modelo por tenant + tuning fino de prompts** — el default actual opera bien; afinar con datos de producción.
- **Hardening producción**: revisión de retention jobs (audit/analytics) bajo carga real, replay/backfill masivo de safety events.
- **Reportes programados** (envío por email de PDF/XLSX en schedule) — el render ya existe; falta scheduling + entrega.
- **App móvil / PWA del operador** — hoy el camino móvil es B9 (responder por WhatsApp).

---

## 6. Notas de fiabilidad

- La tabla de estado §3 de `CLAUDE.md` quedó congelada en la auditoría 2026-04-29 y NO refleja B6 ni los PRs #48–#61. Ante duda, **manda el código**, luego este documento, y al final CLAUDE.md.
- El `ROADMAP.md` de la raíz es la **cola de la rutina nocturna**, no este roadmap de producto. Las tareas de la rutina salen de aquí (§3); al generar tareas nuevas respetar §8.7 de CLAUDE.md.
- Para demos del pipeline de media: activar `media.auto_request_on_critical` en el tenant (default off) y verificar que la integración Samsara tenga scope *Read Camera Media*.

---

## 7. Completados (histórico resumido)

- Backend specs 01–16 + I1/I2/I3 y cierre de diferidos: AI SDK, multimodal, PDF/XLSX, drivers de notificación, queries DB-backed, Echo wiring, DemoSeeder (PRs #1–#24).
- UI Incidents con realtime (PRs #27–#30, #40) · UI Integraciones (PR #31).
- Pipeline Samsara real: adapter, firma webhook, replay, seeders, sync periódica, scheduler dedicado (PRs #28, #32, #34, #35, #42, #46).
- Consola super-admin completa (PRs #37, #39, #41, #43–#45): tenants, suscripción/plan, topes con enforcement, features, miembros, operadores, auditoría cross-tenant, impersonación, ciclo de vida.
- **F1** — Página `settings/roles` (CRUD + permisos + cambio de rol) con `RolePolicy` (PR #48).
- **F2** — Dashboard real: KPIs honestos, top-5, stream con decisiones, salud de integraciones, uso del tenant, realtime debounced (PR #49).
- **B3** — IA real en operación: OpenAI por env, pricing/latencia/costo reales en ambos agentes SDK, validado con `samsara:replay` (PR #50).
- **F3** — Assets/Flota completo: lista con filtros + realtime (PR #53), detalle con telemetría/historial/incidentes (PR #54), mapa en vivo MapLibre GL (PR #55).
- **F4** — Drivers completo: `drivers/index` (filtros, asset actual, risk score) y `drivers/{id}` (perfil, contactos, documentos, assignments, status log) — rutina nocturna 2026-06-10, PR #58.
- **F5a/F5b** — Centro de notificaciones con read markers por usuario (`notification_reads`) + preferencias por usuario en settings (PR #58).
- **B1a** — Policies de Tenancy (`SubscriptionPolicy`/`TenantBrandingPolicy`/`TenantFeaturePolicy`) registradas con tests cross-team (PR #58).
- **B6 COMPLETO (P1–P8)** — pipeline de emergencias end-to-end (PRs #56 y #58):
  - **P1** `isResolved`: dedup por estado, `ApplyExternalResolution`, setting `annotate`/`close`.
  - **P2** Safety events feed: `PollSamsaraSafetyEventsJob` con cursor en `sync_state_json`, dedup `safety:{id}:{eventState}`, media inline → attachments, seeders por behaviorLabel, `dismissed` → resolución externa.
  - **P3** Media on-demand: `MediaRetrievalAdapter` en `SamsaraAdapter` (`/cameras/media/retrieval`), `FetchDeferredEventMediaJob` real (poll→download→`FileObject`→multimodal), listener `RequestPanicMediaOnContextBuilt` gateado por TenantSetting (default off), meter `media_requests`.
  - **P4** GPS fresco: `fetchLiveLocation` (timeout 3s), `FetchLiveLocationForEvent` en `BuildEventContext` (solo critical, staleness configurable), fallback `position_stale`.
  - **P5** Notificación rica + on-call: template por incident_type con asset/driver/ubicación/link/media-flag, `ResolveOnCallOperator` (shift rules + timezone + fallback), auto-asignación sin pisar previas.
  - **P6** SLA real: `sla_due_at`/`acknowledged_at`, `CheckIncidentAcknowledgementJob` con delay (sin cron), escalación por niveles de `TenantEscalationConfig`, endpoint + botón ACK en la bandeja.
  - **P7** Falsa alarma: señales `external_resolved`/`parked_at_base`/`repeated_panic_24h`/`media_assessment`, outcome `REQUIRE_HUMAN_REVIEW`, regla seed opt-in, prompt anti-coacción (panic "resuelto" en carretera nunca se degrada).
  - **P8** Vínculo histórico: `GetPriorSimilarIncidents` (cerrados 7d), `PriorSimilarIncidentLink`, signal `has_prior_similar_incident`.
- **T1/T2** — `assertInertia` en 5 páginas sin aserción + authz endpoint-level de incidents API (PR #58).
- **B8** — Loop multimodal cerrado (PR #63): `MediaAssessmentCompleted` (solo assessments nuevos, idempotente) → `ReevaluateEventJob` con trigger `media_arrived` (guards: inline sin decisión, media ya evaluada en otra versión anti-loop, incidente terminal no re-corre); fact `media_assessment` cross-versión de evaluación; guard de contradicción en `ResolveDecisionOutcome` (footage que contradice un evento con decisión accionable previa → `REQUIRE_HUMAN_REVIEW`, nunca auto-cerrar); timeline `media_assessed` por assessment + broadcast `incidents.updated` que la bandeja ahora escucha.
- **B7** — Ejecutores reales de Automation (PR #63): `ExecuteAction` puentea `Send*` al pipeline de Notifications (destinatarios desde el target del step — email/phone directo, user id, rol del team o `recipients` explícitos —, render del `ActionTemplate`, canal fijado con `force_channels` cuyo gate real es el `NotificationChannel` activo del tenant); `AssignIncident`/`Escalate`/`RequestHumanReview` ejecutan las actions reales de Incidents (nueva `RequestIncidentReview` open→in_review); meter `automation_actions` idempotente por ejecución; `CreateTicket`/`UpdateAssetState` → V2.
- **B9** — Twilio bidireccional (PR #63): tabla `notification_reply_tokens` (token corto TTL 24h, reusado por incidente+address); los SMS/WhatsApp de incidente crítico llevan "Responde SI-XXXX / NO-XXXX / ESC-XXXX" (SMS pre-ajustado a 160); webhook `POST /api/webhooks/twilio` valida `X-Twilio-Signature` contra el canal del tenant resuelto por el número `To` (403 si falla); `ProcessInboundReply` ejecuta ack/falsa-alarma/escalar vía actions de Incidents con timeline "via sms/whatsapp" + auditoría `incident.reply.*`; desconocidos/tenant ajeno/sender inesperado → log y silencio; doble respuesta idempotente. **Fase A completa.**
- **B1b+F7** — Facturación y Marca (PR #63): página billing (plan, consumo por meter con barras, funcionalidades, facturas; item del sidebar) + tab Marca en Configuración (nombre/colores/firma + logo a rustfs con FileObject y preview vía temporaryUrl); policies B1a aplicadas. Verificado visualmente.
- **F14** — Página Auditoría del tenant (PR #63): tabs Auditoría (AuditLog paginado con filtros por búsqueda/categoría/actor/fechas) y Eventos de dominio; fix de routing — el grupo /admin se declara antes del wildcard {current_team} para que /{team}/audit no trague /admin/audit. **Fase D completa, sidebar 100% vivo.** Verificado visualmente.
- **F13** — Página Analítica (PR #63): tabs KPIs (snapshot TenantOverview + KpiRecords) y Reportes (generación por formato + ejecuciones con download); rutas web reusando ReportController/ReportExecutionController; sidebar vivo. Verificado visualmente (job real de PDF disparado desde la UI).
- **F5c** — Gestión de canales del tenant (PR #63): tab Canales en Configuración (CRUD por tipo con campos específicos, secrets solo enmascarados hacia el navegador, claves alineadas a los drivers para el cifrado at-rest, eliminar bloqueado en globales) + endpoint 'probar canal' que envía por el driver real. Verificado visualmente.
- **F12** — Página Automatizaciones (PR #63): tabs Workflows (builder simple trigger+pasos con destino, toggle, disparo manual) y Ejecuciones (estado, intentos, error, retry/confirm/cancel); fix en Store/UpdateAutomationWorkflowRequest que descartaba `order`/`target_type`/`target_reference` de los steps (los executors B7 los necesitan); sidebar vivo. Verificado visualmente. **Fase C completa.**
- **F11** — Página Reglas (PR #63): 3 tabs — decisión (seed panic/falsa-alarma visibles con estado, condiciones expandibles, crear con editor JSON validado + docs de operadores, activar/desactivar), mapeo del proveedor (41 reglas Samsara, crear/toggle) y overrides del tenant (crear/eliminar); mutaciones reusando los controllers API como rutas web; sidebar vivo. Verificado visualmente.
- **F-TC** — Página Configuración del tenant (PR #63): 6 tabs (settings del pipeline con los toggles de media/pánico/GPS, perfil IA, políticas de notificación, escalación con editor de steps, horario on-call, versiones con snapshot); mutaciones reusando los controllers API de TenantConfig como rutas web; OpsLayout + link del sidebar vivo; `canManage` por policy. Verificado visualmente (toggle persiste en BD).
- **F10** — Página Eventos (PR #63): `events/index` (filtros tipo/severidad/categoría/estado/fechas + búsqueda, tab "Sin mapear" con contador, paginación 50) y `events/show` (payload normalizado/contexto/crudo, evaluación IA, decisión, incidente vinculado, media, banner unmapped); `NormalizedEventPolicy` nueva sobre `context.view`; link "Eventos" del sidebar vivo. Verificado visualmente.
- **F9** — Detalle full-page de incidente + media viewer (PR #63): `incidents/{incident}` negocia contenido (JSON para el panel de la bandeja, Inertia `incidents/show` para el navegador); grid 3 columnas reutilizando los subcomponentes del panel + `MediaGallery` (thumbnails, lightbox imagen/video con el assessment IA, botón "Solicitar media" → ruta web `incidents/{incident}/media/request`), historial relacionado (P8) y recarga realtime debounced en `incidents.updated`; CTA "Abrir detalle" en el panel de la bandeja. Verificado visualmente con la app corriendo.
