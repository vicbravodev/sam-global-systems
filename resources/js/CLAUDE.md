# Frontend (resources/js/)

Inertia v3 + React 19 (React Compiler activo) + TypeScript estricto + Tailwind v4. Antes de crear algo, busca si ya existe: casi todo lo que una pantalla necesita está en las primitivas de abajo.

## Dónde va cada cosa

| Ruta                          | Qué va ahí                                                                                                                                                                                                                                        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pages/<feature>/*.tsx`       | Sólo composición: recibe props tipadas, arma la página con primitivas y componentes de la feature. Objetivo < 250 líneas; si crece, extrae a `components/sam/<feature>/`. Modelo: `pages/incidents/show.tsx` + `components/sam/incident-detail/`. |
| `components/sam/<feature>/`   | Componentes de la feature. Detalle en `<feature>/detail/`. Sus tipos en `types.ts`, textos/catálogos propios en `copy.ts`, helpers en `lib.ts`, hooks en `use-*.ts`.                                                                              |
| `components/sam/*.tsx` (raíz) | Primitivas genéricas de producto (ver catálogo). Nada específico de una feature.                                                                                                                                                                  |
| `components/ui/`              | shadcn vendorizado: no editar salvo los propios (`combobox`, `empty-state`, `page-header`, `pagination`, `sonner`, `switch`, `textarea`).                                                                                                         |
| `hooks/`                      | Hooks transversales (`use-server-list`, `use-team-broadcasts`, `use-realtime-connection`…).                                                                                                                                                       |
| `lib/`                        | Funciones puras: `format`, `time`, `labels`, `tone`, `initials`, `submit`, `sam-fetch`, `utils` (`cn`).                                                                                                                                           |
| `types/`                      | Tipos de dominio compartidos entre features (no importan de `components/`). `pagination.ts` = `ListPagination`.                                                                                                                                   |

Archivos en kebab-case. Named exports en todo salvo páginas y layouts (`export default`). Props exportadas como `interface XxxProps`; subcomponentes privados con tipo inline.

## Catálogo de primitivas (`components/sam/`)

- **Páginas de lista:** `ListPage` (shell: título, meta, acciones, `pulse`, `filters`, cuerpo, `footer`, `onRefresh`) + `ListEmptyState` (vacío vs. sin resultados) + `hooks/use-server-list` (filtros, `apply`, `goToPage`, `refresh`) + `list/*` (`SearchInput`, `FilterDropdown`, `ClearFiltersButton`, `ListFooter`) + `data-table/*` + `PulseStrip`.
- **Páginas de detalle:** `DetailHeader` (volver, título, chips, meta, acciones), `Panel` (bloque con encabezado), `DescriptionList`/`DescriptionItem`, `TabBar` (con `actions`).
- **Formularios:** Inertia `<Form>`/`useForm` con `FormField` (apilado: label, control, ayuda, error) o `Field` (dos columnas en ajustes, con `error`), `RadioCardGroup`/`RadioCard`, `PhoneInput` (país + número con formato; entrega E.164 vía `name` oculto u `onChange`; lógica en `lib/phone`), `VerificationCodeInput` (código SMS/2FA en casillas, `submitOnComplete`), `Step` (secciones numeradas), `ReadOnlyNotice`, `ConfirmDialog` (descripción `ReactNode`, `processing`).
- **Estado y datos:** `StatusBadge` (cualquier estado: `tone` + `label`, `dot`/`icon`, `size="sm"` compacto), `SeverityBadge` (escala de severidad), `StatusPill` (estado de incidente), `MetaChip` (metadato neutro), `Meter`, `KpiStrip`, `RelativeTime`, `SlaCountdown`, `RealtimeStatus`, `UserAvatar`/`EntityAvatar` (+ `lib/initials`), `ui/spinner` (no `Loader2` suelto), `ui/skeleton`.

Si necesitas una variante, extiende la primitiva con una prop; no copies su markup en la página.

## Datos: leer, navegar, mutar

- **Props:** la página recibe sus props tipadas como argumento (`export default function Page({ incidents }: IncidentsPageProps)`). Nunca `usePage().props as unknown as …`. Las compartidas (`auth`, `currentTeam`, `permissions`…) ya están tipadas en `types/global.d.ts`: `usePage().props.currentTeam`.
- **URLs:** siempre Wayfinder: `import incidentRoutes from '@/routes/incidents'` → `incidentRoutes.show.url([teamSlug, id])`, o el objeto de ruta directo en `<Link href>`/`router.visit`. Query strings con `{ query: {...} }`. Nada de `` `/${slug}/…` `` ni rutas literales. Son generados y gitignored: tras cambiar rutas, `php artisan wayfinder:generate --with-form`.
- **Recargas:** `router.reload({ only: [...] })` siempre con `only`. Props caras → `Inertia::defer()` en el controlador + `<Deferred>` con `Skeleton`.
- **Mutaciones:** formularios de página con `<Form>`/`useForm` + ruta Wayfinder; acciones JSON con `submit(postJson(ruta.url(...), body), 'Mensaje')` de `lib/submit` (toast, 403/422, recarga). Lecturas JSON, subidas y streaming con `getJson`/`postFormData`/`postStream` de `lib/sam-fetch` (CSRF por cookie). `fetch` crudo sólo para URLs externas. **Axios no existe** (Inertia v3 lo retiró; lint lo prohíbe).
- **Tiempo real:** `useTeamBroadcast` / `useBroadcastReload` de `hooks/use-team-broadcasts` (el payload se estrecha por `detail.event`, sin casts). Nunca `window.addEventListener` directo ni un debounce propio.
- **Inertia v3:** `Inertia::lazy()` → `Inertia::optional()`; eventos `invalid` → `httpException`, `exception` → `networkError`; `router.cancel()` → `router.cancelAll()`.

## Textos y formato

- Copy en es-MX. Códigos del backend → etiqueta con `lib/labels.ts` (`priorityLabel`, `actionLabel`, `humanizeCode`…); si es propio de una feature, en su `copy.ts`. No declares mapas `*_LABELS` sueltos en páginas.
- Estados con color: mapa `valor → { label, tone }` (`ToneLabel` de `lib/tone.ts`; en `lib/labels.ts` si lo usan varias features, como `ASSET_STATUS`/`DRIVER_STATUS`, si no en el `copy.ts` de la feature) pintado con `<StatusBadge {...MAPA[valor]} />`. Para puntos, texto, superficies o mapas usa `TONE_DOT`/`TONE_TEXT`/`TONE_SURFACE`/`TONE_VAR`; severidad con `SEVERITY_DOT`/`SEVERITY_TEXT`/`SEVERITY_BORDER` de `event-severity.ts`. Nunca clases `bg-severity-*`/`text-health-*` sueltas para un estado ni un tipo `XxxTone` nuevo.
- Números, moneda y fechas sólo vía `lib/format.ts` (`formatNumber`, `formatPercent`, `formatCurrency`, `formatDate`, `formatDateTime`, `formatShortDate`, `formatMonthYear`, `formatDateWith`) y `lib/time.ts` (`relativeLabel`/`ageLabel` con estilo `short` "hace 3 min" o `long` "hace 3 minutos", `durationLabel`, `formatClock`, `dayLabel`). Nada de `toLocaleString`/`Intl` directo ni un "hace N…" propio.

## Estilo

- **Tokens de diseño** en `@theme` de `resources/css/app.css`. Usa las utilities (`text-3xs`…`text-3xl`, `tracking-label`, `tracking-caps`, `rounded-sm/md/lg/xl`, `sam-caps`, `sam-h1`…`sam-h4`, `sam-meta`), nunca tamaños arbitrarios (`text-[12px]`: lint lo rechaza). Ojo: `text-sm` = 13px y `text-base` = 14px.
- Clases condicionales con `cn()`, no template literals.

## Rendimiento

- **React Compiler:** no uses `useMemo`/`useCallback`/`memo` salvo que una dependencia de efecto necesite identidad estable. Nunca `eslint-disable react-hooks/*`: el compilador salta ese componente. Para handlers "más recientes" en efectos, `useEffectEvent`; no copies props a estado con efectos (deriva en render o usa `key`). El compilador no soporta `try/finally` dentro del componente: lleva esa lógica a un helper fuera.
- **Code-splitting:** librerías pesadas (maplibre, editores, paneles que sólo se abren a demanda) con `React.lazy` + `Suspense` del mismo tamaño que el contenido. Ejemplos: `lazy-point-map.tsx`, `copilot-bubble.tsx`, `pages/assets/map.tsx`.
- Imágenes de listas/galerías con `loading="lazy" decoding="async"` y dimensiones.
- Filas de listas grandes como componente propio (el compilador memoiza por componente, no dentro de `.map`).

## Accesibilidad

Todo `<button>` con `type` (lint), botones de icono con `aria-label`, inputs con label asociado, filas clicables con `role`, `tabIndex` y teclado.

## Tests (Vitest)

Se prueba la lógica, no el markup: funciones de `lib/`, los `lib.ts` de cada feature y los hooks con estado propio (recargas por broadcast, relojes, reductores). Nada de snapshots ni tests de "renderiza X": eso ya lo cubren `assertInertia` en PHPUnit y los tipos.

- Archivo `*.test.ts` junto al código (`lib/format.test.ts`, `components/sam/inbox/lib.test.ts`). `npm test` (una vez) · `npm run test:watch`.
- Imports explícitos de `vitest` (sin globals). Hooks con `renderHook` + `act` de `@testing-library/react`; el desmontaje entre tests ya está en `test-setup.ts`.
- Zona horaria fija `America/Mexico_City` (`vitest.config.ts`): escribe las fechas esperadas en hora de CDMX. Tiempo con `vi.useFakeTimers()` + `vi.setSystemTime()`.
- Se simula sólo el borde: `@inertiajs/react` (`router`, `usePage`), `sonner`, `@/echo`, `fetch` (`vi.stubGlobal`). La lógica bajo prueba, nunca.
- Un helper nuevo en `lib/` o un `lib.ts`/hook con reglas de negocio llega con su test.

## E2E (Playwright)

Sólo flujos críticos de punta a punta (`tests/e2e/*.spec.ts`): login, pánico firmado → bandeja → detalle, tomar/resolver, cambio de Secret Key y alta de cliente. No es para cubrir pantallas: un flujo entra aquí si romperlo deja un pánico sin atender o a un cliente sin operar.

- `npm run build && npm run test:e2e`. Levanta `php artisan serve` en `:8123` con SQLite propio (`database/e2e.sqlite`), cola `sync` (el pipeline entero corre dentro del webhook), sin sockets ni llaves de IA/Twilio (`tests/e2e/support/env.ts`). No toca la base de dev. Con `public/hot` presente usa el dev server de Vite.
- Datos: `E2eSeeder` (dev + integración Samsara con Secret Key conocida + 2FA del super-admin). Antes de cada test se restaura la base sembrada y se vacía la caché (`support/test.ts`): los tests no dependen de su orden.
- Sesiones por rol en `auth.setup.ts` → `test.use({ storageState: storageStateFor('monitor') })`. Pánicos con `sendPanic(request, { secret?, vehicle? })` (firma HMAC como Samsara).
- Selectores por rol y texto visible (`getByRole`, `getByLabel`), nunca clases ni `data-*` de estilo. Si un selector no se puede escribir así, falta accesibilidad en el componente.
- Tiempo real (Soketi) queda fuera: tras mandar el pánico se recarga la página.

## Gates y entorno

- `npm run types:check && npm run lint:check && npm run format:check && npm test` (y `npm run build`).
- Si un cambio no se ve en la UI, falta `npm run dev` / `npm run build`. Error "Unable to locate file in Vite manifest" → `npm run build`.
