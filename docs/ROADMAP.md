# ROADMAP — SAM Global Systems

> Documento vivo de next steps para frontend y backend. Actualizado al **2026-06-11** tras auditoría completa del código (suite 1196 tests verde, 4308 assertions): **V1 está cerrado completo** (specs 01–16, B6 P1–P8, B7/B8/B9, todas las vistas, billing local, UI enterprise PR #65). Este documento define **V2 — "SAM Monitorista"**: el protocolo default afinado por SAM.
> Úsalo al inicio de cada sesión para decidir qué sigue. Cuando un ítem se complete, anótale el PR que lo cerró y muévelo a §7. Actualiza este documento en el mismo PR que cierra cada ítem.

---

## 1. North star del producto (V2)

SAM no es un dashboard de flotas: es un **monitorista virtual para flotas en México**. Su valor es doble:

1. **Eliminar el ruido**: cuando llega una alerta (botón de pánico, safety event), SAM investiga solo — pide footage, analiza cada imagen y describe lo que se ve, correlaciona los safety events alrededor del momento (frenadas bruscas, maniobras evasivas), detecta pasajeros, revisa el historial — y si la evidencia apunta a falso positivo, lo degrada/descarta documentando por qué.
2. **Escalar lo real sin fallas**: si la evidencia apunta a evento real (o es inconclusa), escala con los contactos pertinentes. **Sea cual sea el veredicto de la IA, en un pánico SIEMPRE se hace una llamada de voz de verificación al operador** (DTMF: 1 = real, 2 = error), con reintentos; si nadie contesta, se ejecutan los protocolos de escalación. El resultado de la llamada alimenta la evaluación.

Principios de producto que gobiernan V2:

- **SAM define el default, el tenant lo afina.** Todo tenant nuevo nace con la "configuración SAM" probada y afinada por nosotros (reglas, protocolo de pánico, escalación, umbrales). Puede modificarla, pero el default ES el producto.
- **Los canales de comunicación son del servicio, no del cliente.** Un cliente NO configura su Twilio: SAM provee los canales (voz/SMS/WhatsApp/email/push) a nivel plataforma; el tenant solo los activa/desactiva y decide cómo escalar qué cosa.
- **Monitoreo proactivo, no solo reactivo**: activo que deja de reportar en X minutos → alerta; movimiento fuera de horario → alerta; pérdida de GPS en movimiento (posible jamming) → alerta. Enfoque robo/mal uso México.
- **Predictivo donde se pueda**: usar los safety events acumulados para detectar deterioro de riesgo del conductor y avisar antes del accidente.

---

## 2. Estado actual (auditoría 2026-06-11, verificada en código)

**V1 completo**: pipeline end-to-end (ingesta → normalización → contexto+media → IA multimodal → decisiones → incidentes con SLA/escalación → automation → notificaciones multicanal → audit/analytics), toda la superficie UI sin links muertos, billing local por transferencia, consola super-admin, i18n español, design system. Suite **1196 tests / 4308 assertions verde**.

**Gaps de V2 contra la visión del §1** (cada uno verificado en código):

| # | Capacidad de la visión | Estado | Detalle verificado |
|---|------------------------|--------|--------------------|
| G1 | Llamada de voz con DTMF al operador | ❌ NO EXISTE | `ChannelType` no tiene `Voice`; `TwilioMessenger` solo hace `messages->create()`; el webhook `TwilioInboundController` solo procesa SMS/WhatsApp (SI-/NO-/ESC-). `'voice'` aparece únicamente como dato de ejemplo en `TenantEscalationConfigFactory`. |
| G2 | Canales gestionados por SAM | 🟡 PARCIAL | `NotificationChannel.team_id = null` = canal global ya existe y `SelectNotificationChannels` los incluye; pero **no hay CRUD de canales globales en la consola super-admin**, **no hay toggle por-tenant de un canal global** (un global aplica a todos sin opción de apagarlo), y la UI del tenant lo invita a meter SUS credenciales (F5c). |
| G3 | Config default "SAM" para tenant nuevo | ❌ NO EXISTE | `CreateTenant` solo siembra `TenantFeature`; `tenant_settings` nace vacío; `media.auto_request_on_critical` default **OFF**; la regla `panic-false-alarm-review` está `is_active=false` y el seeder de reglas es solo del team demo (`serviexpress-jc`). No hay `ApplyDefaultTenantConfig` ni listener de `TenantCreated`. |
| G4 | Media alrededor del momento del evento, análisis por imagen | 🟡 PARCIAL | Ventana hardcoded `±30s` (`FetchDeferredEventMediaJob::CLIP_WINDOW_SECONDS`), no configurable. El assessment multimodal existe (1 por media) pero el prompt de `MediaInspectorAgent` no pide detección de personas/pasajeros ni señales estructuradas (los `extracted_signals` son genéricos). |
| G5 | Safety events alrededor del evento como evidencia | 🟡 PARCIAL | `LoadRecentAssetHistory` carga 60 min del mismo asset/tipo/alta severidad y `repeated_panic_count_24h`, pero **no correlaciona los safety events (frenadas, maniobras) en la ventana del pánico** ni los expone como señal/fact ni al prompt de IA. |
| G6 | Activo deja de reportar X min → alerta proactiva | ❌ NO EXISTE | `assets.last_seen_at` se actualiza pero nadie lo vigila; el event type `device_offline` está seeded pero nada lo genera. No hay job watchdog. |
| G7 | Movimiento fuera de horario | ❌ NO CABLEADO | La señal `outside_operating_hours` está declarada en `SignalsBuilder` pero `BuildEventContext` nunca la calcula; `TenantScheduleProfile.operating_hours_json` existe sin consumidor en el pipeline. |
| G8 | Señales antirrobo (jamming, parada no autorizada) | 🟡 PARCIAL | Existen `gps_signal_weak`, `asset_recently_stopped` y los incident types `SuspiciousStop`/`RouteDeviation`/`GeofenceBreach`, pero sin lógica de detección dedicada (GPS perdido EN movimiento, parada prolongada fuera de base). |
| G9 | Predicción/prevención de accidentes | 🟡 PARCIAL | `DriverRiskProfile` (risk_score, harsh_events_count, fatigue_flags_count) existe y se muestra en UI/contexto, pero **ningún job lo recalcula** — los contadores nunca se actualizan; no hay alertas de deterioro. |
| G10 | Escalación por canal y con reintentos | 🟡 PARCIAL | `steps_json.channels` existe pero `CheckIncidentAcknowledgementJob` lo **ignora** (solo usa `contacts`); no hay `attempts` por step. |

---

## 3. V2 — "SAM Monitorista" (orden recomendado, un PR por ítem)

Patrón obligatorio: mismo estándar de V1 — domain-modular, `BelongsToTenant`, factories+tests (aislamiento de tenant + idempotencia), `RecordUsageEvent` en puntos facturables, settings nuevos resueltos vía `TenantConfigResolver`, migraciones additive-only. **Cada PR que introduce un setting/regla/política nueva DEBE añadir su default afinado al SAM Default Pack (A5) en el mismo PR** (a partir de que A5 exista).

### Fase A — Protocolo de pánico SAM (el corazón del valor)

**A1 — Media alrededor del evento: ventana configurable + análisis por imagen + detección de pasajeros (cierra G4). ✅ COMPLETADO** — ver §7.

**A2 — Correlación de safety events alrededor del evento (cierra G5). ✅ COMPLETADO** — ver §7.

**A3 — Llamada de voz de verificación con DTMF (cierra G1; la pieza más grande de V2).**
- `ChannelType::Voice` + `VoiceNotificationDriver` usando Twilio Calls API (`calls->create()` con TwiML): locución TTS en español con resumen del incidente + `<Gather numDigits="1">` — **1 = incidente real** (ack + escalar según protocolo), **2 = error/falsa alarma** (resolver como falsa alarma). Webhooks nuevos `POST /api/webhooks/twilio/voice/{...}` (TwiML callback + status callback) validando `X-Twilio-Signature` (mismo patrón que `TwilioInboundController`).
- Modelo `IncidentCallVerification` (tenant-scoped): incidente, destinatario, intento N, estado (ringing/answered/no_answer/failed), dígito recibido, timestamps. Reintentos configurables: `voice.call_attempts` (default SAM: 3) con `voice.retry_delay_seconds` (default 60), encadenados por job con delay (patrón `CheckIncidentAcknowledgementJob`).
- **Disparo**: en incidentes de pánico SIEMPRE (independiente del veredicto IA — incluso falsa-alarma-probable se verifica por voz); destinatario = operador on-call resuelto por `ResolveOnCallOperator` (fallback a contacts de escalación).
- **El resultado cuenta en la evaluación**: outcome → señal/fact `operator_call_outcome` (`confirmed_real` / `confirmed_false` / `no_answer`) + timeline del incidente; `confirmed_false` puede cerrar como falsa alarma (action existente de B9); `no_answer` tras agotar intentos → disparar escalación inmediata (no esperar el SLA).
- Meter de uso `voice_calls` (idempotente por intento).

**A4 — Escalación que respeta canales y reintentos por step (cierra G10).**
- `CheckIncidentAcknowledgementJob::notifyLevel()` consume `steps_json[].channels` (→ `force_channels` del payload, validados contra `ChannelType`, ahora incluyendo `voice`) y `steps_json[].attempts` (reintentos del step antes de pasar al siguiente nivel).
- Editor de steps de escalación (UI ya existe, F-TC/F3 enterprise) gana selector de canales con voz y campo de intentos.

**A5 — SAM Default Config Pack: el protocolo de fábrica (cierra G3).**
- Action `ApplyDefaultTenantConfig` invocada desde `CreateTenant` (+ comando `php artisan tenants:apply-default-config {team?|--all}` para tenants existentes, idempotente). Siembra para el tenant:
  - **Settings afinados por SAM**: `media.auto_request_on_critical = ON`, ventanas de A1/A2, intentos de voz de A3, umbrales del watchdog (C1) — la lista crece con cada PR posterior.
  - **Reglas de decisión default**: `panic-button-always-incident` (dura) + `panic-false-alarm-review` **activa** (con los guards anti-coacción existentes: pánico "resuelto" en carretera nunca se degrada).
  - **Escalación default** (steps con canales+intentos: 0 min voz+push al on-call → 5 min SMS/WhatsApp → 15 min llamada a contacto del nivel 2), **notification policy default**, templates de pánico (ya seeded global).
  - Snapshot en `TenantConfigVersion` etiquetado `sam-default-v{N}`; columna/flag de origen (`managed_by: sam_default|tenant`) para distinguir lo que el tenant tocó, y acción "Restaurar configuración recomendada SAM" en la página de Configuración (no pisa lo modificado sin confirmación).
- Esto convierte el seeder demo (`SamsaraTestDecisionRulesSeeder`) en consumidor del pack (no duplicar definiciones: una sola fuente de verdad de los defaults, versionada en código).

### Fase B — Canales gestionados por SAM (cierra G2)

**B1 — Canales plataforma + toggle por tenant.**
- Consola super-admin: CRUD de canales globales (`team_id = null`) con credenciales de plataforma (Twilio/FCM/SMTP) — secrets cifrados at-rest (cast existente), enmascarados en UI.
- Toggle por tenant: tabla additive `tenant_channel_toggles` (team_id, notification_channel_id, enabled) — `SelectNotificationChannels` excluye los globales apagados por el tenant. Default: encendidos.
- UI tenant (tab Canales): los canales SAM aparecen como "Provistos por SAM" con switch on/off y SIN credenciales; crear canal propio pasa a ser opcional/avanzado. El tenant decide el routing (escalación/preferencias), no la infraestructura.

### Fase C — Monitoreo proactivo antirrobo México (cierra G6, G7, G8)

**C1 — Watchdog "dejó de reportar" (cierra G6).**
- Scheduler `assets:detect-offline` (cada 5 min, `onOneServer`): assets activos con `last_seen_at` más viejo que `monitoring.offline_alert_minutes` (setting por tenant, default SAM: 15; override por asset en `metadata_json`) → genera **RawEvent interno** (source `internal_monitor`, event_type `device_offline`) que recorre el pipeline completo (normalización → contexto → IA → decisión → incidente/notificación). Anti-spam: un solo evento por episodio offline (marca en el asset hasta que vuelva a reportar); al volver → resolución externa del evento (reusa `ApplyExternalResolution`).
- Señal `offline_in_motion_context` si el último reporte iba en movimiento (más sospechoso = posible jamming/desconexión).

**C2 — After-hours real (cierra G7).**
- `BuildEventContext` calcula `outside_operating_hours` vía `ResolveTenantSchedule` (la señal ya está declarada; cablearla) y la expone como fact.
- Detección activa: `PollAllAssetLocationsJob` (ya corre cada 5 min) detecta velocidad > umbral fuera del horario del `TenantScheduleProfile` → RawEvent interno `after_hours_movement` (un episodio = un evento). Regla default (vía pack A5): after-hours movement → incidente high + notificación.

**C3 — Señales antirrobo (cierra G8).**
- `gps_lost_in_motion`: posición stale/perdida cuando la última telemetría iba en movimiento (heurística jamming) — distinta de `gps_signal_weak`.
- `unauthorized_stop`: parada > `monitoring.stop_alert_minutes` (default SAM: 10) fuera de geofences conocidas (base/cliente/corredor) durante operación → RawEvent interno `suspicious_stop` (el incident type ya existe).
- Ambas como señales+facts con reglas default en el pack. (Desvío de ruta real requiere rutas planificadas → V3.)

### Fase D — Predictivo (cierra G9)

**D1 — Recalculo de riesgo del conductor + alerta preventiva.**
- Job diario `RecalculateDriverRiskProfilesJob`: agrega safety events de los últimos 30 días por driver (frenadas, speeding, fatiga, colisiones) → actualiza `harsh_events_count`/`fatigue_flags_count`/`risk_score`/`risk_level` + tendencia en `metadata_json`.
- Deterioro significativo (cruce de umbral o pendiente) → notificación preventiva `driver.risk_deteriorated` al tenant ("el conductor X acumuló 5 frenadas bruscas esta semana — riesgo de accidente") + KPI en analytics. La señal `driver_has_recent_risk_events` ya existe para el tiempo real; esto cubre la tendencia.

---

## 4. Orden global y reglas de sesión

| Fase | Ítems | Resultado |
|------|-------|-----------|
| **A. Protocolo de pánico SAM** | A1 → A2 → A3 → A4 → A5 | Un pánico se investiga solo (media amplia + visión por imagen + safety events correlacionados), SIEMPRE se verifica por voz con DTMF y reintentos, la escalación respeta canales, y todo tenant nuevo nace con este protocolo activado y afinado por SAM |
| **B. Canales SAM** | B1 | El cliente no configura Twilio jamás: activa/desactiva canales provistos por SAM y decide el routing |
| **C. Proactivo antirrobo** | C1 → C2 → C3 | SAM avisa cuando un activo se calla, se mueve fuera de horario, pierde GPS en movimiento o se detiene donde no debe |
| **D. Predictivo** | D1 | SAM avisa del conductor que va camino al accidente antes de que ocurra |

**Regla de decisión al abrir sesión:** si la fase actual tiene un ítem a medias, continuarlo; si no, tomar el siguiente de la tabla. Un PR por ítem, CI verde antes de merge (merge solo con autorización del usuario, CLAUDE.md §6.1), y actualizar este documento en el mismo PR. Desde que A5 exista, todo ítem posterior añade sus defaults al pack en su propio PR.

---

## 5. V3 — aplazado deliberadamente (no trabajar sin decisión del usuario)

- **Desvío de ruta real** — requiere modelo de rutas/viajes planificados; `unauthorized_stop` (C3) cubre el 80% del caso robo sin esa inversión.
- **Flujo conversacional con el driver** (SMS/WhatsApp al conductor, no al operador) — la infra quedó como `ActionTemplate` opt-in.
- **Voz con IA conversacional** (agente que conversa en vez de DTMF) — DTMF primero: simple, robusto, telefonía mexicana lo soporta siempre.
- **Segundo provider de integración (Geotab/Motive)** — solo con demanda real.
- **App móvil / PWA del operador** — el camino móvil hoy es voz + WhatsApp bidireccional.
- **Reportes programados por email** — el render PDF/XLSX ya existe; falta scheduling + entrega.
- **B3b — routing de modelo IA por tenant + tuning fino con datos de producción.**
- **Hardening producción**: retention jobs bajo carga real, replay/backfill masivo.

---

## 6. Notas de fiabilidad

- La tabla de estado §3 de `CLAUDE.md` quedó congelada en la auditoría 2026-04-29 y NO refleja B6+ ni V2. Ante duda, **manda el código**, luego este documento, y al final CLAUDE.md.
- El `ROADMAP.md` de la raíz es la **cola de la rutina nocturna**, no este roadmap de producto. Las tareas de la rutina salen de aquí (§3); al generar tareas nuevas respetar §8.7 de CLAUDE.md.
- Para demos del pipeline de media hoy (pre-A5): activar `media.auto_request_on_critical` en el tenant y verificar scope *Read Camera Media* en la integración Samsara. A5 lo deja ON de fábrica.
- Costos: A1 (más media) y A3 (llamadas) consumen cuota de proveedor — ambos quedan medidos (`media_requests`, `voice_calls`) y facturables vía billing local.

---

## 7. Completados (histórico resumido)

### V2 — "SAM Monitorista"

- **A2** — Correlación de safety events (2026-06-11): `LoadRecentAssetHistory::correlateNearbySafetyEvents` — eventos de categorías safety/emergency del mismo asset en `occurred_at ± context.safety_correlation_minutes` (default 30, resuelto en `BuildEventContext` vía `TenantConfigResolver`), excluyendo el evento bajo evaluación; conteo + breakdown por tipo + flag `harsh_driving_near_event` (catálogo `HARSH_DRIVING_CODES`: frenadas, acelerones, vueltas bruscas, near-collision, etc.). Señales nuevas `harsh_driving_near_event` / `nearby_safety_activity` en `SignalsBuilder`; facts `harsh_driving_near_event` + `nearby_safety_events_count` en `DecisionFactsBuilder` y el catálogo del builder visual; persistido en `recent_history_snapshot_json` y `recent_flags_json` (fluye al prompt de IA vía `BuildAIInputContext`); guía explícita en el prompt del `EventClassifierAgent` (maniobra brusca cerca del pánico pesa hacia real; calma sola nunca degrada). Tests: ventana centrada con exclusión del evento, límites de ventana, categorías no-safety ignoradas, harsh flag, aislamiento por asset, passthrough de señales y facts.

- **A1** — Media alrededor del evento (2026-06-11): ventana de clip configurable (`media.clip_window_seconds`, default 60s por lado) resuelta vía `TenantConfigResolver` en `FetchDeferredEventMediaJob`; stills distribuidos en `occurred_at ± media.still_window_minutes` (default 30) × `media.still_count` (default 6) — un retrieval `mediaType: image` por instante (`MediaRetrievalAdapter::requestMedia` ganó el parámetro), poll agregado por request con conteo `stills_downloaded`, fail solo si el proveedor falla todos. `RequestPanicMediaOnContextBuilt` pide clip + stills (skip con `still_count = 0`). Prompt del `MediaInspectorAgent` reescrito con framing de monitoreo de seguridad en México y señales estructuradas obligatorias (`persons_visible_count`, `passenger_detected`, `driver_visible`, `visible_threat`, `cabin_appears_normal`, `vehicle_moving`; `summary_text` en español); `DecisionFactsBuilder` agrega los facts `media_passenger_detected` / `media_visible_threat` / `media_persons_visible_count` / `media_cabin_appears_normal` (evidencia alarmante domina, agregado cross-versión de evaluación) y `DecisionConditionCatalog` los expone al builder visual. Tests: ciclo completo de stills, ventana por TenantSetting, rechazos totales/parciales, listener con stills on/off, agregación y aislamiento de los facts de visión.

### V1 (cerrado 2026-06-10/11)

- Backend specs 01–16 + I1/I2/I3 y cierre de diferidos: AI SDK, multimodal, PDF/XLSX, drivers de notificación, queries DB-backed, Echo wiring, DemoSeeder (PRs #1–#24).
- UI Incidents con realtime (PRs #27–#30, #40) · UI Integraciones (PR #31).
- Pipeline Samsara real: adapter, firma webhook, replay, seeders, sync periódica, scheduler dedicado (PRs #28, #32, #34, #35, #42, #46).
- Consola super-admin completa (PRs #37, #39, #41, #43–#45): tenants, suscripción/plan, topes con enforcement, features, miembros, operadores, auditoría cross-tenant, impersonación, ciclo de vida.
- **F1** roles (PR #48) · **F2** dashboard real (PR #49) · **B3** IA real OpenAI (PR #50) · **F3** assets completo con mapa vivo (PRs #53–#55) · **F4** drivers (PR #58) · **F5a/F5b** notificaciones + preferencias (PR #58) · **B1a** policies Tenancy (PR #58).
- **B6 COMPLETO (P1–P8)** — pipeline de emergencias end-to-end (PRs #56, #58): `isResolved`, safety events feed con cursor, media on-demand (`MediaRetrievalAdapter`), GPS fresco en críticos, notificación rica + on-call, SLA real con ack/escalación, validación de falsa alarma opt-in con guards anti-coacción, vínculo histórico de incidentes.
- **PR #63 (V1 fases A–E)**: **B8** loop multimodal cerrado (re-evaluación al llegar media, guard de contradicción) · **B7** ejecutores reales de Automation · **B9** Twilio bidireccional SMS/WhatsApp (reply tokens SI/NO/ESC, webhook firmado, `ProcessInboundReply`) · **F9** detalle full-page de incidente + media viewer · **F10** página Eventos · **F11** Reglas · **F12** Automatizaciones · **F-TC** Configuración del tenant · **F5c** gestión de canales · **F13** Analítica · **F14** Auditoría · **B1b+F7** Billing+Branding tenant-facing · **B2** billing local por transferencia (Cashier retirado).
- **UI Enterprise (PR #65)**: design system (tokens, sombras tintadas, primitivos nuevos), ConditionBuilder con probador "¿coincidiría?", español 100% (lang/es + barridos), DataTable reutilizable + formularios estructurados (escalación, targets), micro-interacciones, contraste WCAG AA verificado, command palette real. Suite 1195+ tests.
