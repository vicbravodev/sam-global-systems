# Auditoría de usabilidad del frontend SAM + plan de remediación (P0–P2)

**Fecha:** 2026-06-15
**Método:** Chrome real vía Playwright sobre la app corriendo en `http://localhost`, tenant
ServiExpress JC (datos del pipeline Samsara) + consola superadmin. 116+ capturas en desktop
(1440×900) y móvil (390×844), tema claro y oscuro, incluyendo 4 páginas de detalle (incidente,
evento, conductor, activo) y las 5 páginas `/admin/*`. Cada captura se revisó visualmente.
Harness reproducible: `scripts/audit-ux.mjs` y `scripts/audit-ux-detail.mjs`; capturas en
`storage/app/ux-audit/{fecha}/` (gitignored).

**Relación con la auditoría previa:** La de 2026-06-12 (`docs/FRONTEND-ROADMAP.md`, Fases 0–4)
resolvió la estructura: shell único, móvil sin overflow horizontal, tokens/tipografía, páginas
de error, marca. Esta pasada confirma que esa base aguanta y se enfoca en lo que quedó: **idioma
y datos crudos mezclados por todo el producto**, varias pantallas con espacio mal distribuido,
navegación con conceptos solapados, y la lista densa que en móvil sigue siendo tabla con scroll
lateral.

---

## 1. Hallazgos

Cada hallazgo etiqueta su foco: **[Leg]** legibilidad · **[Dist]** distribución/layout ·
**[Flujo]** flujo/navegación · **[Móvil]**. La evidencia cita el screenshot en
`storage/app/ux-audit/2026-06-15/`.

### P0 — Mezcla inglés/español y enums crudos (el dolor de lectura principal) [Leg]

La cáscara está en español pero los datos que el monitorista mira todo el día salen en inglés
técnico. Es transversal:

| Dónde | Qué se ve | Debería |
|---|---|---|
| Conductores (lista vs detalle/móvil) | `Active` en lista desktop, `Activo` en móvil/detalle | Un solo label en español, consistente |
| Equipo y roles | Nombres y descripciones en inglés: `Analyst`, `Billing Manager`, "Read-only analytics: reports, AI analysis, audit logs", "Full tenant management including billing and users"; solo "Monitorista" en español | Nombres y descripciones en español, consistentes |
| Eventos (lista + detalle) | Tipo `Device Offline`, tags `Maintenance`/`normalized`, clasificación `unclear`, "Mapped from AI classification unclear" | Etiquetas en español |
| Reglas | Columna OUTCOME en enums crudos: `REQUIRE_HUMAN_REVIEW`, `LOG_ONLY`, `INCIDENT` | Etiqueta legible ("Revisión humana", "Solo registro", "Incidente") |
| Activos | Tipo `Vehicle` | "Vehículo" |
| Admin (header/sidebar) | `Tenants`, `Trial` en inglés junto a `Morosos`, `Operadores` en español | Decidir es-only y unificar |

Evidencia: `drivers--desktop--dark.png` vs `detail-drivers--desktop--dark.png`,
`settings-roles--desktop--dark.png`, `events--desktop--dark.png`,
`detail-events--desktop--dark.png`, `rules--desktop--dark.png`, `admin-tenants--desktop--dark.png`.

### P1 — Distribución / espacio mal usado [Dist]

- **Facturación**: 4 tarjetas a todo el ancho, cada una con **una sola línea** de texto. Desperdicio
  enorme de espacio vertical y horizontal. Evidencia: `billing--desktop--dark.png`.
- **Analítica**: dos empty-states apilados que dicen casi lo mismo ("se calculan cada noche"),
  mucho hueco muerto. Evidencia: `analytics--desktop--dark.png`.
- **Dashboard**: el ~40% inferior queda vacío bajo "Integraciones"; el panel "Consumo" que el
  roadmap describía no aparece y las columnas quedan desbalanceadas. Evidencia:
  `dashboard--desktop--dark.png`.
- **Detalle de conductor**: 95% vacío ("Sin datos operativos todavía en…"). Con 218 conductores
  todos en este estado, la página no aporta hoy. Evidencia: `detail-drivers--desktop--dark.png`.

### P1 — Flujo / navegación con conceptos solapados [Flujo]

- **"Notificaciones" significa 3 cosas en 3 lugares**: bandeja (sidebar), preferencias del usuario
  (Cuenta→Notificaciones), política del tenant (Configuración→Notificaciones). Mismo nombre, tres
  destinos.
- **Cuatro entradas de "configuración" solapadas**: Configuración (tenant, sidebar) vs Cuenta
  (usuario, sub-nav) vs Equipo y roles (sidebar) vs Equipos (dentro de Cuenta). Límites difusos.
- **Cuenta→Equipos permite "Nuevo equipo"**: en el modelo Team = tenant, dejar a un usuario crear
  equipos desde sus ajustes es conceptualmente peligroso (resabio del starter kit). Evidencia:
  `teams--desktop--dark.png`.

### P2 — Móvil [Móvil]

- **Listas densas = tablas con scroll horizontal** (columnas cortadas, "T‑…" al borde). Difícil con
  una mano. Falta variante card-row en `< md`. Evidencia: `incidents--mobile--dark.png`,
  `admin-tenants--mobile--dark.png`.
- **Footer de atajos de teclado** (J/K/A/X) se muestra en táctil, donde no hay teclado. Evidencia:
  `incidents--mobile--dark.png`.
- **Config en móvil**: columnas GRUPO/VALOR se enciman → "operational30". Evidencia:
  `tenant-config--mobile--dark.png`.
- **Encabezados largos envuelven feo** ("Bandeja / de / incidentes" en 3 líneas); nombres largos
  en celdas estrechas (admin tenants). Evidencia: `incidents--mobile--dark.png`.

### P2 — Polish / bugs visibles

- **Ícono de proveedor roto** (cuadro rojo de imagen rota) recurrente: junto a nombres en
  Automatizaciones y Reglas, y en la columna FUENTE de Activos. Evidencia:
  `automation--desktop--dark.png`, `rules--desktop--dark.png`, `detail-assets--desktop--dark.png`.
- **Configuración** expone keys técnicas crudas inline (`media.auto_request_on_critical`,
  `panic.auto_close_on_external_resolution`, `context.live_location_staleness_seconds`) pegadas a
  las etiquetas, y una columna "V" sin explicar. Evidencia: `tenant-config--desktop--dark.png`.
- **Consistencia de KPIs**: el dashboard del tenant usa la franja cockpit unificada (F3.1) pero la
  consola admin usa 4 tarjetas sueltas. Evidencia: `admin-tenants--desktop--dark.png`.
- **Datos de prueba sucios** (del seeder, no de producción): workflows "AUDIT test workflow"
  duplicados, reglas con `<script>alert(1)</script>` (bien escapado, no ejecuta). No es bug de UI,
  pero ensucia demos.

### Limitaciones de cobertura

- El detalle de tenant en admin (`/admin/tenants/{id}`) no se capturó: las filas no son enlaces
  navegables por el harness. La página existe (`admin/tenants/show.tsx`) y se revisó en código en
  la auditoría previa.
- Para auditar `/admin/*` se creó un superadmin (`superadmin@sam.test`) en la BD de ServiExpress;
  su team personal aparece ahora en la lista de tenants (artefacto de auditoría, no bug).

---

## 2. Enfoque de remediación

**Decisión clave (P0): el producto es español-only para México** (`APP_LOCALE=es`). No se
justifica un sistema i18n con locales múltiples. Se elige el enfoque ya usado en el repo (F3.4,
admin/plans): **mapas de etiqueta + métodos `label()` en los enums**, español-first.

- **Recomendado — Enums con `label()` + presenters/diccionarios:** cada enum de dominio
  (`DecisionOutcome`, estados de conductor/activo, categorías/tipos de evento, clasificación IA)
  expone `label(): string` en español; los presenters y componentes consumen `label()` en vez del
  `value`. Para datos provenientes del proveedor (p.ej. tipo de evento crudo de Samsara) se añade
  un diccionario de traducción en el presenter, con fallback al valor crudo. Roles del sistema:
  traducir `name`/`description` en el seeder de Access (additive: nueva migración/columna no
  necesaria; se actualizan strings) y humanizar en la card. Bajo riesgo, alineado al patrón
  existente, sin dependencias nuevas.
- **Alternativa A — i18n completo (laravel + react-i18next):** sobredimensionado para un producto
  monolingüe; añade dependencias (prohibido sin aprobación, §6) y complejidad. Descartado.
- **Alternativa B — traducir solo en el frontend con un único `labels.ts`:** rápido, pero deja la
  fuente de verdad partida entre front y los enums del backend; peor mantenibilidad. Se usa solo
  para etiquetas que hoy ya viven en el front.

El resto de hallazgos son cambios de layout/markup en componentes React + un puñado de
presenters, sin tocar dominio.

---

## 3. Plan por fases

Cada fase = uno o varios PRs pequeños. Toda página tocada cumple CLAUDE.md §8.5: feature test con
`assertInertia`, `npm run types:check && lint:check && format:check` y `npm run build` verdes;
backend con `vendor/bin/pint --dirty --format agent` y PHPUnit.

### Fase A — P0 idioma/enums (mayor impacto de lectura)
- **A1** `label()` en español en enums de dominio + consumo en presenters: `DecisionOutcome`
  (Reglas/Decisión), clasificación IA (`unclear`→"Sin determinar", etc.), severidad ya cubierta.
- **A2** Estados de conductor y activo: un solo label es-ES consistente en lista, detalle y móvil.
- **A3** Tipos/categorías de evento + tags del proveedor: diccionario de traducción en el
  presenter de eventos (fallback al crudo). "Device Offline"→"Dispositivo sin conexión",
  "Vehicle"→"Vehículo", etc.
- **A4** Roles del sistema: nombres y descripciones en español en el seeder de Access + card de
  roles. Decidir y unificar "Tenants"/"Trial" del admin (mantener es-ES).

### Fase B — P1 distribución
- **B1** Facturación: pasar las 4 tarjetas-de-una-línea a un grid compacto (2×2) o lista de
  filas; reservar el ancho para cuando haya datos reales.
- **B2** Analítica: fusionar los dos empty-states en uno solo claro, recuperar el espacio.
- **B3** Dashboard: cerrar el hueco inferior — mostrar el panel "Consumo" cuando haya datos o
  rebalancear columnas para que la franja viva ocupe el alto.
- **B4** Detalle de conductor: dar presencia a identidad / código externo / última conexión en vez
  de solo la franja "sin datos".

### Fase C — P1 navegación
- **C1** Desambiguar "Notificaciones": renombrar las tres entradas según su función (p.ej.
  "Bandeja", "Mis preferencias de notificación", "Política de notificaciones del tenant").
- **C2** Clarificar el solapamiento Configuración/Cuenta/Equipo y roles/Equipos (agrupar o
  renombrar; decisión de IA de producto, ver §4).
- **C3** Revisar/retirar "Nuevo equipo" en Cuenta→Equipos según el modelo Team=tenant.

### Fase D — P2 móvil + polish
- **D1** Variante card-row para las tablas densas en `< md` (al menos incidentes, conductores,
  eventos, activos, admin/tenants).
- **D2** Ocultar el footer de atajos de teclado en táctil/`< md`.
- **D3** Config en móvil: arreglar el encimado GRUPO/VALOR (stack o scroll contenido).
- **D4** Encabezados que no envuelvan feo en móvil.
- **D5** Arreglar el ícono de proveedor roto (cuadro rojo) en automatización/reglas/activos.
- **D6** Unificar KPIs del admin con la franja cockpit del dashboard (consistencia).

---

## 4. Decisiones que requieren al usuario (IA de producto)

- **C2**: cómo reorganizar las 4 entradas de "configuración". Opciones: (a) agrupar bajo un solo
  menú "Ajustes" con sub-secciones; (b) mantener separadas pero renombrar para que el alcance sea
  obvio (tenant vs cuenta).
- **C3**: ¿debe un usuario poder crear equipos/tenants desde sus ajustes, o eso es exclusivo del
  superadmin? Define si "Nuevo equipo" se retira.

## 5. Fuera de alcance

- Limpieza de datos de prueba del seeder (no es UI).
- Internacionalización multi-idioma.
- Rediseño visual de fondo (la dirección actual es correcta).
- Detalle de tenant en admin (ya revisado en código; se puede capturar aparte si se requiere).
