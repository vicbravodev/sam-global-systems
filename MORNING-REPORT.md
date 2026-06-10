# MORNING REPORT — rutina nocturna `claude/night-roadmap`

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
