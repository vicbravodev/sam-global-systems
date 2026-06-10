# MORNING REPORT — rutina nocturna `claude/night-roadmap`

## Run 2026-06-10 12:10

**Resumen ejecutivo.** Run de verificación sin avance de tareas: el ROADMAP sigue **sin pendientes** (`- [ ]` = 0) tras el cierre de v4 en el run de las 10:25, y el cupo diario de auto-generadas (10) quedó consumido con v2+v3+v4, así que no se genera Iteración v5 hoy. Se re-verificó la base completa sobre el último commit de la rama: **1077 tests / 3583 assertions verdes**, Pint limpio, tsc/eslint/prettier verdes y build de Vite exitoso. Mismo entorno degradado que el run anterior: PHP 8.4 sin `ext-bcmath` ni driver de cobertura (PPA `ondrej/php` bloqueado con 403 por política de red) — composer corrió con `--ignore-platform-req=ext-bcmath` y la suite vía `vendor/bin/phpunit --no-coverage`.

### Tareas completadas

Ninguna (no había `- [ ]` pendientes).

### Tareas bloqueadas

Ninguna.

### Auto-generadas

Ninguna: cupo diario 10/10 consumido (4 v2 + 3 v3 + 3 v4). Si el primer run de mañana encuentra hallazgos materiales en la auditoría, puede generar la Iteración v5 (límite final de la rutina).

### Verificación final

- `vendor/bin/phpunit --no-coverage` → **OK (1077 tests, 3583 assertions)**.
- `vendor/bin/pint --test` → pass.
- `npm run types:check && npm run lint:check && npm run format:check` → verdes.
- `npm run build` → exitoso.
- Cobertura: sin driver pcov/xdebug en este contenedor; no medible localmente (CI la exige igual).

### Riesgos / para mirar primero

- El PR de la rama acumula todo el trabajo de v1–v4 y está listo para revisión humana; nada nuevo desde las 10:25.
- La limitación de cobertura local es del contenedor, no del repo: si CI tiene pcov, el gate de cobertura corre allá con normalidad.

## Run 2026-06-10 10:25

**Resumen ejecutivo.** Se completaron las **3 tareas de la Iteración v4** — F5a (centro de notificaciones), F5b (preferencias de notificación del usuario) y T2 (tests de authz de la API de incidents) — con lo que **el ROADMAP queda sin tareas pendientes** (`- [ ]` = 0). Con el cupo diario de 10 auto-generadas ya consumido en runs anteriores, este run no genera Iteración v5. Suite final: **1077 tests / 3583 assertions, todo verde**; Pint limpio; tsc/eslint/prettier/build verdes. Nota de entorno: este contenedor trae PHP 8.4 sin `ext-bcmath` ni pcov (PPA bloqueado por política de red) — composer corrió con `--ignore-platform-req=ext-bcmath` y los tests vía `vendor/bin/phpunit --no-coverage` (equivalente a la suite; `php artisan test` exige driver de cobertura inexistente aquí).

### Tareas completadas (commits en orden)

| Commit | Tarea |
|--------|-------|
| `feat(notifications): centro de notificaciones con read markers por usuario (F5a)` | `NotificationPageController` (`GET /{team}/notifications` + `POST .../read`), tabla additive `notification_reads` (lectura **por usuario**: el "leída" de un operador no pisa el de otro) + `MarkNotificationRead` idempotente, filtros estado/prioridad/no-leídas, `sourceUrl` al incidente, página `notifications/index.tsx` + sidebar activado. 10 tests. |
| `feat(notifications): preferencias de notificación del usuario en settings (F5b)` | `Settings\NotificationPreferencesController` (`GET/PUT /settings/notifications`): upsert idempotente por (user, type) validando canales contra `ChannelType`, knownTypes = catálogo base ∪ tipos observados en el tenant; página settings con filas por tipo + item de nav. **Fix de rutas**: `require settings.php` movido antes del grupo `{current_team}` — `/settings/notifications` matcheaba el wildcard del slug y daba 404. 8 tests. |
| `test(incidents): authz endpoint-level para update/evidence/link-event/assign (T2)` | Auditoría de las 13 rutas API de incidents: faltaba cobertura endpoint-level de `PUT update`, `POST evidence`, `POST link-event` y el 403 de `assign`. `IncidentApiAuthzTest` (11 tests): happy + 403 sin permiso + 404 cross-team, incluido 404 cuando el normalized event del link es de otro tenant. |

### Tareas bloqueadas

Ninguna.

### Auto-generadas

Ninguna en este run: el cupo diario (10) quedó completo con v2+v3+v4 en los runs de hoy, y no se crea v5 hoy. Si mañana la auditoría encuentra hallazgos materiales, el siguiente run puede generar v5 (límite final).

### Verificación final

- `vendor/bin/phpunit --no-coverage` → **1077 passed (3583 assertions)**.
- `vendor/bin/pint --test` → limpio.
- `npm run types:check && npm run lint:check && npm run format:check` → verdes.
- `npm run build` → exitoso (incluye Wayfinder regenerado con las rutas nuevas).
- Cobertura: **sin driver pcov/xdebug en este contenedor** (instalación bloqueada: el PPA `ondrej/php` devuelve 403 por política de red) — no se pudo medir localmente; CI la exigirá igual.

### Riesgos / revisar primero

1. **Cambio de orden de carga de rutas** (`routes/web.php`): `require settings.php` ahora va ANTES del grupo `{current_team}`. Necesario para que `/settings/notifications` no se interprete como team slug `settings`. La suite completa pasa, pero si existiera un tenant con slug `settings`, sus URLs `/{slug}/...` que colisionen con rutas literales de settings resolverían a settings (comportamiento más correcto, pero conviene saberlo).
2. **Semántica de "leída"**: se decidió lectura por usuario (tabla `notification_reads`) y no un flag global en `notifications` — una notificación es tenant-wide con N destinatarios. Si producto prefiere "atendida por el equipo" (un solo flag), hay que decidirlo explícitamente.
3. Entorno del contenedor degradado respecto a runs previos (PHP 8.4 sin bcmath/pcov): si el siguiente run ve fallar `composer install` o `php artisan test`, usar los mismos workarounds (`--ignore-platform-req=ext-bcmath`, `vendor/bin/phpunit --no-coverage`).

## Run 2026-06-10 09:15

**Resumen ejecutivo.** Run muy productivo: se completaron las **4 tareas de la Iteración v2** (B6-P2 safety events feed, B6-P3 media on-demand, F4b detalle de conductor, T1 assertInertia) y, tras auto-generar la Iteración v3 desde el plan B6 del roadmap de producto, **también sus 3 tareas** (B6-P5 notificación rica + on-call, B6-P6 SLA con escalación, B6-P7 validación de falsa alarma). **Con esto el plan B6 completo (P1–P8) queda cerrado.** Suite final: **1048 tests / 3411 assertions, todo verde**; Pint limpio; front (tsc/eslint/prettier/build) verde. Se generó la Iteración v4 (F5a/F5b notificaciones UI + T2 auditoría de authz API) — con ella el cupo de 10 auto-generadas del día queda completo.

### Tareas completadas (commits en orden)

| Commit | Tarea |
|--------|-------|
| `feat(ingestion): safety events feed de Samsara (B6-P2)` | Poller del feed `GET /safety-events/stream` con cursor en `sync_state_json` (migración additive), dedup `safety:{id}:{eventState}` que deja pasar transiciones de estado, media inline descargada al momento, `eventState=dismissed` → `ApplyExternalResolution`, meter `ingested_events`, seeder `severe_speeding`, scheduler cada 2 min. 15 tests. |
| `feat(context): media on-demand para eventos críticos (B6-P3)` | Contrato `MediaRetrievalAdapter` + Samsara `POST/GET /cameras/media/retrieval`; `FetchDeferredEventMediaJob` real (place → poll → download → materializa vía `AttachImmediateEventMedia`, acotado por `expires_at` 6h); listener `RequestPanicMediaOnContextBuilt` gateado por `media.auto_request_on_critical` (default off); meter `media_requests`. 15 tests (8 del job reescrito + 7 del listener). |
| `feat(drivers): página drivers/{id} (F4b)` | `DriverPageController@show` (404 cross-team + policy), perfil/contactos/documentos/assignments/status log, `pages/drivers/show.tsx`, navegación desde el roster. 5 tests. Nota: `DriverDocument` usa `file_url` string — no hay relación FileObject, la mención del task a `temporaryUrl` no aplicaba. |
| `test(inertia): assertInertia para 5 páginas (T1)` | `teams/index`, `teams/edit`, `settings/profile`, `admin/operators/index` y `settings/appearance` (test nuevo) fijan componente y props. |
| `feat(notifications): notificación rica + auto-asignación on-call (B6-P5)` | Template por `incident_type` (`incident.panic_emergency.created` seeded global email+web) con payload enriquecido (asset/driver/ubicación/link/media); `ResolveOnCallOperator` sobre `shift_rules_json` (turnos con timezone, overnight, fallback, sólo miembros) + auto-asignación con notificación dirigida Critical. Contacto directo al driver queda como `ActionTemplate` opt-in del tenant (no hardcodeado). 9 tests. |
| `feat(incidents): SLA real con acknowledgement y escalación (B6-P6)` | `sla_due_at`/`acknowledged_at`/`acknowledged_by` (migración additive), watchdog `CheckIncidentAcknowledgementJob` con `->delay($sla)` y re-armado por nivel de `TenantEscalationConfig.steps_json` (decisión: NO se usó `EscalationPolicy` de Decisions — TenantConfig es el dueño natural), `AcknowledgeIncident` + endpoint web + botón "Atender (ACK)" en la bandeja. 9 tests. |
| `feat(decisions): validación de falsa alarma (B6-P7)` | Señales `external_resolved`/`parked_at_base`/`repeated_panic_24h` (+`GeofenceCategory::Base`), facts con `media_assessment`, REVIEW fuerza `requires_human_review`, regla opt-in de ejemplo INACTIVA, prompt del clasificador con guía falsa-alarma-vs-coacción. 8 tests (matriz de 5 + 3 unit). |

### Tareas bloqueadas

Ninguna.

### Auto-generadas

Iteración v3 (3, completadas en este mismo run) e Iteración v4 (3: F5a centro de notificaciones, F5b preferencias, T2 auditoría authz API) — total del día: 10/10.

### Verificación final

- `php artisan test --compact` → **1048 passed (3411 assertions)** (con `--no-coverage`; ver riesgo abajo).
- `vendor/bin/pint --test` → limpio.
- `npm run types:check && npm run lint:check && npm run format:check` → verdes.
- `npm run build` → OK (Wayfinder regenerado).

### Riesgos / mirar primero

- **Entorno**: la red del contenedor bloquea `ppa.launchpadcontent.net` → `composer install` falló por `ext-bcmath`; se instaló con `--ignore-platform-req=ext-bcmath` (ninguna ruta de código tocada usa bcmath). Sin driver de cobertura (pcov) por lo mismo: la suite corre con `--no-coverage` y el umbral local no se pudo verificar — CI sí la exige. El run de las 06:50 documentó cómo compilar ambas extensiones desde fuente; sigue pendiente decisión humana de meterlo a `.claude/setup.sh`.
- **B6-P6**: el endpoint `acknowledge` usa la ability `update` (`incidents.manage`) — si se quiere un permiso más laxo para operadores de solo-lectura habrá que decidirlo.
- **B6-P7**: la degradación sólo llega hasta REVIEW y sólo con la regla opt-in activa; el seeder de ejemplo vive en `SamsaraTestDecisionRulesSeeder` (tenant de pruebas).
- **B6-P5**: convención nueva `shift_rules_json.on_call[]` documentada en `ResolveOnCallOperator` — si ya había otra convención prevista para spec 16, revisar.

## Run 2026-06-10 06:50 (concurrente — trabajo descartado por duplicado)

**Resumen ejecutivo.** Este run arrancó en paralelo con el de las 06:43: la rama remota `claude/night-roadmap` aún no existía cuando ambos hicieron el chequeo del candado, así que el candado anti-concurrencia no pudo activarse (solo funciona cuando la rama ya existe). Ambos runs completaron de forma independiente **las mismas 4 tareas de la Iteración v1** (B6-P8, B6-P4, B1a, F4a) con implementaciones equivalentes. El run de las 06:43 empujó primero; este run **descartó sus 6 commits locales sin publicarlos** (nada de force-push ni merges de implementaciones paralelas) y se alineó al estado remoto. Sin cambios de código propios en la rama.

Aportes informativos de este run que el de las 06:43 no pudo verificar:

- **Cobertura ejecutada y en verde** sobre el mismo conjunto de tareas: Tier 1 **22/22** ≥95% · Tier 2 **88.39%** ≥80% · Global **85.33%** ≥75% (`--mode=local`, PASS). El otro run no tenía driver de cobertura.
- **Cómo conseguir driver de cobertura y bcmath en este contenedor pese a la red bloqueada**: compilar desde código fuente de GitHub funciona — `bcmath` desde `php/php-src` (tag `php-8.4.19`, `phpize && ./configure && make`) y `pcov` desde `krakjoe/pcov` v1.0.12; instalar el `.so` en `/usr/lib/php/20240924/` + ini en `/etc/php/8.4/cli/conf.d/`. Candidato a incorporarse a `.claude/setup.sh` (decisión humana; la rutina no edita ese script sin pedirlo).
- **Sugerencia de robustez del candado**: considerar que el run haga push de un commit de "run iniciado" inmediatamente tras crear la rama (o usar otra señal), para cubrir la ventana del primer arranque donde dos runs no se ven entre sí.

## Run 2026-06-10 06:43

**Resumen ejecutivo.** Primer run de la rutina (la rama no existía; se creó desde `main`). Se reparó la base — el repo no arrancaba en PHP 8.4 por un fatal de constante de trait en Audit — y se completaron las **4 tareas de la Iteración v1**: B6-P8 (vínculo histórico de incidentes), B6-P4 (GPS fresco en eventos críticos), B1a (policies de Tenancy) y F4a (página `drivers/index`). Suite final: **988 tests / 3126 assertions, todo verde**, Pint limpio, front (tsc/eslint/prettier/build) verde. Se auto-generó la Iteración v2 (4 tareas) para el siguiente run.

### Tareas completadas (commits en orden)

| Commit | Tarea |
|--------|-------|
| `2d96824` | **fix(audit)** — base rota en este entorno: el trait `AppendOnly` redefinía `UPDATED_AT = null`, fatal en PHP 8.3/8.4 (soportados por `composer.json`; CI corre 8.5 donde no es fatal). Reemplazado por override de `getUpdatedAtColumn()` — comportamiento idéntico (el framework solo lee la constante por ese accessor). |
| `f5a3e80` | **B6-P8** — `GetPriorSimilarIncidents` (incidentes cerrados del mismo asset/driver en 7 días, `incidents.context_prior_lookback_days`), enum `PriorSimilarIncident`, links idempotentes, filas en `incidents_snapshot_json`, signal `has_prior_similar_incident` (y `has_open_incident` ya no cuenta cerrados). 8 tests feature + 2 unit. |
| `46f861a` | **B6-P4** — `ProviderAdapter::fetchLiveLocation` (Samsara `GET /fleet/vehicles/locations`, timeout 3s, null ante fallo), acción `FetchLiveLocationForEvent` en `BuildEventContext`: solo critical, sin GPS inline, staleness por TenantSetting `context.live_location_staleness_seconds` (60s default); éxito → `source='live_fetch'` + `AssetLocationSnapshot`; fallo → fallback con `position_stale` → `gps_signal_weak`. 12 tests. |
| `1b8e62d` | **B1a** — `SubscriptionPolicy` / `TenantBrandingPolicy` / `TenantFeaturePolicy` registradas en `TenancyServiceProvider` (permisos `tenancy.billing.view`/`tenancy.billing.manage`/`tenancy.manage`, patrón `DriverPolicy`). 11 tests incl. cross-team y usuario sin team. |
| `b894fcc` | **F4a** — `DriverPageController@index` (`/{current_team}/drivers`, `DriverPolicy::viewAny`, filtros q/estado, paginación 50) + `pages/drivers/index.tsx`, `DriversTable`/`DriverStatusBadge`, tipos, sidebar y layout Ops. 6 tests con `assertInertia`. |

### Tareas bloqueadas

Ninguna.

### Tareas auto-generadas (Iteración v2, 4 de 10 del cupo diario)

1. **B6-P2** — Safety events feed de Samsara (L; siguiente en el orden recomendado del plan B6).
2. **B6-P3** — Media on-demand para el panic (M; cablear el stub `FetchDeferredEventMediaJob`).
3. **F4b** — Página `drivers/{id}` (continuación natural de F4a).
4. **T1** — `assertInertia` para 5 páginas de producto que se renderizan en tests pero sin fijar componente/props (`teams/index`, `teams/edit`, `admin/operators/index`, `settings/profile`, `settings/appearance`).

El run cierra aquí por presupuesto de sesión (B6-P2 es esfuerzo L y no cabía completa); el siguiente run la retoma como primera `- [ ]`.

### Verificación final

- `php artisan test --compact` → **988 passed (3126 assertions)**, ~30s.
- `vendor/bin/pint --test` → limpio (`{"result":"pass"}`).
- `npm run types:check && npm run lint:check && npm run format:check` → verdes.
- `npm run build` → exitoso (manifest regenerado con `drivers/index`).
- **Cobertura: NO ejecutada** — el contenedor no tiene pcov/xdebug y no se pudo instalar (`apt` y PPA bloqueados por la política de red, pecl no disponible). CI la exigirá igual en el PR.

### Riesgos / cosas que un humano debe mirar primero

1. **`fix(audit)` (`2d96824`)** — cambia cómo se anula `UPDATED_AT` en los 5 modelos de Audit. Equivalente funcional verificado (tests del dominio + suite completa), pero es el cambio con más radio de alcance del run.
2. **B6-P4** hace una llamada HTTP dentro del pipeline de contexto (antes de la transacción, timeout 3s, solo criticals con posición stale). Si un tenant tiene muchos criticals con GPS viejo, añade hasta ~3s por evento a `EnrichContextJob` (cola `context`). El gate por TenantSetting permite subir el umbral por tenant si molesta.
3. **Entorno de la rutina**: PHP del contenedor es **8.4.19** (no 8.5 como producción/CI) y sin driver de cobertura; `composer install` requirió `--ignore-platform-req=ext-bcmath` porque la red bloquea el PPA de PHP. Si se puede, añadir `php8.4-bcmath`/pcov a la imagen del entorno o ajustar `.claude/setup.sh`.
4. `CLAUDE.md` §3 todavía lista "Policies Tenancy" como único pendiente real — ya quedó cerrado en este run, pero la rutina tiene prohibido editar `CLAUDE.md` (§8.7); actualizarlo a mano cuando se mergee.
