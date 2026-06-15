# Remediación de usabilidad — Fase D (P2 móvil + polish) Implementation Plan

> **For agentic workers:** ejecutar tarea por tarea. Mayoritariamente CSS/markup responsive en
> React. Los feature tests `assertInertia` existentes son la red de regresión. Steps en checkbox.

**Goal:** Cerrar los P2 de móvil y polish: (D1) variante card-row para tablas densas en `< md`;
(D2) ocultar el footer de atajos de teclado en táctil; (D3) arreglar el encimado GRUPO/VALOR en
config móvil; (D4) encabezados que no envuelven feo en móvil; (D5) arreglar el ícono de proveedor
roto; (D6) unificar los KPIs del admin con la franja cockpit del dashboard.

**Architecture:** Cambios de presentación responsive. El cambio con más alcance es D1 (variante
card-row), que se concentra en el `DataTable` compartido para que conductores/eventos/activos se
beneficien a la vez, más los casos con `<table>` nativa (incidentes, admin/tenants). Sin
dependencias nuevas ni cambios de dominio.

**Tech Stack:** Inertia v3 + React 19 + TS · Tailwind v4 (breakpoint `md`) · PHPUnit 12.

**Hechos del código (de la exploración):**
- DataTable compartido: `resources/js/components/sam/data-table/data-table.tsx` (sin variante
  móvil; `min-w-[640px]` fuerza scroll). Lo usan eventos (directo), conductores y activos (vía
  wrappers `drivers-table`/`assets-table`).
- Incidentes: `resources/js/pages/incidents/index.tsx` — `InboxTable` nativa con `min-w-[760px]`;
  footer de atajos `InboxFooter` (líneas ~502-521) **siempre visible**.
- Admin tenants: `resources/js/pages/admin/tenants/index.tsx` — `<table>` nativa + `StatCard` (4
  tarjetas sueltas, grid `grid-cols-2 sm:grid-cols-4`).
- Tenant-config: `resources/js/pages/settings/tenant-config.tsx` — tabla "Otros settings" con
  columnas Setting/Grupo/Valor/v sin anchos → encimado en móvil.
- Header: `resources/js/components/ui/page-header.tsx` (`<h1 class="sam-h2 truncate">`); incidentes
  usa header inline sin truncate.
- Provider tag: `resources/js/components/sam/provider-tag.tsx` es un **badge de texto**, no imagen.
  El "cuadro rojo de imagen rota" del spec NO se localizó como `<img>` aquí → D5 empieza por
  localizar el `<img>` real (puede estar en automation/rules/assets con un `src` que 404ea).
- KpiCard/KpiGrid del dashboard: `resources/js/pages/dashboard.tsx` (~líneas 211-351).

**Gate de cierre:** `vendor/bin/phpunit --no-coverage` verde · `vendor/bin/pint --test` limpio ·
`npm run types:check && lint:check && format:check` verdes · `npm run build`.

---

## Task D2: ocultar footer de atajos en táctil (rápido, primero)

**Files:** Modify `resources/js/pages/incidents/index.tsx`.

- [ ] **Step 1:** localizar `InboxFooter` (`grep -n "InboxFooter\|sam-kbd\|navegar" resources/js/pages/incidents/index.tsx`).
- [ ] **Step 2:** envolver la tira de atajos (J/K/A/X/Enter) en `hidden md:flex` (o `max-md:hidden`)
  para que no aparezca en `< md` / táctil. Mantener el resto del footer (conteos, etc.) visible.
- [ ] **Step 3:** `npm run types:check`; regresión `vendor/bin/phpunit --no-coverage --filter=Incident` verde.
- [ ] **Step 4:** format + commit
```bash
npx prettier --write resources/js/pages/incidents/index.tsx
git add resources/js/pages/incidents/index.tsx
git commit -m "fix(incidents): ocultar atajos de teclado en móvil/táctil (D2)"
```

---

## Task D3: config móvil — arreglar encimado GRUPO/VALOR

**Files:** Modify `resources/js/pages/settings/tenant-config.tsx`.

- [ ] **Step 1:** localizar la tabla "Otros settings" (`grep -n "Grupo\|Valor\|Otros settings\|<table" resources/js/pages/settings/tenant-config.tsx`).
- [ ] **Step 2:** dos opciones (elegir la más simple que no rompa desktop):
  (a) envolver la tabla en `overflow-x-auto` con `min-w` explícito (scroll contenido, no de página); o
  (b) en `< md`, stack: ocultar `<thead>` y renderizar cada fila como bloque etiqueta→valor
  (`block md:table-row` + `before:content` o labels visibles). Preferir (a) por simplicidad si basta.
- [ ] **Step 3:** asignar anchos a columnas (`w-*`) para evitar que Grupo/Valor se peleen el espacio.
- [ ] **Step 4:** `npm run types:check`; regresión del feature test de tenant-config verde.
- [ ] **Step 5:** format + commit
```bash
npx prettier --write resources/js/pages/settings/tenant-config.tsx
git add resources/js/pages/settings/tenant-config.tsx
git commit -m "fix(tenant-config): tabla de settings sin encimado en móvil (D3)"
```

---

## Task D4: encabezados que no envuelven feo en móvil

**Files:** Modify `resources/js/components/ui/page-header.tsx` y el header inline de
`resources/js/pages/incidents/index.tsx`.

- [ ] **Step 1:** en `page-header.tsx`, garantizar `min-w-0` en el contenedor del título y que el
  `<h1 truncate>` realmente trunque (el padre flex necesita `min-w-0`/`flex-1`). En `< md` permitir
  `text-base`/tamaño menor si el título es largo.
- [ ] **Step 2:** migrar el header inline de incidentes ("Bandeja de incidentes") a `PageHeader`
  (o aplicarle las mismas clases) para que no envuelva a 3 líneas.
- [ ] **Step 3:** `npm run types:check`; regresión incidentes verde.
- [ ] **Step 4:** format + commit
```bash
npx prettier --write resources/js/components/ui/page-header.tsx resources/js/pages/incidents/index.tsx
git add -A
git commit -m "fix(ui): encabezados truncan/encajan en móvil en vez de envolver (D4)"
```

---

## Task D5: ícono de proveedor roto

**Files:** por localizar (automation/rules/assets + componente de logo de proveedor).

- [ ] **Step 1: localizar el `<img>` real** — `grep -rn "<img\|provider.*logo\|logoUrl\|iconUrl\|provider_icon" resources/js/pages/automation resources/js/pages/rules resources/js/pages/assets resources/js/components/sam`
  El `ProviderTag` es texto; el cuadro rojo viene de un `<img src=...>` que 404ea en otro sitio.
- [ ] **Step 2:** según lo hallado:
  - Si hay un `<img>` con `src` de logo de proveedor que no existe → sustituir por `ProviderTag`
    (badge de texto, ya consistente) o por un `<img>` con `onError` que caiga a un placeholder/ícono
    genérico (no cuadro rojo).
  - Si no se encuentra ningún `<img>` (el artefacto era de datos del seeder), DOCUMENTAR en este
    plan como no-reproducible en código y marcar `- [!]` con la evidencia del grep.
- [ ] **Step 3:** `npm run types:check`; regresión de las páginas afectadas verde.
- [ ] **Step 4:** format + commit (si hubo cambio)
```bash
git add -A
git commit -m "fix(ui): ícono de proveedor con fallback en vez de imagen rota (D5)"
```

---

## Task D6: unificar KPIs del admin con la franja cockpit

**Files:** Modify `resources/js/pages/admin/tenants/index.tsx`; reutilizar el patrón `KpiGrid`/
`KpiCard`. Si se extrae a componente compartido, crear `resources/js/components/sam/kpi-strip.tsx`
SOLO si lo consumen ≥2 páginas (dashboard + admin) — extracción justificada.

- [ ] **Step 1:** decidir extracción: mover `KpiCard`/`KpiGrid` de `dashboard.tsx` a
  `resources/js/components/sam/kpi-strip.tsx` (export reutilizable) y que dashboard lo importe.
  Verificar que no rompe el dashboard (regresión `DashboardTest`).
- [ ] **Step 2:** en admin/tenants, reemplazar las 4 `StatCard` sueltas por la franja `KpiStrip`
  (mismas 4 métricas: Tenants, Activos, Trial, Morosos), con el look cockpit (`gap-px`, borde único,
  `bg-surface-1` por celda). Sin sparkline si no hay serie (el componente debe soportar métrica sin
  serie).
- [ ] **Step 3:** tests — regresión `DashboardTest` (extracción no rompe) + feature test de
  admin/tenants (`assertInertia` del componente `admin/tenants/index`) verde.
- [ ] **Step 4:** `npm run types:check && lint:check`; format + commit
```bash
npx prettier --write resources/js/pages/admin/tenants/index.tsx resources/js/pages/dashboard.tsx resources/js/components/sam/kpi-strip.tsx
git add -A
git commit -m "fix(admin): KPIs del admin con la franja cockpit unificada del dashboard (D6)"
```

---

## Task D1: variante card-row para tablas densas en `< md` (el de mayor alcance, al final)

**Files:** Modify `resources/js/components/sam/data-table/data-table.tsx` (núcleo) + verificación en
eventos/conductores/activos; `resources/js/pages/incidents/index.tsx` (InboxTable nativa) y
`resources/js/pages/admin/tenants/index.tsx` (tabla nativa).

- [ ] **Step 1: DataTable — variante card-row** — en `data-table.tsx`, en `< md` renderizar cada
  fila como tarjeta apilada (label de columna + valor) en vez de `<table>` con scroll horizontal.
  Aprovechar que las columnas ya declaran `header`/`cell`: en móvil mapear cada fila a un bloque
  con `grid grid-cols-[auto_1fr]` (header → cell). Mantener la tabla `md:` intacta. Respetar
  `onRowClick`, densidad y `empty`.
- [ ] **Step 2:** verificar eventos/conductores/activos (consumen DataTable) — sus feature tests
  `assertInertia` deben seguir verdes (cambio es solo de render). `grep` para confirmar que ninguno
  dependa de la estructura `<table>` en aserciones.
- [ ] **Step 3: Incidentes** — `InboxTable` es nativa con `min-w-[760px]`. Darle variante card-row
  en `< md` (cada incidente como tarjeta con severidad/título/SLA), preservando selección y
  navegación por teclado en `md+`.
- [ ] **Step 4: Admin tenants** — `<table>` nativa; aplicar el mismo patrón card-row en `< md`.
- [ ] **Step 5:** regresión completa de las páginas tocadas
  (`vendor/bin/phpunit --no-coverage --filter="Incident|Event|Driver|Asset|Tenant"`) verde.
- [ ] **Step 6:** `npm run types:check && lint:check`; format + commit
```bash
npx prettier --write resources/js/components/sam/data-table/data-table.tsx resources/js/pages/incidents/index.tsx resources/js/pages/admin/tenants/index.tsx
git add -A
git commit -m "feat(ui): variante card-row para tablas densas en móvil (D1)"
```

---

## Cierre de la Fase D (gate completo)

- [ ] `vendor/bin/phpunit --no-coverage` verde.
- [ ] `vendor/bin/pint --test` limpio.
- [ ] `npm run types:check && npm run lint:check && npm run format:check` verdes.
- [ ] `npm run build` exitoso.
- [ ] (opcional) `node scripts/audit-ux.mjs --base=http://localhost` en viewport móvil — confirmar
  card-rows, sin atajos en táctil, headers que encajan, KPIs unificados.

---

## Self-Review

- **Cobertura del spec (Fase D):** D1 card-row (Task D1), D2 atajos en táctil (D2), D3 config móvil
  (D3), D4 headers (D4), D5 ícono roto (D5, con ruta a `- [!]` si no es reproducible en código),
  D6 KPIs admin (D6).
- **Orden:** quick wins primero (D2/D3/D4), luego D5/D6, y D1 (mayor alcance) al final.
- **Riesgo:** D1 toca el DataTable compartido → la regresión de eventos/conductores/activos es la
  guardia. D5 puede resultar no-reproducible (era artefacto de datos): documentar en vez de forzar.
