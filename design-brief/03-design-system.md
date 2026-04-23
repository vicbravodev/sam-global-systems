# 03 · Design System — Especificaciones técnicas

Este documento define **las restricciones** que el design system debe respetar y **el alcance** de lo que el diseñador debe entregar.

## 1. Restricciones técnicas (inamovibles)

- **Tailwind CSS v4** con configuración vía `@theme { ... }` en `resources/css/app.css`. No hay `tailwind.config.js`.
- **Tokens de color en `oklch()`** (no HEX, no HSL). Entregar **light + dark** para cada token.
- **Dark mode**: clase `.dark` aplicada en `<html>`. Los tokens se re-definen en `.dark { ... }`.
- **shadcn/ui** como base de primitivos (ya instalados: `button`, `input`, `dialog`, `dropdown-menu`, `sheet`, `sidebar`, `tooltip`, `card`, `badge`, `alert`, `avatar`, `checkbox`, `select`, `toggle`, `navigation-menu`, `separator`, `skeleton`, `spinner`, `sonner`, `breadcrumb`, `collapsible`, `input-otp`, `label`, `toggle-group`, `icon`).
- **Inertia.js v3 + React 19**. Nada de CSR-heavy routing; las páginas son server-rendered Inertia. Navegación con `<Link>` de Inertia.
- **Fuente actual**: `Instrument Sans` (variable `--font-sans`). El diseñador puede proponer cambio, pero debe justificarlo para densidad tabular y realtime.
- **Radio actual**: `--radius: 0.625rem` con escalas `sm/md/lg`. Puede recalibrarse.
- **Iconografía**: `lucide-react`. No cambiar a otra librería salvo justificación fuerte.
- **Realtime**: Soketi/Pusher broadcasting. Nuevos items llegan por canal `private-accounts.{teamId}`. El DS debe definir cómo se ve "algo nuevo acaba de entrar".
- **Accesibilidad**: WCAG 2.1 AA mínimo. Foco visible en todos los interactivos. Contraste ≥4.5:1 en texto, ≥3:1 en componentes.

## 2. Tokens — qué entregar

### 2.1 Color

Mantener la estructura shadcn + **agregar escalas semánticas operativas**. Entregar en OKLCH, light + dark.

**Tokens base (ya existen, reemplazables):**
```
background, foreground
card, card-foreground
popover, popover-foreground
primary, primary-foreground
secondary, secondary-foreground
muted, muted-foreground
accent, accent-foreground
destructive, destructive-foreground
border, input, ring
sidebar, sidebar-foreground, sidebar-primary, sidebar-primary-foreground,
  sidebar-accent, sidebar-accent-foreground, sidebar-border, sidebar-ring
chart-1 .. chart-5
```

**Tokens a agregar (obligatorios para SAM):**

Severidad operativa (para eventos, incidentes, AI):
```
severity-critical      / severity-critical-foreground   / severity-critical-bg
severity-high          / severity-high-foreground       / severity-high-bg
severity-medium        / severity-medium-foreground     / severity-medium-bg
severity-low           / severity-low-foreground        / severity-low-bg
severity-info          / severity-info-foreground       / severity-info-bg
```

Estado de incidente (ciclo de vida):
```
status-open, status-in-review, status-escalated,
status-resolved, status-closed, status-false-positive, status-cancelled
```
Cada uno con `-foreground` y `-bg`. Varios estados pueden compartir color base (ej: resolved/closed → verde), pero el token debe existir independiente para flexibilidad.

Estado de salud de integración:
```
health-healthy, health-degraded, health-broken, health-unknown
```

Decisión AI:
```
decision-discard, decision-info, decision-incident, decision-escalate
```

Confianza AI (para gradiente/meter):
```
confidence-low, confidence-mid, confidence-high
```

Chart (ampliar a 8 series para analytics):
```
chart-1 .. chart-8
```
Además: `chart-positive`, `chart-negative`, `chart-neutral` para deltas.

### 2.2 Tipografía

Entregar escala con token + uso:

| Token | Tamaño | Uso |
|-------|--------|-----|
| `text-display` | ~2rem | KPI hero, títulos de página (raros) |
| `text-h1` | 1.5rem | Títulos de sección grandes |
| `text-h2` | 1.25rem | Títulos de card / modal |
| `text-h3` | 1rem bold | Sub-secciones |
| `text-body` | 0.875rem (14px) | **Default de la app** (densidad alta) |
| `text-body-lg` | 1rem | Lectura larga (docs, resoluciones) |
| `text-caption` | 0.75rem | Metadatos, timestamps, hints |
| `text-mono-sm` | 0.75rem mono | IDs, hashes, JSON inline |
| `text-mono` | 0.875rem mono | Code blocks, payload viewers |

Line-height y letter-spacing explícitos para cada uno. Peso: 400 default, 500 medium (énfasis), 600 bold (headings y números críticos).

Recomendación de stack (decidir): `Inter` o `Geist` como sans + `JetBrains Mono` o `Geist Mono` como mono. Justificar contra `Instrument Sans` actual.

### 2.3 Espaciado

Escala Tailwind default (0, 0.5, 1, 1.5, 2, 3, 4, 6, 8, 10, 12, 16). Entregar **grid densidad** para listas operativas:

- `density-compact` — 32px alto de fila (bandeja de incidentes default).
- `density-comfortable` — 40px alto de fila.
- `density-relaxed` — 56px alto de fila (pantallas de lectura, admin).

El usuario debe poder cambiar densidad en preferencias (ya existe `UserPreference`).

### 2.4 Radio, borde, sombra

- Radio: `sm 4px`, `md 6px`, `lg 10px`, `xl 14px`, `full`.
- Borde: `1px` default. `2px` solo en estados de foco/seleccionado.
- Sombra: definir `shadow-xs/sm/md/lg/xl` pero **usarlas con moderación**. En dark mode, sombras casi invisibles; diferenciación por borde + elevación de color.

### 2.5 Motion

- **Instantáneo (<50ms)**: estados hover, foco.
- **Rápido (100–150ms)**: aparición de menús, tooltips, toasts.
- **Normal (200ms)**: transiciones de ruta, expand/collapse.
- **Realtime highlight**: nuevo item en lista → flash de `accent/20` durante 1.2s, fade a estado normal.
- **Nunca** usar transiciones en cambios de datos críticos (severidad, SLA). Los números cambian en seco.
- Respetar `prefers-reduced-motion`.

### 2.6 Z-index (escala explícita)

```
base: 0
dropdown: 40
sticky: 50
fixed-header: 100
sidebar-overlay: 150
modal-backdrop: 200
modal: 210
popover: 300
tooltip: 400
toast: 500
command-palette: 600
```

## 3. Componentes — qué entregar

### 3.1 Primitivos (adaptar shadcn existentes)

Ya existen, el diseñador valida/ajusta: `Button` (variantes: default, destructive, outline, secondary, ghost, link), `Input`, `Textarea` (no existe aún, crear), `Select`, `Checkbox`, `Radio` (no existe, crear), `Switch` (no existe, crear), `Slider` (no existe, crear si necesario), `Dialog`, `Sheet`, `Popover` (crear), `Tooltip`, `DropdownMenu`, `Tabs` (no existe, crear), `Toast` (sonner), `Badge`, `Avatar`, `Card`, `Separator`, `Skeleton`, `Spinner`, `Breadcrumb`.

### 3.2 Componentes de sistema (shell)

- **AppSidebar** (ya existe, refinar) — colapsable, con badge de contador en items realtime.
- **TeamSwitcher** (ya existe, refinar) — selector multi-tenant, con logo del tenant.
- **CommandPalette** (crear) — ⌘K, búsqueda global cross-módulo.
- **GlobalSearch** — inline en topbar, atajo `/`.
- **UserMenu** (ya existe) — avatar + perfil + logout.
- **RealtimeStatusPill** — verde "conectado", ámbar "reconectando", rojo "offline".
- **BrandingHeader** — respeta override del tenant (logo, color primario).

### 3.3 Componentes de datos

- **DataTable** — columnas redimensionables, sort, multi-select, sticky header, paginación server-side, filas densas por default.
- **Filters** — barra de filtros componible: chips con valor, filtros guardables.
- **SavedViews** — dropdown con vistas guardadas por usuario.
- **BulkActionBar** — aparece al seleccionar múltiples filas, sticky bottom.
- **EmptyState** — ilustración mínima (mono-color, no corporativa), título, descripción, CTA.
- **Pagination** — numeric + cursor.
- **JsonInspector** — visor pretty-printed colapsable con copy.
- **DiffViewer** — before/after side-by-side, para audit.
- **CodeBlock** — con syntax highlight ligero + copy.

### 3.4 Componentes de dominio (lo específico de SAM)

Todos estos son **obligatorios** y deben diseñarse con anatomía + estados:

**Severidad & estado:**
- `SeverityBadge` — 5 variantes (critical/high/medium/low/info), tamaños sm/md/lg.
- `StatusPill` — 7 variantes del ciclo de incidente.
- `PriorityIndicator` — barra vertical a la izquierda del row (alto impacto visual sin gritar).
- `HealthDot` — dot pequeño con color de `health-*` para integraciones.
- `DecisionOutcomeBadge` — 4 variantes (DISCARD/INFO/INCIDENT/ESCALATE).

**SLA y tiempo:**
- `SlaCountdown` — cuenta regresiva viva, cambia a `severity-high` a los 80%, `severity-critical` a los 95%, y rojo pulsante cuando se pasa.
- `RelativeTime` — "hace 2 min" con tooltip timestamp absoluto en TZ del tenant.
- `DurationPill` — "12m 34s" para tiempos de respuesta.

**Objetos operativos:**
- `IncidentRow` — fila densa para la bandeja: priority stripe, severity badge, título, activo, conductor, asignado, SLA countdown, edad.
- `IncidentCard` — variante expandida para detalle o pinned.
- `IncidentTimeline` — vertical, un icono/color por `entry_type`, actor (user/system/ai), payload colapsable.
- `EvidenceGallery` — grid de media: imagen/video thumb/document icon/snapshot telemetría. Click → lightbox.
- `AssignmentPanel` — avatar + rol + acciones (reasignar, desasignar).
- `CommentThread` — con `VisibilityToggle` (internal / tenant / audit).
- `RelatedEventsPanel` — lista colapsable de eventos ligados al incidente.
- `AssetCard` — icono por tipo + estado + última ubicación + link.
- `AssetStatusPill` — idle / active / offline / maintenance.
- `DriverCard` — foto + nombre + riesgo (`RiskMeter`) + asignación actual.
- `RiskMeter` — 0–100 con zonas de color.
- `DocumentStatusBadge` — vigente / por vencer / vencido.
- `ProviderLogo` — logos de Samsara, Motive, etc. (con fallback `?` para providers nuevos).
- `IntegrationCard` — logo + nombre + health + última sync.
- `WebhookLogRow` — método + endpoint + status + firma válida/inválida + payload colapsable.
- `AiEvaluationCard` — input resumido → output con confianza + modelo + latencia.
- `ConfidenceBar` — 0–100% con gradiente `confidence-low → high`.
- `ModelTag` — pill con nombre del modelo y versión.
- `ReasoningDisclosure` — colapsable con el razonamiento de la IA.
- `RuleBuilder` — bloques IF (evento + contexto) → THEN (decisión). Drag-free, select-based (accesible con teclado).
- `RuleSimulator` — correr regla contra dataset y ver impacto.
- `AutomationCard` — trigger + condición + acción + estado + última ejecución.
- `ActionPicker` — selector de acciones ejecutables con parámetros.
- `KpiTile` — valor grande + delta (% vs periodo anterior) + sparkline.
- `TrendChart`, `Heatmap`, `StackedBarChart`, `DonutChart` — paleta `chart-*`.
- `UsageMeterCard` — uso vs límite, barra + % + proyección.
- `PlanCompareTable` — grid planes, features, precios.
- `MappingRuleEditor` — payload input → normalized output con JSON diff en vivo.
- `FeatureToggle` — switch + descripción + badge si está en beta/deprecated.

### 3.5 Pantallas hero a diseñar a fondo

Entregar mockups pixel-perfect (light + dark) de:

1. **Dashboard operativo** — 3 KPI tiles arriba, mapa en vivo + lista de incidentes abiertos, stream de eventos, RealtimeStatusPill.
2. **Bandeja de incidentes** — sidebar + filtros + DataTable densa + split-view detail opcional.
3. **Detalle de incidente** — layout 3 columnas (timeline / descripción+evidencia+AI / asignación+relacionados+acciones).
4. **Mapa/Lista de activos** — toggle, filtros, cards, detalle en drawer.
5. **Detalle de conductor** — perfil + riesgo + documentos + asignaciones + incidentes relacionados.

Bonus si el tiempo alcanza: detalle de evaluación AI, editor de reglas, integración con webhook logs.

## 4. Accesibilidad y densidad

- Hit targets **≥32×32px** incluso en densidad compacta (padding interno cubre el alto de fila).
- Foco visible con **anillo de 2px** en `ring` token.
- Navegación completa por teclado. Documentar shortcuts globales (`⌘K`, `/`, `j/k`, `e`, `c`, `r`, `?`).
- Anunciar cambios realtime con `aria-live="polite"` sin volver loca a la screen reader.
- Color **nunca** como único portador de información: severidad lleva color **+ icono + texto**.
- Soportar `prefers-reduced-motion` (sin highlights de realtime animados).
- Soporte de zoom hasta 200% sin romper layout.

## 5. Multi-tenant branding

El tenant puede configurar:
- Logo (reemplaza `app-logo`).
- Color primario (override `--primary` dentro de un scope `.tenant-{slug}`).
- Favicon.
- Dominio custom.

El DS debe aguantar **cualquier color primario razonable** del tenant sin romper contraste. Idealmente el diseñador propone un mecanismo de **generación automática de la escala** a partir del color primario del tenant (OKLCH lo hace trivial).

## 6. Entregables finales esperados

1. `app.css` actualizado con `@theme` y tokens light/dark completos.
2. Figma (o equivalente) con:
   - Página **Tokens** (color, tipografía, espaciado, radio, motion).
   - Página **Primitivos** (shadcn adaptados).
   - Página **Dominio** (todos los componentes de §3.4).
   - Página **Pantallas hero** (§3.5).
   - Página **Estados** (loading, empty, error, offline, permiso denegado, realtime).
3. Documentación markdown del sistema (uno por componente de dominio, con: anatomía, props, estados, do/don't).
4. Guía de voz/tono (1–2 páginas).
5. Paleta OKLCH exportable con light/dark y variante de alto contraste.
