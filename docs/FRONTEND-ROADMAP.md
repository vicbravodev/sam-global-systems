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

- [ ] **F0.1 Móvil: el shell no responde.** `OpsSidebar` se renderiza fijo (`grid-cols-[auto_1fr]`,
  ancho 232px) en cualquier viewport; en 390px el contenido queda en ~150px y el topbar se
  encima con el botón de búsqueda. Implementar: sidebar como drawer/Sheet bajo `lg:` con botón
  hamburguesa en `OpsTopbar`, topbar compacto (buscador como icono), y colapso explícito por
  página de las tablas densas (las tablas de incidentes/eventos/flota necesitan una variante
  card-row en `< md`). Archivos: `resources/js/layouts/ops-layout.tsx`,
  `resources/js/components/sam/ops-sidebar.tsx`, `ops-topbar.tsx`, `sam/data-table/*`.
  Criterio: ninguna página operativa con overflow horizontal en 390px; navegación completa
  posible con una mano.
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
- [ ] **F0.7 Verificar el filtro de tabs de la bandeja.** En la auditoría, el tab "SLA crítico"
  mostró las mismas 39 filas que "Abiertos". Confirmar con datos que cada tab (Míos / Sin dueño /
  SLA crítico / Observando) filtra de verdad y tiene test de feature con `assertInertia`.

## Fase 1 — Un solo producto, un solo shell (P1)

- [ ] **F1.1 Migrar settings de usuario y equipos al shell ops.** `settings/profile|security|appearance|notifications`
  y `teams/*` usan el starter `AppLayout` + `SettingsLayout`: otra marca ("SAM OPERATIONS"),
  otro sidebar con nav obsoleta ("Panel / Bandeja / Integraciones"), sin topbar ni paleta.
  Se siente otro producto. Mover esas páginas dentro de `OpsLayout` (sección "Cuenta" con
  sub-nav lateral propia, como ya hacen `settings/roles` y `settings/tenant-config`), y borrar
  `app-sidebar.tsx`/`app-layout.tsx` del árbol vivo si nada más los usa. Criterio: cero páginas
  autenticadas fuera del shell ops (salvo `/admin/*` con su `AdminLayout` deliberado); el switcher
  de tema sigue accesible.
- [ ] **F1.2 Empty states con voz de producto.** Hoy: "Aún no hay snapshot de resumen — se genera
  con BuildAnalyticsSnapshotJob" (jerga interna en UI), "Sin suscripción activa — contacta al
  administrador de SAM", cards apilados vacíos en Facturación y en el detalle de conductor.
  Reescribir todos los empty states con el patrón del componente `ui/empty-state.tsx`: qué
  significa, qué hacer a continuación (CTA real cuando exista: "Nuevo workflow", "Conectar
  integración"), sin nombres de clases/jobs internos. De paso: eliminar el em-dash (`—`) como
  separador de copy en mensajes (queda bien como placeholder de celda vacía en tablas, que es
  numérico/neutral). Páginas: analytics, automation, billing, drivers/show, assets/show
  (telemetría), notifications.
- [ ] **F1.3 KPIs del dashboard sin datos falsos.** "SLA CUMPLIDO 0%" y "PRECISIÓN IA 100%" con
  subtítulo "sin datos previos" comunican métricas que no existen (0% parece desastre, 100%
  parece perfección; ambos son "no hay datos"). Cuando no haya muestra suficiente, el KPI debe
  renderizar estado vacío explícito ("Sin datos del periodo") en lugar del número. Igual el
  sparkline rojo decorativo bajo "39". Archivo: `pages/dashboard.tsx` + componentes de KPI.
- [ ] **F1.4 Detalle de conductor: ocultar lo que no existe.** 5 secciones vacías apiladas
  (riesgo, contactos, documentos, asignaciones, estado) producen una página fantasma. Colapsar
  secciones vacías en una sola franja "Sin datos operativos todavía" + mantener las que tengan
  contenido; en la lista, quitar (o poblar) las columnas muertas ASSET ASIGNADO / RIESGO /
  TELÉFONO que hoy son `—` en las 215 filas.
- [ ] **F1.5 Landing pública y auth con marca.** `welcome.tsx` sigue siendo la página del starter
  de Laravel (hasta carga Instrument Sans de bunny.net, una fuente distinta a la del producto).
  Mínimo viable: hero simple con la marca SAM, value-prop de una línea y CTA a login (es un
  producto B2B por invitación; no necesita marketing site completo). Unificar tipografía con
  Geist. Revisar también `auth/login` (captura pendiente) para que el card use la misma marca.
- [ ] **F1.6 Self-host de fuentes.** Geist y Geist Mono se cargan vía `<link>` a Google Fonts
  (`app.blade.php:39-41`): dependencia externa, FOUT y petición bloqueante en cada cold load.
  Servirlas con `@font-face` + `font-display: swap` desde `public/fonts/` (woff2, pesos usados)
  y eliminar los preconnect. Criterio: cero requests a `fonts.googleapis.com`.

## Fase 2 — Consistencia y accesibilidad (P1–P2)

- [ ] **F2.1 Auditoría de contraste WCAG AA en ambos temas.** Los textos `fg-3`/muted sobre
  superficies oscuras (subtítulos de KPI, metadatos de tablas, hints mono pequeños tipo
  `text-2xs`) están al borde. Verificar AA (4.5:1 cuerpo, 3:1 texto grande) con los tokens
  reales en oscuro y claro; ajustar los tokens, no caso por caso. Incluir badges de severidad
  (Alta/Crítica) y los chips `escalate`/`info` del stream.
- [ ] **F2.2 Navegación por teclado de las tablas.** Las filas de bandeja/eventos/flota deben ser
  focusables y activables con Enter (link real o `role="link"` + handler), con focus ring
  visible. La paleta ya lista atajos (`v` ir al panel, `e` ir a incidentes…): documentarlos en
  un popover "?" de atajos y asegurar que no chocan con inputs.
- [ ] **F2.3 Estados de carga consistentes.** `data-table` ya tiene skeleton; verificar que cada
  página con fetch diferido (mapa, media de incidente, analytics) tenga skeleton con la forma
  del layout final y no spinner genérico ni salto de layout (CLS). Reservar alto para el mapa
  y para `MEDIA DEL EVENTO`.
- [ ] **F2.4 Unificar el placeholder de celdas vacías.** Conviven `—`, "Sin asignar", "sin
  conductor", vacío. Definir uno por tipo (texto vs persona vs número) en el data-table y
  aplicarlo transversalmente.
- [ ] **F2.5 Reduced motion.** Las transiciones existentes son discretas (correcto para ops), pero
  el sparkline animado del dashboard y cualquier pulso del indicador "Conectado" deben respetar
  `prefers-reduced-motion`.

## Fase 3 — Calidad visual fina (P2)

- [ ] **F3.1 Jerarquía del dashboard.** Las 4 KPI cards idénticas + 2 paneles + 1 card de
  integraciones son correctas pero planas. Propuesta: fila de KPIs más compacta (sin card por
  KPI: número + label separados por hairline, estilo cockpit), "Incidentes abiertos" como panel
  dominante (es el job principal del usuario), stream como columna lateral persistente.
- [ ] **F3.2 Tipografía de datos.** Buen uso de mono tabular; revisar que TODOS los timestamps,
  IDs (`INC-00040`) y counts usen `font-mono` con `tabular-nums` (hoy hay mezcla en metadatos
  de notificaciones y auditoría).
- [ ] **F3.3 Chips y badges: un solo sistema.** Conviven: badge severidad (Alta/Crítica), chip
  estado (VENCIDO rojo), chip acción (`escalate`/`info`), badge "activa", pill "Sistema",
  tag `stop`. Consolidar en 3 variantes documentadas (severity / status / meta) con la misma
  geometría (radio, padding, caja tipográfica) en `components/sam/`.
- [ ] **F3.4 Configuración del tenant: humanizar las keys.** La tabla "OTROS SETTINGS (10)" expone
  `context.safety_correlation_minutes` crudo. Mapear key→label en español + descripción corta
  (el catálogo ya existe en backend), dejando la key técnica como texto secundario mono.
- [ ] **F3.5 Mapa en vivo.** Cluster/zoom inicial ajustado al bounding box de la flota (hoy abre
  mostrando todo Norteamérica), leyenda de estados y empty state si no hay posiciones frescas.
- [ ] **F3.6 Favicon y OG.** Definir favicon con el mark SAM (existe `app-logo-icon.tsx`) y
  metadatos OG básicos; hoy queda el default.

## Fase 4 — Deuda de plataforma frontend (P3)

- [ ] **F4.1 Consola `/admin/*`: auditoría visual pendiente.** Requiere un usuario con
  `global_role=super_admin` en dev (seeder opcional `task fresh` o comando artisan). Repetir
  esta auditoría sobre las 6 páginas admin y anexar hallazgos aquí.
- [ ] **F4.2 Tests de regresión visual.** Con Playwright CLI ya disponible, añadir un flujo
  manual documentado (o script `scripts/audit-frontend.sh`) que recorra las páginas clave y
  guarde screenshots por viewport/tema, para re-auditar tras cada fase de este roadmap.
- [ ] **F4.3 Página `events/show`: payloads colapsables.** Los bloques JSON (normalizado + crudo)
  dominan la página; meterlos en `<details>`/acordeón colapsado por defecto con copy-button,
  dejando arriba la evaluación IA / decisión / incidente que es lo que el operador necesita.
- [ ] **F4.4 Bandeja: revisar el botón "Asignarme crítico más viejo".** Verificar estados
  (sin críticos disponibles → disabled con tooltip), feedback con toast y actualización
  optimista de la fila.

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
