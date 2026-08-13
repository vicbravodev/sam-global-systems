# Auditoría exhaustiva del flujo de pánico/monitoreo — Diseño y roadmap de cierre

> **Fecha:** 2026-06-18 · **Autor:** auditoría asistida (8 sub-auditorías de código + walkthrough visual del front con Playwright) · **Estado:** propuesta para revisión del dueño.
>
> Este documento es el **mapa de cierre** entre el North Star "V2 — SAM Monitorista" ya escrito en [`docs/ROADMAP.md`](../../ROADMAP.md) y el **estado real del código**. No reemplaza a `ROADMAP.md` (que sigue mandando como fuente de prioridades de producto); lo alimenta con gaps verificados y los agrupa en **packs** con criterios de aceptación. Cada pack se convierte luego en su propio plan de implementación (`docs/superpowers/plans/…`).

---

## 0. Método y alcance

Se auditó el flujo objetivo descrito por el dueño:

> Conectar Samsara → sincronizar drivers/assets → escuchar webhook de alert incidents (panic buttons) → al llegar un panic, correlacionar los safety events alrededor (detección de pasajeros **antes** = mala señal; frenada/maniobra brusca) → la IA simula un monitorista que **decide o descarta** → **re-evalúa** si la resolución es ambigua → los agentes humanos **siempre** pueden tomar la alerta y terminarla antes que la IA → se analizan **todas las imágenes (solo imágenes)** con IA → UI sencilla para que el agente entienda de un vistazo. Más: config del tenant, inglés en la UI, configuración compleja, y monitoreo **proactivo** (asset que no reporta en X → alerta).

Cobertura: 8 sub-auditorías read-only (Samsara sync, ingesta panic, correlación de contexto, IA, decisiones+incidentes+toma humana, proactividad, TenantConfig, frontend+i18n) + recorrido visual de login, dashboard, bandeja de incidentes, detalle de panic, páginas 403/404 y consola de configuración.

---

## 1. Veredicto

**El sistema está mucho más construido de lo que la percepción sugería.** El pipeline corre end-to-end con código real (no stubs); la UI es madura (español consistente, un solo shell, móvil arreglado, bandeja/detalle fuertes). Los gaps que importan están **concentrados en 4 focos**, no esparcidos.

### 1.1 Correcciones a la percepción inicial (verificadas)

- **"Asset no reporta en X → alerta" YA EXISTE.** `DetectOfflineAssetsJob` corre cada 5 min, umbral configurable por tenant **y por asset**, con auto-resolución y anti-spam. Hay 5 monitores proactivos, no es reactivo-puro.
- **Samsara sync es real, no Null.** `SamsaraAdapter` hace HTTP real a la Fleet API; credenciales por tenant cifradas; sync idempotente de assets/drivers; polling de safety events con cursor incremental.
- **La IA sí "simula un monitorista".** El prompt (`EventClassifierAgent`) es un monitorista de seguridad mexicano con lógica anti-coerción y consumo del contexto correlacionado. **Toda** la media persistida se analiza (sin sampleo).
- **El front ya "se ve como producto maduro".** Las roturas que la memoria marcaba (móvil, badges fake, ⌘K, shell legacy) están **cerradas en código**.
- **El gate "safety NO pasa por IA" YA ESTÁ EN `main`** (PR #87, commit `a028741`): `AIEvaluationGate` + `config('ai.skip_evaluation_categories') => ['safety']`, wired en 4 puntos, con 2 archivos de tests. **Las sub-auditorías no lo vieron porque esta rama (`claude/upbeat-wu-4cf067`) salió de `main` en el PR #86, ANTES del #87.** → **Pack 0 obligatorio: sincronizar la rama con `main` antes de construir.**

### 1.2 Decisiones del dueño ya tomadas (2026-06-18)

1. **Roadmap escrito primero** (este documento), antes de tocar código.
2. **"Solo imágenes" es estricto:** la IA de visión debe analizar únicamente imágenes/stills; video/audio se excluyen del pipeline de assessment.
3. **Safety-skip:** confirmado que ya está en `main` (#87) — no es trabajo nuevo, solo verificar al sincronizar.

---

## 2. Estado real vs North Star "V2 — SAM Monitorista"

| Capacidad del North Star (ROADMAP §1) | Estado real | Pack |
|---|---|---|
| Investiga solo: pide footage, analiza cada imagen, describe lo que ve | ✅ pipeline real; ⚠️ analiza también video/audio (debe ser solo imágenes); ⚠️ footage **default OFF** | 2, 3 |
| Correlaciona safety events alrededor del momento (frenadas, maniobras), detecta pasajeros | 🟡 correlación temporal ±N existe, pero **simétrica** (no distingue antes/después) y **passenger detection no entra** (categoría `compliance`) | 3 |
| Degrada/descarta documentando por qué; escala lo real | ✅ clasificación + acciones recomendadas + explicación | — |
| Llamada de voz de verificación SIEMPRE en pánico, con reintentos | ✅ `incident_call_verifications` + `StartCallVerificationOnIncidentCreated` | — |
| Re-evalúa cuando hay nueva evidencia / ambigüedad | 🟡 re-evalúa por **llegada de media**; **NO** por ambigüedad/baja confianza | 3 |
| Monitorista humano puede tomar y cerrar antes que la IA | 🔴 **no implementado a nivel de semántica**: el incidente solo nace al final del pipeline; no hay `claim`/lock; ACK no silencia la automatización | 1 |
| SAM define el default, el tenant lo afina (sin tocar código) | 🟡 defaults por tenant ✅, pero settings críticos **no editables** desde UI y claves crudas filtradas | 5 |
| Proactivo (no reporta / fuera de horario / jamming) | ✅ implementado; faltan extensiones (batería baja, velocidad sostenida) | 6 |
| UI para que el agente entienda de un vistazo | 🟡 bandeja/detalle fuertes, pero **sin mapa**, "razonamiento" fabricado en cliente, safety events como códigos crudos | 4 |

---

## 3. Packs de cierre (priorizados)

> Severidad: **P0** = bloquea el flujo objetivo / producción · **P1** = cierra el North Star · **P2** = pulido.
> Cada pack tendrá su propio diseño/plan antes de implementar.

### Pack 0 — Sincronizar rama con `main` (#87) · **prerequisito**
- **Objetivo:** que el worktree refleje `main` (incluye el safety-skip ya mergeado) para no planear contra código stale.
- **Acción:** merge de `main` dentro de `claude/upbeat-wu-4cf067` (sin `--force`), correr suite + gates, confirmar `SafetyEventSkipsAIEvaluationTest` verde.
- **Criterio de aceptación:** `git log` muestra `a028741` en la rama; `vendor/bin/pint --test`, `php artisan test --compact`, `npm run types:check && lint && build` verdes.

### Pack 1 — Toma humana del flujo (P0) · *el gap más grave*
- **Objetivo:** cumplir "el humano SIEMPRE puede tomar la alerta y terminarla antes que la IA", sin condiciones de carrera.
- **Gaps incluidos:** 1.1 (no hay artefacto tomable antes de la IA), 1.2 (no existe `claim`/lock; ACK no silencia automatización), 1.3 (sin guard de carrera).
- **Diseño propuesto (a detallar):**
  1. Para categorías críticas (pánico/emergencia), **materializar el incidente al normalizar/contextualizar**, *antes* de `EvaluateEventJob`; la IA/decisión **enriquece** ese incidente en vez de crearlo. Así hay algo que tomar desde el segundo cero.
  2. Acción `ClaimIncident` (`claimed_by_user_id`, timeline, broadcast) + estado "manejo humano".
  3. **Guard único** que el pipeline consulta antes de aplicar decisión / verificación por voz / auto-asignación on-call / escalación: si está `claimed`/resuelto por humano → cede (no solo `isTerminal()`).
  4. **Lock determinístico** de la transición terminal (`lockForUpdate` sobre el incidente o `updateOrCreate` condicional sobre `IncidentResolution`, que ya tiene `unique(incident_id)`): el primero que escribe gana.
- **Archivos clave:** `CreateIncidentOnDecisionMade.php`, `CreateIncidentFromEvent.php`, `CloseIncident.php`, `RunDecisionEngineJob.php`, `RequestReevaluationOnMediaAssessmentCompleted.php`, `PlaceVerificationCallJob.php`, `Incident.php`, `IncidentPolicy.php`, `IncidentController.php`.
- **Criterios de aceptación:**
  - Un agente puede "tomar" un panic recién llegado **antes** de que exista evaluación IA.
  - Tras `claim`/resolución humana, el pipeline automático **no** crea decisión nueva, **no** llama por voz, **no** escala.
  - Test de carrera: IA y humano resuelven casi simultáneo → resultado determinístico (gana el primero; el segundo aborta limpio).
  - `reopen` rearma SLA y deja entrada de timeline (cierra desvío con spec 11 §10.7).
- **Riesgo:** medio-alto (toca el orden del pipeline). Requiere diseño cuidadoso de la materialización temprana del incidente para no romper dedup/idempotencia existente.

### Pack 2 — Desbloqueo de producción (P0)
- **Objetivo:** que un panic real de Samsara llegue, se autentique y traiga media, sin que un tenant nuevo tenga que adivinar flags.
- **Gaps incluidos:**
  - **2.1 Secret del webhook (P0):** permitir pegar el **Secret Key real de Samsara** en la UI de integración y usarlo como secret del `WebhookEndpoint` (o exponer el secret de SAM para pegarlo en Samsara). Hoy los HMAC nunca casarían con webhooks reales.
  - **2.2 Media del panic default (P1→P0 para el flujo):** decidir e implementar el comportamiento out-of-the-box de `media.auto_request_on_critical` para emergencias (que el panic traiga imágenes sin intervención), o forzarlo en onboarding.
  - **2.3 Robustez (P1/P2):** `WebhookController` debe leer `eventType` (no `event_type`); manejar HTTP **429** con `Retry-After`; persistir cursor de ubicaciones.
- **Archivos clave:** `WebhookEndpoint.php`, `IntegrationController.php`, `pages/integrations/index.tsx`, `RequestPanicMediaOnContextBuilt.php`, `WebhookController.php`, `SamsaraAdapter.php`.
- **Criterios de aceptación:**
  - Un test que use el **fixture real** (`database/fixtures/samsara-panic-events.json`) con firma de Samsara pasa la verificación HMAC.
  - Un tenant recién creado captura media del panic sin tocar config manual (o el onboarding lo deja explícito).
  - `WebhookEvent.event_type` ya no es `'unknown'` para webhooks reales.

### Pack 3 — Reglas del flujo IA alineadas al spec (P1)
- **Objetivo:** que el comportamiento de la IA coincida con lo pedido.
- **Gaps incluidos:**
  - **3.1 Solo imágenes (estricto):** filtrar el pipeline de assessment a `MediaType::{Image,Snapshot}`; excluir clip/video/audio. (Decisión del dueño confirmada.)
  - **3.2 Re-evaluación por ambigüedad:** tras persistir evaluación, si `classification ∈ {Unclear, PendingEvidence}` o `confidence_score < umbral` (config/tenant), encolar `ReevaluateEventJob` (trigger `ManualReviewRequested`/`ContextUpdated`) con guard de versión máxima anti-loop.
  - **3.3 Correlación direccional + passenger detection:** incluir el `offset_seconds` (antes/después) de cada safety event en `nearby_safety_breakdown`; mover/añadir un tipo `passenger_detected`/occupancy a una categoría que **sí** entre a `correlateNearbySafetyEvents` (hoy `unauthorized_passenger` está en `compliance` y queda fuera).
- **Archivos clave:** `EvaluateEventMultimodally.php`, `AssessPendingMediaOnEvaluationCompleted.php`, `EvaluateEventWithAI.php`, `LoadRecentAssetHistory.php`, `NormalizationSeeder.php`, `SignalsBuilder.php`, `config/ai.php`.
- **Criterios de aceptación:**
  - Ningún assessment de IA se ejecuta sobre video/audio.
  - Un panic con clasificación `unclear`/baja confianza dispara una re-evaluación (con tope de versiones).
  - El breakdown de safety events distingue "antes" vs "después" del panic; una detección de pasajeros previa al panic queda reflejada en señales que la IA consume.

### Pack 4 — UX del monitorista (P1) · *lo que más "se ve"*
- **Objetivo:** que el agente entienda un panic de un vistazo y vea la info de primera mano.
- **Gaps incluidos:**
  - **4.3 Mapa en el detalle del incidente** (reutilizar `LiveMap`/MapLibre con el pin del panic y, si hay, la traza reciente).
  - **4.4 Trace real de IA:** reemplazar la "Cadena de razonamiento" fabricada en cliente (`detail-center.tsx:302`) por el trace/explicación real, o etiquetarla honestamente como "Pasos del pipeline".
  - **4.5 Panel explícito "Eventos de seguridad alrededor (±N min)"** con etiquetas humanas en español, en vez de los códigos crudos (`forward_collision_warning`) que hoy aparecen en "Relacionados" y en el "Stream en vivo".
  - **4.6 Pulido:** traducir códigos de evento a español en todas las vistas; tiles de "Evidencia" funcionales o quitados; verificar que "Ver en Samsara" tenga href real.
- **Archivos clave:** `components/sam/incident-detail/*`, `detail-center.tsx`, `detail-timeline.tsx`, `dashboard.tsx`, mapa de códigos→etiquetas es.
- **Criterios de aceptación:** el detalle de un panic muestra mapa + safety events correlacionados etiquetados en español + razonamiento veraz; el "Stream en vivo" no muestra códigos crudos.

### Pack 5 — Config del tenant sin fricción (P1)
- **Objetivo:** quitar la sensación de "complejo de configurar".
- **Gaps incluidos:**
  - **4.1 Settings críticos editables:** convertir la tabla read-only "Otros settings" en formularios guiados para las claves que ya tienen `SETTING_LABELS` (teléfonos de verificación por voz, umbral offline, ventana de correlación, ventanas de media). El backend ya acepta el batch tipado; falta UI de inputs.
  - **Quitar claves técnicas crudas de las etiquetas** (p.ej. "(panic.auto_close_on_external_resolution)") → label humano + ayuda contextual.
  - **4.2 On-call:** editor visual de turnos (no JSON crudo) + poder **crear** un perfil de horario desde la UI (`POST settings/tenant-config/schedule`).
- **Archivos clave:** `pages/settings/tenant-config.tsx` (monolito 2332 L — considerar trocear), `TenantConfigController.php`, `TenantScheduleProfileController.php`, `routes/web.php`.
- **Criterios de aceptación:** un admin de tenant configura teléfonos de verificación, umbral de heartbeat y turnos on-call **sin tocar JSON ni ver claves técnicas**.

### Pack 6 — Pulido i18n + extras proactivos (P2)
- **Gaps incluidos:** 4 cabeceras en inglés (`Setting`, `Outcome`, `Meter`, "Timeline"); badge de rol en topbar hardcodeado a "Supervisor"; renombrar el tipo `MockIncident`→`IncidentRow`. Extensiones proactivas del mismo molde `Detect*Job`: **batería baja** (P1 dentro del pack), velocidad sostenida, geofence dwell.
- **Criterio de aceptación:** sin inglés en UI de usuario; badge de rol refleja el rol real; al menos el monitor de batería baja shippeado.

---

## 4. Secuenciación recomendada

```
Pack 0 (sync main)  →  Pack 1 (toma humana, P0)  →  Pack 2 (producción, P0)
                                                          ↓
        Pack 3 (reglas IA, P1)  →  Pack 4 (UX monitorista, P1)  →  Pack 5 (config UX, P1)  →  Pack 6 (pulido, P2)
```

Racional: primero la integridad del flujo (que el humano gane y que el panic real llegue con media), luego la fidelidad de la IA al spec, luego lo visible/usable.

---

## 5. Preguntas abiertas (a resolver antes o durante cada pack)

1. **Pack 2.1:** ¿modelo de secret del webhook = (a) tenant pega el Secret Key de Samsara en SAM, o (b) SAM expone su secret para pegarlo en Samsara? (a) es lo más alineado a "el tenant trae su org de Samsara".
2. **Pack 2.2:** ¿`media.auto_request_on_critical` se vuelve ON por default para emergencias, o se deja OFF pero el onboarding obliga a activarlo? (sweep es gratis vía `listUploadedMedia`, no retrieval pagado → inclina a ON).
3. **Pack 1:** ¿el incidente materializado temprano para pánicos debe ser visible en la bandeja con un estado tipo "Entrante / sin evaluar", o un artefacto "alerta" separado del incidente? Afecta el modelo de datos.
4. **Pack 3.2:** umbral de confianza para disparar re-evaluación: ¿global en `config/ai.php` o por tenant (`TenantAIProfile`)?

---

## 6. Cómo continúa

Tras aprobación de este documento: por cada pack, se crea su diseño/plan detallado (skill `writing-plans` → `docs/superpowers/plans/2026-06-18-pack-N-*.md`) y se implementa en su propia rama/PR, empezando por Pack 0 → Pack 1. Nada de implementación hasta que el dueño apruebe este roadmap y el plan del pack en turno.
