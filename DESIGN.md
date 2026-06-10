# DESIGN.md — Sistema de diseño SAM

Fuente de verdad operativa del design system. Los tokens viven en [`resources/css/app.css`](resources/css/app.css); este documento explica cómo usarlos. Contexto de producto en [`PRODUCT.md`](PRODUCT.md).

## Tokens

### Superficies y texto

| Token | Uso |
|-------|-----|
| `bg-background` / `text-foreground` | Lienzo base de la app |
| `bg-surface-1` | Cards, popovers (igual que `bg-card`) |
| `bg-surface-2` | Hover de filas, chips, fondos secundarios |
| `bg-surface-3` | Fondos hundidos (wells), zonas de menor jerarquía |
| `text-fg-1` | Texto principal |
| `text-fg-2` | Texto secundario (labels) |
| `text-fg-3` | Meta, ayudas, placeholders descriptivos |
| `text-fg-disabled` | Deshabilitado |
| `border-border` / `border-border-strong` | Bordes normal / enfatizado |

### Color funcional

- **Primario** (`primary`, slate indigo): acción primaria y selección. `--primary-hover` / `--primary-active` existen para estados.
- **Acento** (`accent`, ámbar cálido): atención puntual (banner de impersonación, flash realtime). NO es un color decorativo.
- **Destructive** = `--severity-critical`. Para errores de input usar `text-destructive`; para zonas de peligro `border-destructive/20 bg-destructive/5`.
- **Éxito**: no hay token "success"; usar `text-health-ok` para confirmaciones de estado.
- **Severidad** (5): `severity-critical/high/medium/low/info` + variantes `-bg`.
- **Ciclo de vida de incidente** (7): `status-new/triaging/assigned/in-progress/resolved/closed/discarded`.
- **Salud de integraciones**: `health-ok/warn/down/unknown`.
- **IA**: `ai-accent`, `confidence-low/mid/high`.

**Prohibido**: clases de paleta Tailwind (`text-red-600`, `bg-amber-500`...). Todo color pasa por token.

### Sombras

Escala tintada al hue de fondo (260), definida en `@theme` — las utilidades estándar `shadow-xs/sm/md/lg/xl` ya la usan. La intensidad varía por modo vía `--shadow-color-1/2`. No inventar `shadow-[...]` ad-hoc.

### Radius

`--radius-sm` 4px (chips, kbd) · `--radius-md` 6px (inputs, botones) · `--radius-lg` 10px (**cards**) · `--radius-xl` 14px (modales grandes). Cards usan `rounded-lg`, no `rounded-xl`.

### Tipografía

Clases semánticas `.sam-*`: `sam-display/h1/h2/h3/h4/body/body-sm/meta/label/caps/code/num/kbd`. Para números en tablas: `sam-num` o `font-mono tabular-nums`. Escala px en `--text-2xs..3xl` (10–36px, base 14).

### Densidad

Tokens `--row-compact` 32px · `--row-comfortable` 40px · `--row-relaxed` 56px. Las filas de tablas con toggle de densidad mapean a `h-(--row-compact)` etc., nunca alturas mágicas.

### Motion

| Token | Valor | Uso |
|-------|-------|-----|
| `--motion-instant` | 50ms | feedback inmediato |
| `--motion-fast` | 120ms | hover, active |
| `--motion-normal` | 200ms | popovers, entradas |
| `--motion-slow` | 320ms | transiciones de layout |
| `--ease-out` | cubic-bezier(0.16,1,0.3,1) | default para todo |

Reglas: solo `transform`/`opacity`; entradas desde `scale(0.97)+opacity` (nunca `scale(0)`); exits más rápidos que enters; nunca animar acciones de teclado; `prefers-reduced-motion` ya cubierto globalmente.

## Vocabulario de componentes

Primitivos en `resources/js/components/ui/` (shadcn + propios): `button`, `input`, `textarea`, `select`, `combobox` (HeadlessUI, buscable, `allowCustom` para valores libres), `radio-group`, `checkbox`, `dialog`, `sheet`, `dropdown-menu`, `tooltip`, `badge`, `card`, `skeleton`, `pagination`, `empty-state`, `page-header`.

Componentes de dominio en `resources/js/components/sam/`: `severity-badge`, `status-pill`, `provider-tag`, `confidence-bar`, `sla-countdown`, `relative-time`, inbox, incident-detail, etc.

Reglas:

- **Header de página**: siempre `<PageHeader title meta actions>`. No inventar headers.
- **Listas vacías**: siempre `<EmptyState icon title description action>` con CTA que enseña ("Crea tu primera regla").
- **Paginación**: `<Pagination page totalPages onPageChange>` — "Anterior/Siguiente".
- **Errores de formulario**: label arriba, helper text, `<InputError>` debajo del input.
- **Modal solo cuando hay que interrumpir**; preferir edición inline o `<Sheet>`.

## Glosario ES (copy)

| EN | ES |
|----|----|
| Save | Guardar |
| Cancel | Cancelar |
| Delete | Eliminar |
| Edit | Editar |
| Create | Crear |
| Search | Buscar |
| Settings | Configuración |
| Are you sure...? | ¿Seguro que quieres...? |
| No results | Sin resultados |
| Previous / Next | Anterior / Siguiente |
| Loading | Cargando |
| Required | Obligatorio |
| Dashboard | Panel |
| Team | Equipo |
| Owner / Admin / Member | Propietario / Administrador / Miembro |

Español neutro, sin anglicismos ("Guardar", no "Salvar"; "Eliminar", no "Borrar" para acciones destructivas permanentes). Sin em dashes en copy de UI.

## Checklist de cierre por pantalla

1. ¿Cero colores hardcodeados? (grep `red-|green-|blue-|amber-|yellow-`)
2. ¿Estados completos en cada interactivo?
3. ¿Empty state con CTA?
4. ¿Funciona en dark (default) y light?
5. ¿Responsive móvil + desktop?
6. ¿Copy 100% español del glosario?
7. ¿Un usuario fluido en Linear/Stripe confiaría en esta pantalla?
