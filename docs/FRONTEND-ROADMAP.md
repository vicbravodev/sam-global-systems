# Frontend Roadmap — SAM Global Systems

Resultado de la auditoría de frontend del 2026-06-12, hecha sobre la app real corriendo en dev
(`http://localhost`, tenant ServiExpress JC, datos seeded del pipeline Samsara) navegando las
38 páginas Inertia con Playwright CLI en desktop (1440×900), móvil (390×844) y ambos temas
(oscuro/claro). Las páginas `/admin/*` (consola super-admin) no se auditaron visualmente porque
el usuario seeded no tiene `global_role=super_admin`; se revisaron solo a nivel de código.

**Lectura de diseño:** SAM es una consola operativa (dashboard-class product UI), no una landing.
Los dials correctos para este producto son: varianza baja (layout predecible), motion bajo
(transiciones funcionales, nada decorativo), densidad alta (cockpit). La base actual es buena:
Geist + Geist Mono, tokens semánticos (`fg-1/2/3`, superficies), números tabulares en mono,
shadcn/ui personalizado, dark/light funcionales y consistentes. El problema no es la dirección
visual: son los huecos funcionales, el shell legacy de settings y el móvil roto.

**Veredicto por página (resumen):**

| Página | Estado | Nota |
|---|---|---|
| Dashboard | 🟡 Buena base | KPIs con datos falsos/placeholder cuando no hay histórico |
| Incidentes (bandeja) | 🟢 Sólida | La mejor lista del producto; densa y legible |
| Incidente (detalle) | 🟢 Sólida | Timeline + media + IA + acciones; la página más completa |
| Eventos / Evento | 🟢 Correcta | Detalle útil (payloads, decisión, contexto) |
| Flota / Activo / Mapa | 🟢 Correcta | Mapa MapLibre funcional |
| Conductores / Conductor | 🟡 Floja | Columnas muertas (`—`) y detalle casi vacío |
| Analítica | 🔴 Vacía | Empty state con jerga interna (`BuildAnalyticsSnapshotJob`) |
| Automatizaciones | 🟡 Vacía-funcional | Builder existe pero el empty state no guía |
| Auditoría / Notificaciones | 🟢 Correcta | Tablas densas consistentes |
| Reglas | 🟢 Correcta | 3 tabs funcionales |
| Facturación | 🟡 Vacía | 4 cards de empty states apilados |
| Configuración (tenant) | 🟢 Correcta | 8 tabs; expone keys técnicas crudas |
| Equipo y roles | 🟢 Correcta | |
| Settings de usuario / Equipos | 🔴 Shell legacy | Otro layout, otra marca, nav obsoleta |
| Welcome (landing pública) | 🔴 Starter Laravel | Página de starter kit sin marca SAM |
| Errores (403/404/500) | 🔴 Default Laravel | Blancas, en inglés, sin shell |
| Móvil (todo el producto) | 🔴 Roto | El sidebar no colapsa; contenido aplastado |

---

## Fase 0 — Bugs funcionales visibles (P0, antes que cualquier estética)

- [x] **F0.1 Móvil: el shell no responde.** ✅ 2026-06-12 — sidebar como drawer (`Sheet` side
  left) bajo `lg:` con hamburguesa en `OpsTopbar` y cierre automático al navegar
  (`router.on('navigate')`); topbar compacto (< `md`: buscador como icono, breadcrumbs y texto
  del user pill ocultos en < `sm`); tablas densas (`data-table` + `inbox-table`) con `min-w` para
  scroll horizontal contenido dentro del wrapper en vez de aplastar columnas. Verificado con
  Playwright a 390×844: 0px de overflow horizontal en dashboard/bandeja, drawer navegable con
  una mano. (Refinamiento futuro opcional: variante card-row por página en `< md`.)
- [x] **F0.2 Badges del sidebar hardcodeados.** ✅ 2026-06-12 — `navBadges` es shared prop de
  Inertia (`HandleInertiaRequests::navBadges()`, count real de incidentes abiertos por tenant,
  cacheado 60s). Los badges de Reglas/Integraciones se eliminaron hasta tener un count real.
  Test: `tests/Feature/Http/NavBadgesShareTest.php` (count real, aislamiento de tenant, guest).
- [x] **F0.3 El atajo ⌘K anunciado no existe.** ✅ 2026-06-12 — listener global Cmd/Ctrl+K en
  `ops-layout.tsx` (toggle de la paleta con `preventDefault`).
- [x] **F0.4 Páginas de error default de Laravel.** ✅ 2026-06-12 — página Inertia
  `errors/error.tsx` (sin layout, marca SAM, mensajes 403/404/500/503 en español, volver atrás /
  ir al inicio) cableada vía `$exceptions->respond()` en `bootstrap/app.php`. Excluye JSON/API/
  webhooks; 500/503 conservan la página de debug con `APP_DEBUG=true`. Test:
  `tests/Feature/Http/ErrorPagesTest.php` (404, 403 en `/admin/*`, 500 con debug off, JSON intacto).
- [x] **F0.5 Identidad de la app en metadata.** ✅ 2026-06-12 — `APP_NAME=SAM` en `.env.example`
  y fallbacks `'SAM'` en `config/app.php` y `resources/js/app.tsx` (`APP_LOCALE=es` ya estaba en
  `.env.example`; `config/app.php` ya tenía default `es`). Pendiente manual: actualizar los `.env`
  de entornos desplegados.
- [x] **F0.6 Link "Configuración" muerto en el shell legacy.** ✅ 2026-06-12 — el footer de
  `app-sidebar.tsx` apunta a `/settings/profile`. (Desaparece del todo con F1.1.)
- [x] **F0.7 Verificar el filtro de tabs de la bandeja.** ✅ 2026-06-12 — verificado en código:
  los tabs sí discriminan (open = no terminal, unassigned = open sin assignee, sla = `slaSeconds
  < 900`, discarded). Lo observado en la auditoría era artefacto de datos: los incidentes seeded
  eran viejos, así que TODOS los abiertos tenían SLA vencido (`slaSeconds` negativo) y caían en
  "SLA crítico". Fix real encontrado: "Míos" matcheaba por **iniciales** (colisionaba entre
  usuarios homónimos) — ahora el presenter expone `assignee.id` y el tab filtra por
  `currentUserId`. Test ampliado: `IncidentInboxTest::test_inbox_maps_priority_status_and_assignment`
  asserta `assignee.id`.

## Fase 1 — Un solo producto, un solo shell (P1)

- [x] **F1.1 Migrar settings de usuario y equipos al shell ops.** ✅ 2026-06-12 — `settings/*` y
  `teams/*` ahora renderizan `[OpsLayout, SettingsLayout]` con heading "Cuenta" y sub-nav propia;
  default del resolver de layouts pasó a `OpsLayout`. El user pill del topbar es ahora un dropdown
  real (`UserMenuContent`: perfil + cerrar sesión — antes el shell ops no tenía logout). Borrados
  del árbol vivo: `app-layout.tsx`, `layouts/app/*`, `app-sidebar.tsx`, `app-header.tsx`,
  `nav-main.tsx`, `nav-user.tsx`, `team-switcher.tsx`, `app-logo.tsx`. Verificado con Playwright:
  perfil y equipos dentro del shell ops, tema accesible desde el topbar.
- [x] **F1.2 Empty states con voz de producto.** ✅ 2026-06-12 — analytics usa `EmptyState`
  (sin `BuildAnalyticsSnapshotJob` en UI), automation con CTA real "Nuevo workflow", billing
  reescrito sin em-dash (plan/consumo/funcionalidades/facturas explican qué significa y qué
  sigue), assets/show explica por qué no hay posición/telemetría. Notifications ya cumplía.
- [x] **F1.3 KPIs del dashboard sin datos falsos.** ✅ 2026-06-12 — `KpiCard` gana estado
  `empty` ("Sin datos del periodo") activado cuando SLA/precisión vienen `null`; deltas null en
  gris neutro ("sin comparativa previa"); sparkline neutro (`--fg-3`) y solo con variación real
  (series planas no pintan línea).
- [x] **F1.4 Detalle de conductor: ocultar lo que no existe.** ✅ 2026-06-12 — el detalle solo
  pinta cards con contenido y colapsa lo vacío en una franja "Sin datos operativos todavía en:
  …"; la lista auto-poda columnas sin un solo dato en el set (asset asignado / riesgo /
  teléfono).
- [x] **F1.5 Landing pública y auth con marca.** ✅ 2026-06-12 — `welcome.tsx` reescrita: hero
  SAM (mark + value-prop "Cada alerta investigada. Solo lo real escala." + CTA a login), tokens
  y Geist del producto, sin bunny.net. Los layouts de auth ya usaban `AppLogoIcon`.
- [x] **F1.6 Self-host de fuentes.** ✅ 2026-06-12 — Geist y Geist Mono variables (subset latin,
  woff2) servidas desde `public/fonts/` con `@font-face` + `font-display: swap` + preload en
  `app.blade.php`; preconnect/links a Google Fonts eliminados. Verificado con Playwright: 0
  requests a fonts.googleapis/gstatic/bunny.net.

## Fase 2 — Consistencia y accesibilidad (P1–P2)

- [x] **F2.1 Auditoría de contraste WCAG AA en ambos temas.** ✅ 2026-06-12 — medido con
  conversión OKLCH→sRGB + ratio WCAG sobre los tokens reales: `fg-2`/`fg-3` pasan AA holgado en
  ambos temas (≥5.2:1) y todo el tema oscuro pasa. Fallaban los colores de severidad/confianza
  **como texto en tema claro** (medium 1.85:1, high 2.48:1, low 2.46:1, critical 3.74:1):
  oscurecidos a nivel token (`L 0.49–0.5`) → ahora 5.3–6.7:1 sobre sus fondos y superficies, y
  de paso el blanco sobre badge sólido también mejora.
- [x] **F2.2 Navegación por teclado de las tablas.** ✅ 2026-06-12 — las filas de DataTable e
  inbox ya eran focusables con Enter + focus ring. Lo que faltaba (y era otra mentira de UI):
  los atajos que el footer de la bandeja anuncia (J/K navegar, Enter abrir, X seleccionar,
  A asignarme, Esc cerrar) **no existían** — ahora están implementados con guard para inputs/
  diálogos. Verificado con Playwright: j abre panel, x activa bulk bar, esc cierra, escribir
  "jjkk" en el buscador no dispara nada.
- [x] **F2.3 Estados de carga consistentes.** ✅ 2026-06-12 — verificado: `data-table` tiene
  skeleton con la forma de las filas; el panel de detalle del inbox usa `DetailPlaceholder`
  durante el fetch JSON; el mapa reserva alto (`flex-1` del shell); media de incidente y
  analytics llegan como props server-side (lazy de Inertia, sin fetch diferido en cliente), así
  que no producen CLS. Sin spinners genéricos fuera de acciones puntuales (botones).
- [x] **F2.4 Unificar el placeholder de celdas vacías.** ✅ 2026-06-12 — convención codificada
  en `sam/data-table/cell-empty.tsx` (`CellEmpty`: dash neutro para texto/número, "Sin asignar"
  cursiva para persona) y aplicada en las tablas de assets/drivers/notifications; los
  placeholders inline de events/show capitalizados ("Sin activo"/"Sin conductor").
- [x] **F2.5 Reduced motion.** ✅ 2026-06-12 — todas las animaciones custom (`sam-badge-pulse`,
  `sam-sla-pulse`, `sam-flash`) ahora van detrás de `motion-safe:`; el indicador "Conectado" ya
  lo cumplía y el sparkline del dashboard es estático.

## Fase 3 — Calidad visual fina (P2)

- [x] **F3.1 Jerarquía del dashboard.** ✅ 2026-06-12 — la fila de KPIs es ahora una sola franja
  cockpit (celdas con hairlines vía `gap-px bg-border`, número mono 26px, sin card por KPI);
  "Incidentes abiertos" es el panel dominante (`2fr`) con Integraciones y Consumo debajo en la
  misma columna, y el stream en vivo quedó como columna lateral persistente (`1fr`). Verificado
  con screenshot.
- [x] **F3.2 Tipografía de datos.** ✅ 2026-06-12 — barrido sobre notificaciones y auditoría:
  los timestamps de auditoría ya van en `font-mono`, las fechas de notificaciones usan
  `RelativeTime` (mono + tabular), los codes de canal van en mono. No quedaron offenders; los
  IDs `INC-*` del inbox ya eran mono desde V1.
- [x] **F3.3 Chips y badges: un solo sistema.** ✅ 2026-06-12 — las 3 variantes documentadas
  viven en `components/sam/`: `SeverityBadge` (severidad), `StatusPill` (estado) y el nuevo
  `MetaChip` (metadatos neutros), las tres con geometría idéntica (`rounded-sm px-1.5 py-1
  text-[10px] font-semibold tracking-[0.02em]`). De paso: los labels de `StatusPill` estaban en
  inglés ("New", "In progress"…) → traducidos, y el `DecisionChip` del stream del dashboard se
  alineó a la geometría común con labels en español (Incidente/Escalado/Info/Descartado).
- [x] **F3.4 Configuración del tenant: humanizar las keys.** ✅ 2026-06-12 — la tabla "Otros
  settings" mapea las keys del SAM Default Pack a etiqueta en español + descripción corta, con
  la key técnica como texto secundario mono (keys desconocidas caen a la key cruda).
- [x] **F3.5 Mapa en vivo.** ✅ 2026-06-12 — verificado en código: el fit al bounding box de la
  flota ya existía (`LiveMap` hace `fitBounds` al primer load y nunca re-encuadra para no mover
  el viewport del operador), igual que la leyenda de estados presentes y el empty state. Lo que
  faltaba: el fallback sin posiciones ahora encuadra México (zoom 4.6) en vez de Norteamérica.
- [x] **F3.6 Favicon y OG.** ✅ 2026-06-12 — `public/favicon.svg` con el mark SAM (rect azul
  marca + trazo blanco) y metadatos OG/description básicos en `app.blade.php`.

## Fase 4 — Deuda de plataforma frontend (P3)

- [x] **F4.1 Consola `/admin/*`: auditoría visual pendiente.** ✅ 2026-06-12 — auditadas las 5
  páginas (tenants, plans, operators, channels, audit) con Playwright en desktop 1440 y móvil
  390, usuario `super_admin` local. **Hallazgos anexados:**
  - 🟢 Desktop sólido: shell admin consistente, 0px de overflow horizontal en las 5 páginas,
    tablas densas legibles, badges de morosos/trial funcionando.
  - ✅ ~~Planes: labels de medidores en inglés~~ — resuelto 2026-06-12: mapa code→español en
    `admin/plans` (mismo patrón F3.4), fallback al nombre de DB para codes desconocidos.
  - ✅ ~~Canales: el label "Tipo" se encima con el select~~ — resuelto 2026-06-12: cada campo
    del form es `flex flex-col gap-1` (label arriba, control abajo).
  - ✅ ~~Móvil: `AdminLayout` no colapsa el sidebar~~ — resuelto 2026-06-12: drawer móvil
    replicando F0.1 (Sheet + hamburguesa en `AdminTopbar`, cierre al navegar). Verificado con
    Playwright a 390px.
- [x] **F4.2 Tests de regresión visual.** ✅ 2026-06-12 — `scripts/audit-frontend.mjs`: recorre
  17 páginas clave × desktop/móvil × oscuro/claro con Playwright, guarda screenshots en
  `storage/app/frontend-audit/{fecha}/` (gitignored) y falla si detecta overflow horizontal.
  Primera corrida: 68 capturas, 0 páginas con overflow.
- [x] **F4.3 Página `events/show`: payloads colapsables.** ✅ 2026-06-12 — `JsonBlock` es ahora
  un `<details>` colapsado por defecto con botón "Copiar" (clipboard + toast); la evaluación IA /
  decisión / incidente quedan dominando la página.
- [x] **F4.4 Bandeja: revisar el botón "Asignarme crítico más viejo".** ✅ 2026-06-12 — sin
  críticos abiertos el botón queda disabled con tooltip explicativo; el feedback con toast y
  spinner ya existía y el refresh es parcial (`router.reload({only: ['incidents']})`). El pulso
  del contador de críticos ahora respeta reduced motion.

---

## Orden sugerido de ejecución

1. **PR 1 (quick wins):** F0.2 + F0.3 + F0.5 + F0.6 — un día, elimina las mentiras visibles.
2. **PR 2:** F0.4 páginas de error.
3. **PR 3 (el grande):** F0.1 móvil del shell ops (sidebar drawer + topbar compacto).
4. **PR 4:** F1.1 unificación de settings en el shell ops.
5. **PR 5:** F1.2 + F1.3 + F1.4 empty states y datos honestos.
6. **PR 6:** F1.5 + F1.6 + F3.6 marca (landing, fuentes self-host, favicon).
7. Fase 2 y 3 en PRs pequeños por ítem; F4 cuando haya hueco.

Cada PR debe cumplir las reglas de CLAUDE.md §8.5: feature test con `assertInertia` para toda
página tocada, `npm run types:check && lint:check && format:check` y `npm run build` verdes.
