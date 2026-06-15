# Remediación de usabilidad — Fase C (P1 navegación) Implementation Plan

> **For agentic workers:** ejecutar tarea por tarea. Esta fase reorganiza navegación y renombra
> entradas solapadas. C2 (agrupar configuración) y C3 (retirar "Nuevo equipo") ya están
> **decididas con el usuario** en el spec §4 — no re-preguntar esas decisiones. Steps en checkbox.

**Goal:** Eliminar los conceptos solapados de navegación: (C1) "Notificaciones" significa 3 cosas
en 3 lugares → renombrar por función; (C2) cuatro entradas de "configuración" solapadas → agrupar
bajo un solo menú "Ajustes"; (C3) retirar "Nuevo equipo" de Cuenta→Equipos (Team = tenant; crear
tenants es exclusivo del superadmin).

**Architecture:** Cambios en componentes de navegación React + el layout de settings. C3 además
retira una ruta de creación de equipos del flujo de usuario (la ruta `teams.store` puede
conservarse para el superadmin/seed pero deja de exponerse en la UI de cuenta). No se crean
modelos ni migraciones. Los feature tests `assertInertia` de las páginas tocadas son la red de
regresión; se añaden tests para los cambios observables (label nuevo, ausencia del botón).

**Tech Stack:** Inertia v3 + React 19 + TS · Laravel routes · PHPUnit 12.

**Hechos del código (de la exploración):**
- Sidebar tenant: `resources/js/components/sam/ops-sidebar.tsx`. Grupo "Configuración" con:
  Integraciones, **Notificaciones** (`/{team}/notifications`, bandeja), Auditoría, Facturación,
  **Equipo y roles** (`/{team}/settings/roles`), **Configuración** (`/{team}/settings/tenant-config`).
- Sub-nav de Cuenta: `resources/js/layouts/settings/layout.tsx` con Perfil, Seguridad, Equipos,
  Apariencia, **Notificaciones** (`/settings/notifications`, preferencias de usuario).
- Tenant-config: `resources/js/pages/settings/tenant-config.tsx`, tab **Notificaciones** (política).
- Equipos: `resources/js/pages/teams/index.tsx`, botón **"Nuevo equipo"** vía
  `resources/js/components/create-team-modal.tsx` → `POST /settings/teams` (`teams.store`).
- Test equipos: `tests/Feature/Teams/TeamTest.php` (`test_teams_can_be_created`).

**Gate de cierre:** `vendor/bin/phpunit --no-coverage` verde · `vendor/bin/pint --test` limpio ·
`npm run types:check && lint:check && format:check` verdes · `npm run build` · tras tocar rutas:
`php artisan wayfinder:generate`.

---

## Task C3: retirar "Nuevo equipo" de Cuenta→Equipos (el más acotado, primero)

**Files:**
- Modify: `resources/js/pages/teams/index.tsx` (quitar botón/CTA "Nuevo equipo")
- Modify: `resources/js/components/create-team-modal.tsx` (deja de usarse en cuenta; evaluar si se
  elimina su import en teams/index o se conserva el componente sin montar)
- Test: `tests/Feature/Teams/TeamTest.php` (+ test de que la página no ofrece crear)

- [x] **Step 1: Baseline** — `vendor/bin/phpunit --no-coverage --filter=TeamTest` verde. Anotar que
  `test_teams_can_be_created` postea a `teams.store`.
- [x] **Step 2: Test que falla** — añadir a un test de la página de equipos (Inertia) una aserción
  de que el prop/flag que habilita "crear equipo" es false o que la página ya no expone el modal.
  Como es UI, el test robusto es a nivel de prop: si la página recibe un flag `canCreateTeam`,
  pasarlo `false` para usuarios no-superadmin y assertarlo. Si no existe tal prop, el test cubre la
  ruta: un usuario normal que postea a `teams.store` recibe 403 (la creación pasa a ser exclusiva
  del superadmin). Confirmar fallo.
- [x] **Step 3: Implementación** —
  - Frontend: quitar el botón "Nuevo equipo" y el `CreateTeamModal` de `teams/index.tsx`. La página
    pasa a ser solo lista/gestión de membresías existentes.
  - Backend: en `TeamController::store` (o su policy/middleware), autorizar solo a superadmin
    (`super_admin` role / `Gate`), devolviendo 403 al resto. Mantener la ruta para el flujo
    superadmin/seed. Si ya existe una `TeamPolicy`, añadir `create()` que solo permita superadmin.
  - Decidir sobre `create-team-modal.tsx`: si queda sin uso, retirar su import; conservar el archivo
    solo si lo usa el superadmin en otra vista.
- [x] **Step 4: Verde** — `vendor/bin/phpunit --no-coverage --filter=TeamTest` verde (ajustar
  `test_teams_can_be_created` para actuar como superadmin, o renombrarlo a reflejar la nueva regla).
- [x] **Step 5: tipos + format + commit**
```bash
npm run types:check && npx prettier --write resources/js/pages/teams/index.tsx
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "fix(teams): retirar 'Nuevo equipo' de cuenta; crear tenants es exclusivo del superadmin (C3)"
```

---

## Task C1: desambiguar "Notificaciones"

Tres entradas con el mismo nombre. Renombrar por función (manteniendo es-ES):
- Sidebar `/{team}/notifications` (bandeja del tenant): **"Notificaciones"** → **"Bandeja de notificaciones"**.
- Cuenta `/settings/notifications` (preferencias del usuario): sub-nav **"Notificaciones"** → **"Mis notificaciones"** (la página ya titula "Preferencias de notificación").
- Tenant-config tab (política del tenant): **"Notificaciones"** → **"Política de notificaciones"**.

**Files:**
- Modify: `resources/js/components/sam/ops-sidebar.tsx`
- Modify: `resources/js/layouts/settings/layout.tsx`
- Modify: `resources/js/pages/settings/tenant-config.tsx`
- Test: la página/tab afectada (tenant-config ya tiene feature test; sidebar/layout son strings).

- [x] **Step 1: Localizar los 3 labels** — `grep -n "Notificaciones" resources/js/components/sam/ops-sidebar.tsx resources/js/layouts/settings/layout.tsx resources/js/pages/settings/tenant-config.tsx`
- [x] **Step 2: Renombrar** los tres strings según el mapeo de arriba. No cambian rutas ni props.
- [x] **Step 3 (si hay test de tenant-config tabs):** extender el feature test del tenant-config
  para assertar que el label de la sección de política es "Política de notificaciones" (si el label
  viaja como prop/objeto de tabs). Si los labels son puramente de frontend, basta `tsc` + build.
- [x] **Step 4: tipos + format + commit**
```bash
npm run types:check && npx prettier --write resources/js/components/sam/ops-sidebar.tsx resources/js/layouts/settings/layout.tsx resources/js/pages/settings/tenant-config.tsx
git add -A
git commit -m "fix(nav): desambiguar las tres 'Notificaciones' por función (C1)"
```

---

## Task C2: agrupar la configuración bajo un solo menú "Ajustes" (DECIDIDO)

Hoy hay cuatro entradas solapadas: **Configuración** (tenant-config), **Cuenta** (perfil/seguridad/
apariencia/preferencias/equipos), **Equipo y roles**, **Equipos**. Decisión del usuario (spec §4):
agrupar bajo un solo menú **"Ajustes"** con sub-secciones, en vez de entradas sueltas.

**Enfoque conservador y testeable:** introducir en el sidebar una sola entrada **"Ajustes"** que
lleva a un hub de settings, y consolidar la sub-navegación de settings para que cubra tanto las
secciones de **tenant** (Configuración del tenant, Equipo y roles) como las de **cuenta** (Perfil,
Seguridad, Apariencia, Mis notificaciones, Equipos). No se borran páginas ni rutas; se reorganiza
solo la navegación y se retiran del grupo "Configuración" del sidebar las entradas que pasan a
vivir dentro de "Ajustes" (Equipo y roles, Configuración del tenant). Integraciones, Auditoría,
Facturación y la Bandeja de notificaciones permanecen como entradas operativas del sidebar.

**Files:**
- Modify: `resources/js/components/sam/ops-sidebar.tsx` (una entrada "Ajustes")
- Modify: `resources/js/layouts/settings/layout.tsx` (sub-nav unificada tenant + cuenta)
- Posible: un componente/hub `resources/js/pages/settings/index.tsx` SOLO si hace falta una landing;
  evaluar reutilizar el layout existente sin crear página nueva.
- Test: feature test de que la entrada "Ajustes" y sus sub-secciones resuelven a las páginas correctas.

- [x] **Step 1: Diseño de la sub-nav unificada** — listar las secciones finales y a qué ruta apunta
  cada una (tenant: `settings/tenant-config`, `settings/roles`; cuenta: `profile`, `security`,
  `appearance`, `notifications` (Mis notificaciones), `teams`). Agruparlas visualmente en el layout
  de settings en dos bloques: "Cuenta" y "Tenant".
- [x] **Step 2: Sidebar** — reemplazar en `ops-sidebar.tsx` las entradas "Equipo y roles" y
  "Configuración" por una sola **"Ajustes"** (icono settings) que apunte a la primera sub-sección
  (p.ej. `settings/tenant-config` para roles tenant, o `profile` para cuenta — elegir landing
  estable y consistente con permisos). Mantener Integraciones/Auditoría/Facturación/Bandeja.
- [x] **Step 3: Layout de settings** — en `layouts/settings/layout.tsx` añadir las secciones tenant
  (Configuración del tenant, Equipo y roles) junto a las de cuenta, separadas por encabezado de
  grupo. Respetar autorización: las secciones tenant solo se muestran a quien tenga el permiso
  correspondiente (reutilizar los flags `can*` que ya pasan las páginas).
- [x] **Step 4: Tests** — feature test (Inertia) que verifique que las páginas de settings tenant y
  cuenta siguen resolviendo (componentes correctos) tras la reorganización, y que el sidebar ya no
  duplica entradas de configuración. Como la mayor parte es markup de nav, el test cubre que las
  rutas existentes siguen vivas y que la autorización por sección se respeta (un usuario sin permiso
  de config no ve la sección tenant). Factories siempre.
- [x] **Step 5: tipos + format + (wayfinder si cambian rutas) + commit**
```bash
npm run types:check && npm run lint:check
npx prettier --write resources/js/components/sam/ops-sidebar.tsx resources/js/layouts/settings/layout.tsx
git add -A
git commit -m "feat(nav): agrupar configuración bajo un solo menú 'Ajustes' con sub-secciones (C2)"
```

> **Nota de alcance:** si durante la implementación C2 exige mover páginas a rutas nuevas o un
> refactor grande del routing (más allá de reorganizar la nav y la sub-nav), PARAR y consultar al
> usuario antes de proceder (regla del loop: cambios arquitectónicos significativos). El diseño de
> arriba es deliberadamente conservador para evitarlo.

---

## Cierre de la Fase C (gate completo)

- [x] `vendor/bin/phpunit --no-coverage` verde.
- [x] `vendor/bin/pint --test` limpio.
- [x] `npm run types:check && npm run lint:check && npm run format:check` verdes.
- [x] `npm run build` exitoso.
- [x] (si se tocaron rutas) `php artisan wayfinder:generate`.
- [x] (opcional) `node scripts/audit-ux.mjs --base=http://localhost` — confirmar nav sin duplicados.

---

## Self-Review

- **Cobertura del spec (Fase C):** C1 desambiguación de notificaciones, C2 agrupar "Ajustes"
  (decidido), C3 retirar "Nuevo equipo" + creación de tenant exclusiva del superadmin (decidido).
- **Orden:** C3 (acotado) → C1 (strings) → C2 (reorganización mayor) para minimizar riesgo.
- **Punto de pausa:** C2 si requiere refactor de routing grande → AskUserQuestion.
