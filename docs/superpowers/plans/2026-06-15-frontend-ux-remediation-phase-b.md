# Remediación de usabilidad — Fase B (P1 distribución) Implementation Plan

> **For agentic workers:** ejecutar tarea por tarea. Los cambios de esta fase son
> mayoritariamente de **layout/markup en React** (sin tocar dominio ni props nuevas salvo donde
> se indique). Para cada página tocada, el feature test `assertInertia` existente actúa como
> guardia de regresión y debe quedar verde; se añaden aserciones nuevas solo donde se introduzcan
> props nuevas. Steps en checkbox (`- [ ]`).

**Goal:** Recuperar el espacio mal distribuido en Facturación, Analítica, Dashboard y Detalle de
conductor: que cada pantalla use su alto/ancho con densidad útil en vez de tarjetas de una línea,
empty-states duplicados y huecos muertos.

**Architecture:** Cambios de presentación en los componentes Inertia. No se añaden dependencias
ni props de backend salvo que se indique explícitamente. Se reutilizan `Card`, `EmptyState` y los
tokens/utilidades Tailwind ya existentes. Donde un patrón se repite (tira de métricas), se extrae
un componente pequeño bajo `resources/js/components/sam/` SOLO si reduce duplicación real (no
crear abstracciones especulativas).

**Tech Stack:** Inertia v3 + React 19 + TypeScript · Tailwind v4 · PHPUnit 12 (sqlite `:memory:`).

**Gate de cierre de fase:** `vendor/bin/phpunit --no-coverage` (o `php artisan test --compact` con
driver de cobertura) verde, `vendor/bin/pint --test` limpio, `npm run types:check && lint:check &&
format:check` verdes, `npm run build` exitoso.

---

## File Structure

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `resources/js/pages/billing/index.tsx` | Layout de Facturación | Modificar |
| `resources/js/pages/analytics/index.tsx` | Empty-states de Analítica | Modificar |
| `resources/js/pages/dashboard.tsx` | Hueco inferior / panel de consumo | Modificar |
| `resources/js/pages/drivers/show.tsx` | Presencia de identidad en detalle vacío | Modificar |
| `tests/Feature/Domains/Tenancy/BillingBrandingPageTest.php` | Guardia de regresión billing | Verificar/extender |
| `tests/Feature/Domains/Analytics/AnalyticsPageTest.php` | Guardia de regresión analytics | Verificar/extender |
| `tests/Feature/DashboardTest.php` | Guardia de regresión dashboard | Verificar/extender |
| `tests/Feature/Domains/Drivers/DriverShowPageTest.php` | Guardia de regresión driver detail | Verificar/extender |

---

## Task B1: Facturación — grid compacto en vez de tarjetas de una línea

El spec (`billing--desktop--dark.png`): tarjetas a todo el ancho con una sola línea de texto cada
una. Objetivo: agrupar la información de cabecera (plan, estado, precio, próxima renovación) en un
grid compacto 2×2 de métricas, dejando las tablas (consumo, facturas) a ancho completo debajo.

**Files:**
- Modify: `resources/js/pages/billing/index.tsx`
- Test: `tests/Feature/Domains/Tenancy/BillingBrandingPageTest.php`

- [ ] **Step 1: Guardia de regresión**

Run: `vendor/bin/phpunit --no-coverage --filter=BillingBrandingPageTest`
Expected: verde antes de tocar nada (baseline). Anotar los props que asserta (`subscription`,
`usage`, `features`, `invoices`) para no romperlos.

- [ ] **Step 2: Refactor de layout (sin cambiar props)**

En `billing/index.tsx`, sustituir las tarjetas-de-una-línea de la cabecera de plan por una tira
compacta de métricas (grid `grid-cols-2 md:grid-cols-4` o `2x2`), reutilizando el patrón visual de
`KpiCard` del dashboard (bloque `bg-surface-1 px-4 py-3` sin borde por celda, separadores `gap-px`
sobre `bg-border`). Las cuatro métricas de cabecera: **Plan**, **Estado**, **Precio base /
ciclo**, **Próxima renovación**. Mantener las tablas de **Consumo del periodo** y **Facturas** a
ancho completo debajo. La tarjeta de soporte ("¿Dudas con tu facturación?") pasa a una franja
delgada al pie (no una Card a ancho completo).

- [ ] **Step 3: tipos + formato + regresión**

Run: `npm run types:check` → sin errores.
Run: `vendor/bin/phpunit --no-coverage --filter=BillingBrandingPageTest` → verde (mismos props).

- [ ] **Step 4: format + commit**

```bash
npx prettier --write resources/js/pages/billing/index.tsx
git add resources/js/pages/billing/index.tsx
git commit -m "fix(billing): tira compacta de métricas de plan en vez de tarjetas de una línea (B1)"
```

---

## Task B2: Analítica — fusionar empty-states duplicados

El spec (`analytics--desktop--dark.png`): dos empty-states apilados que dicen casi lo mismo ("se
calculan cada noche"). Objetivo: un solo empty-state claro por pestaña, recuperando el hueco.

**Files:**
- Modify: `resources/js/pages/analytics/index.tsx`
- Test: `tests/Feature/Domains/Analytics/AnalyticsPageTest.php`

- [ ] **Step 1: Guardia de regresión**

Run: `vendor/bin/phpunit --no-coverage --filter=AnalyticsPageTest` → verde baseline.

- [ ] **Step 2: Localizar los dos empty-states**

Run: `grep -n "EmptyState\|se calculan\|cada noche\|BarChart3\|FileBarChart2" resources/js/pages/analytics/index.tsx`
En la pestaña KPIs, "Resumen del tenant" y "KPIs recientes" muestran cada uno su propio empty-state
con mensaje casi idéntico cuando no hay datos.

- [ ] **Step 3: Fusionar (KPIs tab)**

Cuando NO hay overview ni kpis, renderizar **un solo** `EmptyState` (no dos Cards apiladas), con
copy único y claro ("Las métricas se calculan cada noche; aún no hay datos para este periodo."),
ocupando el ancho de la pestaña. Cuando sí hay datos, mantener las dos secciones. Igual criterio
en la pestaña Reportes si presenta empty-states duplicados ("Reportes disponibles" vs
"Ejecuciones recientes"): si ambos están vacíos, un solo mensaje.

- [ ] **Step 4: tipos + formato + regresión**

Run: `npm run types:check` → sin errores.
Run: `vendor/bin/phpunit --no-coverage --filter=AnalyticsPageTest` → verde.

- [ ] **Step 5: format + commit**

```bash
npx prettier --write resources/js/pages/analytics/index.tsx
git add resources/js/pages/analytics/index.tsx
git commit -m "fix(analytics): un solo empty-state por pestaña en vez de dos duplicados (B2)"
```

---

## Task B3: Dashboard — cerrar el hueco inferior

El spec (`dashboard--desktop--dark.png`): el ~40% inferior queda vacío bajo "Integraciones"; el
panel "Consumo" no aparece y las columnas quedan desbalanceadas. El controller YA pasa `usage`
(`UsageCounterRow[]`) pero `UsagePanel` solo se renderiza si `usage.length > 0`. Objetivo: que la
columna izquierda llene el alto — mostrar "Uso del plan" siempre (con empty-state honesto cuando
no haya contadores) y rebalancear para que la franja viva ocupe el alto disponible.

**Files:**
- Modify: `resources/js/pages/dashboard.tsx`
- Test: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Guardia de regresión**

Run: `vendor/bin/phpunit --no-coverage --filter=DashboardTest` → verde baseline. Anota que asserta
`kpis`, `incidents`, `stream`, `integrations`, `usage`.

- [ ] **Step 2: "Uso del plan" siempre presente**

Run: `grep -n "usage\|UsagePanel\|Integraciones\|Uso del plan" resources/js/pages/dashboard.tsx`
Cambiar el guard que oculta el panel cuando `usage.length === 0`: en vez de `return null`,
renderizar la Card "Uso del plan" con un `EmptyState` compacto ("Sin consumo medido en este
periodo todavía."). Así la columna izquierda (Incidentes + Integraciones + Uso del plan) llena el
alto y se elimina el hueco muerto.

- [ ] **Step 3: Rebalance del grid si queda hueco**

Si tras el Step 2 sigue habiendo hueco en desktop, ajustar el grid inferior
(`lg:grid-cols-[2fr_1fr]`) para que el "Stream en vivo" (columna derecha) estire su alto
(`h-full`/`flex-1`) hasta el borde de la fila, evitando el bloque vacío. No añadir datos ficticios.

- [ ] **Step 4: Test de que el panel de uso aparece aun sin contadores**

Añadir a `DashboardTest` un test (o extender el existente) que cargue el dashboard SIN usage
counters y asserte vía `assertInertia` que el componente sigue siendo `dashboard` y que `usage`
está presente como array (`->has('usage')`). Como el cambio es de markup, el valor del test es de
regresión; no se inventan props.

- [ ] **Step 5: tipos + formato + regresión**

Run: `npm run types:check` → sin errores.
Run: `vendor/bin/phpunit --no-coverage --filter=DashboardTest` → verde.

- [ ] **Step 6: format + commit**

```bash
npx prettier --write resources/js/pages/dashboard.tsx
git add resources/js/pages/dashboard.tsx tests/Feature/DashboardTest.php
git commit -m "fix(dashboard): panel de uso siempre visible y columna que llena el alto (B3)"
```

---

## Task B4: Detalle de conductor — presencia de identidad

El spec (`detail-drivers--desktop--dark.png`): 95% vacío cuando el conductor recién sincronizado
no tiene datos operativos. El header ya muestra nombre/estado/código/última conexión, pero el
cuerpo es solo una caja "sin datos". Objetivo: dar cuerpo con una tarjeta de **Identidad** (nombre
completo, código de empleado, ID externo primario, primera/última conexión, activo actual) que
siempre tiene contenido, de modo que la página aporte aun sin historial.

**Files:**
- Modify: `resources/js/pages/drivers/show.tsx`
- Test: `tests/Feature/Domains/Drivers/DriverShowPageTest.php`

- [ ] **Step 1: Guardia de regresión**

Run: `vendor/bin/phpunit --no-coverage --filter=DriverShowPageTest` → verde baseline. Los props ya
incluyen `driver.fullName/employeeCode/externalPrimaryId/firstSeenAt/lastSeenAt/currentAsset` — no
hacen falta props nuevas.

- [ ] **Step 2: Tarjeta de Identidad siempre visible**

Run: `grep -n "Sin datos\|riskProfile\|currentAsset\|employeeCode\|externalPrimaryId\|firstSeenAt" resources/js/pages/drivers/show.tsx`
Añadir como primera Card del cuerpo una **"Identidad"** con un grid de campos: Nombre completo,
Código de empleado, ID externo (proveedor), Primera conexión, Última conexión, Activo actual
(enlace si existe). Usar `'—'` como placeholder por campo ausente. Esta Card se renderiza siempre
(no condicionada a historial), de modo que el "95% vacío" pasa a tener contenido real.

- [ ] **Step 3: La caja "sin datos" se reduce a las secciones realmente vacías**

Mantener la caja colapsada de secciones sin datos (riesgo/contactos/documentos/asignaciones/estado)
pero DESPUÉS de la Card de Identidad, como nota secundaria, no como contenido principal.

- [ ] **Step 4: Test de identidad**

Extender `DriverShowPageTest`: un test que cree un conductor SIN riskProfile/contactos/documentos/
asignaciones (solo identidad, con factory) y asserte vía `assertInertia` que el componente es
`drivers/show` y que `driver.employeeCode` / `driver.externalPrimaryId` se exponen. Factories
siempre; aislamiento de tenant ya cubierto por los tests existentes.

- [ ] **Step 5: tipos + formato + regresión**

Run: `npm run types:check` → sin errores.
Run: `vendor/bin/phpunit --no-coverage --filter=DriverShowPageTest` → verde.

- [ ] **Step 6: format + commit**

```bash
npx prettier --write resources/js/pages/drivers/show.tsx
git add resources/js/pages/drivers/show.tsx tests/Feature/Domains/Drivers/DriverShowPageTest.php
git commit -m "fix(drivers): tarjeta de identidad siempre presente en el detalle de conductor (B4)"
```

---

## Cierre de la Fase B (gate completo)

- [ ] **Step 1:** `vendor/bin/phpunit --no-coverage` → todo verde.
- [ ] **Step 2:** `vendor/bin/pint --test` → limpio (esta fase no toca PHP salvo tests; igual se corre).
- [ ] **Step 3:** `npm run types:check && npm run lint:check && npm run format:check` → verdes.
- [ ] **Step 4:** `npm run build` → exitoso.
- [ ] **Step 5 (opcional):** `node scripts/audit-ux.mjs --base=http://localhost` y revisar
  `billing`, `analytics`, `dashboard`, `detail-drivers` — confirmar densidad útil sin huecos.

---

## Self-Review

- **Cobertura del spec (Fase B):** B1 billing grid (Task B1), B2 analytics empty-states (Task B2),
  B3 dashboard hueco/consumo (Task B3), B4 driver identity (Task B4).
- **Riesgo bajo:** sin props nuevas de backend ni dependencias; los tests `assertInertia`
  existentes son la red de regresión. Donde se añade test, es para fijar que el markup nuevo no
  rompe los props.
- **No alcanza:** datos ficticios para llenar huecos (prohibido por el spec — empty-states
  honestos), ni rediseño visual de fondo (fuera de alcance §5).
