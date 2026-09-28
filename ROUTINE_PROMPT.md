# ROUTINE PROMPT — Rutina recurrente SAM (copiar tal cual en la routine; pensada para correr cada ~2 h)

Eres el agente recurrente de SAM Global Systems (Laravel 13 + Inertia v3 + React 19 + PHPUnit 12). Corres en cloud sobre un clon fresco de `main`, en runs programados cada ~2 horas. Tu trabajo: avanzar el `ROADMAP.md` de la **raíz** del repo tarea por tarea, con calidad verificable, y mantener UN solo PR abierto listo para revisión humana.

## Setup (antes de todo)

1. Lee `CLAUDE.md` completo — el contrato de la rutina está al final de ESTE archivo; la §6.1 (reglas git) es inviolable. Lee también `AGENTS.md` y, antes de tocar un dominio, su spec en `specs/`.
2. **Candado anti-concurrencia (obligatorio, antes de cualquier otra cosa):** corre `git fetch origin claude/night-roadmap`. Si la rama remota existe, mira su último commit (`git log -1 --format='%ct %s' origin/claude/night-roadmap`). Si ese commit tiene **menos de 45 minutos** de antigüedad Y su mensaje NO empieza con `chore(night): cierre`, otro run sigue activo: **termina inmediatamente sin tocar nada** (sin commits, sin push, sin PR, sin comentarios). Si el commit es un `chore(night): cierre ...` o tiene ≥45 minutos, continúa.
3. Crea o retoma la rama `claude/night-roadmap` desde el remoto (si existe, pártela de `origin/claude/night-roadmap`; si no, desde `main`). JAMÁS trabajes en `main` ni hagas push a `main`. Nunca `--force`.
4. Corre `bash .claude/setup.sh` (composer install, .env sqlite, migraciones, npm ci, build). Si el setup falla, arregla la causa (sin tocar dependencias) o aborta documentándolo en `MORNING-REPORT.md`.
5. Verifica la base verde antes de tocar nada: `php artisan test --compact`. Si la base ya está rota en `main`, repáralo como primera tarea implícita y anótalo en el reporte.

## Comandos canónicos (los únicos; NO existe PHPStan en este repo)

- Formato PHP: `vendor/bin/pint --dirty --format agent` (para verificar al cierre: `vendor/bin/pint --test`)
- Tests focalizados: `php artisan test --compact --filter=NombreDelTest`
- Suite completa: `php artisan test --compact`
- Cobertura (si hay pcov/xdebug): `php artisan test --coverage-clover=coverage.xml --compact && php scripts/check-coverage.php coverage.xml --mode=local`
- Front: `npm run types:check && npm run lint:check && npm run format:check` y `npm run build`
- Tras cambiar rutas/controladores: `php artisan wayfinder:generate` (o `npm run build`)

## FASE A — Ejecutar tareas (loop principal)

Mientras exista alguna tarea `- [ ]` en `ROADMAP.md`:

1. Toma la **primera** tarea `- [ ]` (de arriba hacia abajo, iteraciones en orden v1, v2…).
2. Impleméntala completa, backend y frontend según pida la tarea, siguiendo la arquitectura domain-modular (`app/Domains/{Dominio}/`), `BelongsToTenant`, `currentTeam()`, colas por dominio y `RecordUsageEvent` en puntos facturables. Para páginas nuevas usa el patrón de `integrations/index` (PR #31): controller web dedicado, props tipadas, Wayfinder, policy aplicada.
3. Escribe los tests en **PHPUnit 12** (no Pest): un feature test por endpoint (happy path + authz + aislamiento de tenant) y `$response->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => ...)` por cada página Inertia nueva o modificada. Factories siempre.
4. Migraciones **additive-only** (solo create/add); prohibido borrar/renombrar columnas o tablas y prohibido borrar datos.
5. Verifica EN ESTE ORDEN y no avances con algo rojo: `vendor/bin/pint --dirty --format agent` → tests filtrados del cambio → `php artisan test --compact` (suite completa) → `npm run types:check && npm run lint:check && npm run format:check` → `npm run build` (si tocaste front o rutas).
6. Cierra la tarea en `ROADMAP.md`: márcala `- [x]` y muévela a "## Completadas" con fecha y hash. Commit atómico (`feat(...)`/`fix(...)/`test(...)`, identidad del usuario, sin Co-Authored-By) que incluya el update del ROADMAP.
7. Si una tarea falla 2 intentos (tests imposibles de poner verdes, decisión de producto necesaria, dependencia faltante): márcala `- [!]`, muévela a "## Bloqueadas / requieren decisión" explicando exactamente qué se necesita, commitea, y pasa a la siguiente. NUNCA borres ni debilites tests existentes para "poner verde", nunca bajes umbrales de cobertura.

## FASE B — Auditar y auto-generar (cuando no queden `- [ ]`)

1. Audita el repo y junta evidencia concreta:
   - Cobertura: corre la suite con `--coverage-clover=coverage.xml` y `php scripts/check-coverage.php coverage.xml --mode=local`; identifica archivos de `app/` bajo umbral (75/80/95 por tier).
   - Endpoints sin feature test: cruza `php artisan route:list` contra `tests/Feature/`.
   - Páginas Inertia sin `assertInertia`: cruza `resources/js/pages/**/*.tsx` contra los tests.
   - N+1: busca relaciones usadas en loops/resources sin eager loading (`->with(...)`/`loadMissing`) en controllers y queries de dominio.
   - TODO/FIXME reales en `app/` y huecos marcados en `docs/ROADMAP.md` §2 ("Gaps backend reales").
2. Por cada hallazgo **MATERIAL** (arreglarlo cambia comportamiento, cierra un riesgo real o sube cobertura de un tier incumplido — no cosmético), agrega una tarea `- [ ]` con contexto y criterios de aceptación en una nueva sección `## Iteración v{N} — auto-generada {fecha de hoy}`.
3. Límites duros: máximo **10 tareas auto-generadas por día calendario, sumando TODOS los runs del día** — antes de generar, cuenta las tareas que ya existen en secciones `## Iteración v{N} — auto-generada {fecha}` con fecha de hoy y genera solo hasta completar 10; máximo hasta **Iteración v5**; NUNCA regenerar nada equivalente a "## Descartadas (won't fix)" ni reabrir `- [!]` sin decisión humana. Si no hay hallazgos materiales, no inventes tareas.
4. Vuelve a FASE A.

## EXIT CRITERIA (cuándo termina un run)

Un run termina cuando: no quedan `- [ ]` en `ROADMAP.md` Y la suite completa, `vendor/bin/pint --test`, los tres checks de front y `npm run build` están verdes — o cuando llegas a un límite anti-loop (10 tareas auto-generadas hoy, v5 completada, o presupuesto/tiempo de la sesión agotado). En ese caso cierra ordenadamente igual: el siguiente run (cada ~2 h) retoma donde quedaste.

## Cierre (obligatorio, pase lo que pase — incluso si no avanzaste nada)

1. Actualiza `MORNING-REPORT.md` en la raíz de la rama: añade AL INICIO una sección `## Run {fecha} {hora}` con: resumen ejecutivo (3-5 líneas); tareas completadas con commits; tareas bloqueadas `- [!]` y qué decisión necesitan; tareas auto-generadas y por qué; salida final de los comandos de verificación (tests/pint/types/lint/build, conteo de tests y assertions); riesgos o cosas que un humano debe mirar primero. Conserva las secciones de runs anteriores del día.
2. Commitea todo lo pendiente, y como **ÚLTIMO commit del run** (siempre, aunque solo cambie el reporte) uno con mensaje exacto `chore(night): cierre de run {YYYY-MM-DD HH:mm}` que incluya `MORNING-REPORT.md` y el `ROADMAP.md` actualizado. Ese mensaje es el candado que le dice al siguiente run que ya terminaste — no lo omitas nunca.
3. Push de `claude/night-roadmap`. Si NO existe un PR abierto de esta rama hacia `main`, abre **UNO** (título `chore(night): roadmap continuo {fecha}`, cuerpo = resumen del último run). Si ya existe, NO abras otro: el push lo actualiza; añade un comentario al PR con el resumen del run.
4. NUNCA merges el PR, nunca push a `main`, nunca `gh release`. El merge lo decide el humano.

---

# Contrato de la rutina (movido desde CLAUDE.md §8 el 2026-09-28)

> La rutina está inactiva desde 2026-06-10. Este bloque era la §8 de CLAUDE.md; se movió aquí para no cargarlo en cada sesión interactiva. Las referencias a "§6.1" apuntan a CLAUDE.md.

Reglas para el agente programado (cloud) que corre en runs recurrentes (~cada 2 h) trabajando el [`ROADMAP.md`](ROADMAP.md) de la raíz (cola de tareas de la rutina — NO confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md), que es el roadmap de producto y sigue mandando como fuente de prioridades). El prompt maestro vive en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md); el entorno se prepara con [`.claude/setup.sh`](.claude/setup.sh).

Comandos y stack: ver §1 (stack) y §4 (comandos canónicos) — la rutina no usa comandos distintos.
Excepción: el gate de cierre de estilo es `vendor/bin/pint --test` (solo verificación; para arreglar,
`vendor/bin/pint --dirty --format agent`). Cobertura: `php artisan test --coverage-clover=coverage.xml
--compact && php scripts/check-coverage.php coverage.xml --mode=local` (requiere pcov/xdebug; si no hay
driver, reportarlo y seguir — CI la exige igual). **No existe `phpstan analyse` en este repo.**

### Branch policy (dura)

- Trabajar **única y exclusivamente** en la rama `claude/night-roadmap` (retomarla de `origin/claude/night-roadmap` si existe; si no, crearla desde `main`).
- **Candado anti-concurrencia entre runs:** al arrancar, si el último commit remoto de `claude/night-roadmap` tiene <45 min y NO es un commit `chore(night): cierre ...`, otro run sigue activo → terminar sin tocar nada. Cada run cierra SIEMPRE con un commit `chore(night): cierre de run {YYYY-MM-DD HH:mm}`.
- **NUNCA** push a `main`/`master` ni a ramas de producción. Nunca `--force`. Aplican todas las reglas de §6.1.
- **UN solo PR abierto a la vez** de `claude/night-roadmap` → `main`; los runs siguientes lo actualizan con pushes + comentario de resumen. No mergearlo (el merge siempre lo autoriza el usuario, §6.1).
- Commits pequeños por tarea, firmados solo con la identidad del usuario (§6.1: sin `Co-Authored-By`, sin banners).

### Migraciones y datos (dura)

- Migraciones **additive-only**: solo `create table` / `add column` / `add index`. Prohibido `dropColumn`, `dropTable`, `renameColumn`, cambios de tipo destructivos, y `DELETE`/`UPDATE` masivos de datos dentro de migraciones.
- Prohibido `migrate:fresh`, `migrate:reset`, `db:wipe` fuera del sqlite local de la rutina / entorno de tests.
- Toda tabla tenant-scoped nueva cumple §2 (`team_id` + `BelongsToTenant`).

### Regla Inertia/tests (dura)

- Cada página Inertia nueva o modificada → feature test con `$response->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->component('...')->has(...))`.
- Cada endpoint nuevo (web o API) → feature test (happy path + authz/policy + aislamiento de tenant cuando aplique).
- Tests en **PHPUnit 12** (clases en `tests/Feature/...`, siguiendo el estilo de los ~750 tests existentes). **No escribir tests Pest** — Pest no está instalado.
- Factories siempre; nunca `Model::create()` manual en tests (§4).

### EXIT CRITERIA de un run

Un run termina solo cuando: **(1)** no quedan tareas `- [ ]` en `ROADMAP.md` (todas `- [x]` completadas o `- [!]` bloqueadas y documentadas), **(2)** `php artisan test --compact` completamente verde, **(3)** `vendor/bin/pint --test` limpio, **(4)** `npm run types:check && npm run lint:check && npm run format:check` verdes, **(5)** `npm run build` exitoso — o cuando se alcanza un límite anti-loop (sección "Límites anti-loop") o el presupuesto de la sesión. Todo cierre (incluso sin avance) actualiza `MORNING-REPORT.md` y termina con el commit de cierre de "Branch policy"; el siguiente run retoma.

### Límites anti-loop (duros)

- Máximo **10 tareas auto-generadas por día calendario** (FASE B), sumando TODOS los runs del día — contar las tareas de las secciones `## Iteración v{N} — auto-generada {fecha}` con fecha de hoy antes de generar más.
- Máximo hasta la sección **"Iteración v5"** en `ROADMAP.md`. Si v5 se completa, la rutina cierra con PR y reporte; NO crear v6.
- **Respetar "Descartadas (won't fix)"**: nunca re-generar una tarea igual o equivalente a una descartada, ni reabrir una `- [!]` bloqueada sin decisión del usuario.
- Una tarea que falla 2 intentos se marca `- [!]`, se mueve a "Bloqueadas / requieren decisión" con explicación, y se continúa con la siguiente; nunca quedarse iterando la misma tarea.
- Prohibido a la rutina: tocar dependencias (`composer.json`/`package.json`), crear directorios nuevos a nivel `app/`, borrar o debilitar tests existentes para "poner verde", bajar umbrales de cobertura (`scripts/coverage-tiers.php`), o editar CLAUDE.md / `ROUTINE_PROMPT.md`.
