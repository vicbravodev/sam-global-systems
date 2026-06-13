# ROADMAP_UI.md — Auditoría de UI por estrés v2 (2026-06-12)

Auditoría de interacción sobre la app real (`http://localhost`, tenant ServiExpress JC, datos seeded
del pipeline Samsara) con Playwright CLI: 30 rutas navegadas e interactuadas en desktop 1440×900 y
móvil 390×844, ambos temas en dashboard/bandeja/detalle/forms/errores, todos los counts verificados
contra PostgreSQL, todos los forms estresados (vacío / inválido / `<script>` / 500 chars / doble
click), 5 flujos completos ejecutados de punta a punta. La evidencia (129 screenshots y reportes
por bloque) fue artefacto de la sesión de auditoría y no se versiona; cada hallazgo de este
documento incluye su repro y criterio de aceptación de forma autosuficiente.

Esta v2 complementa [`docs/FRONTEND-ROADMAP.md`](docs/FRONTEND-ROADMAP.md) (auditoría visual
2026-06-12): no duplica sus hallazgos — los re-verifica (§4) — y profundiza donde aquella no llegó:
interacción, flujos, validación y estados intermedios.

---

## 1. Resumen ejecutivo

**Veredicto global:** la capa visual es sólida y el happy path de mouse en desktop funciona, pero
la app **miente al operador** en varios puntos de datos, **atrapa al usuario en móvil**, y sus
forms del shell ops **aceptan basura y crean duplicados**. Cero errores 5xx, cero XSS (todo
escapado), consola limpia en casi todo — los problemas son de producto, no de estabilidad.

**Los 5 hallazgos más graves:**
1. **Móvil agravado de "se ve mal" a "atrapa al operador"** (C2): en 390px el panel de detalle de
   incidente no se puede cerrar (botón fuera de viewport con `overflow hidden`) y el textarea de
   comentario mide 0px; los forms de reglas colapsan a inputs de ~30px.
2. **"Visto hace 4 min" en activos muertos hace 190 días** (C1-a): `last_seen_at` se bumpea en
   bloque con el poll de sync para los 255 activos; un activo sin dispositivo figura "Activo · visto
   hace 4 min".
3. **El mismo incidente muestra 3 estados distintos** (C1-b): "Escalated" en el detalle del activo,
   "In progress" en la bandeja, "escalated" en la paleta — verificado en el mismo minuto.
4. **Doble click = registros duplicados** (C3): "Crear regla" y "Crear workflow" sin guard de
   submit ni unicidad → 2×201; además los errores 422 salen como un único toast en inglés que
   desaparece en 4s (parece fallo silencioso), y tenant-config acepta GPS `-5` y color `no-es-color`.
5. **El shell ops no tiene salida**: sin menú de usuario ni "Cerrar sesión" (avatar estático),
   campana muerta con punto rojo hardcodeado, ⌘K anunciado pero inexistente, paleta que abre sin
   foco y solo busca incidentes.

**Quick win descubierto:** todos los errores de servidor en inglés son 2 líneas de `.env`
(`APP_LOCALE=en` → `es`) — `lang/es/` ya existe completo y `config/app.php` ya defaultea a `es`.

**Vs. la auditoría anterior:** 6 hallazgos resueltos o casi (F0.7, F1.3 parcial, F2.2 parcial,
F2.3, F2.5, F3.5), 16 confirmados vigentes (F0.1–F0.6, F1.1–F1.6, F2.4, F3.1–F3.4, F3.6, F4.3),
y **~60 hallazgos nuevos** (7 Critical · 26 High · 27 Medium · ~16 Low) que esa pasada visual no
podía ver porque no interactuaba.

---

## 2. Veredicto por pantalla

| Ruta | Promedio rubric | Semáforo | Hallazgo dominante |
|---|---|---|---|
| `/settings/appearance` | 4.1 | 🟢 | El mejor form simple del producto |
| `/settings/teams` + `/teams/{id}` | 3.7 | 🟢 | Modal de invitación ejemplar; email sale en inglés |
| `/login` · `/register` · `/forgot-password` | 3.5 | 🟡 | Errores de servidor en inglés; doble POST; enumeración de usuarios |
| `/settings/profile` | 3.5 | 🟡 | Correcto; validación en inglés |
| `/settings/notifications` | 3.5 | 🟡 | Claves crudas `incident.assigned.on_call` |
| `/events` (lista) | 3.4 | 🟡 | Sort client-side que miente sobre el conjunto |
| `/integrations` | 3.4 | 🟡 | Draft sucio persiste tras Escape |
| `/settings/roles` | 3.25 | 🟡 | `PUT → 405` en consola tras cada cambio de rol |
| `/assets` | 3.1 | 🟡 | Columna "Visto" falsa para los 255 activos |
| `/incidents/{id}` | 3.0 | 🟡 | Timeline con spam "Assigned to queue #1" ×19 |
| `/notifications` | 3.0 | 🟡 | Las 282 notificaciones en estado "Cancelada" sin explicación |
| `/assets/map` | 2.9 | 🟡 | Pilas de markers inaccesibles; a11y nula |
| `/assets/{id}` | 2.9 | 🟡 | Estado de incidente contradictorio; sin conductor |
| `/billing` | 2.9 | 🟡 | Página sin un solo elemento interactivo |
| Búsqueda global (flujo) | 2.9 | 🟡 | ⌘K muerto, sin foco al abrir, solo incidentes |
| `/incidents` (bandeja) | 2.75 | 🟡 | Filtro Estado no contiene el estado del 97.5% de los datos |
| `/automation` | 2.75 | 🟡 | Workflows imposibles de editar tras crearse |
| `/audit` | 2.75 | 🟡 | FQCN `App\Domains\…` expuestos; Resumen = Acción |
| `/drivers` + `/drivers/{id}` | 2.7 | 🟡 | Módulo hueco: 3 columnas y 5 secciones siempre vacías |
| `/dashboard` | 2.7 | 🟡 | Topbar con 3 controles muertos; filas sin deep-link |
| `/rules` | 2.6 | 🟡 | Duplicados por doble click; validación invisible |
| `/` (welcome) | 2.6 | 🔴 | Starter de Laravel intacto |
| `/settings/tenant-config` | 2.5 | 🟡 | Acepta valores sin sentido; versiones que no registran |
| `/events/{id}` | 2.4 | 🔴 | Panel Contexto muerto al 100%; media intriageable |
| `/analytics` | 2.4 | 🔴 | Vacío absoluto sin ninguna acción posible |
| 404 / 403 (todas las variantes) | 2.0 | 🔴 | Default Laravel: blanca, inglés, sin salida, ignora el tema |
| `/settings/security` | — | ⚪ | No auditable (gate confirm-password, ver apéndice) |
| `/admin/*` | — | ⚪ | No auditable (403 esperado, ver apéndice) |

Móvil puntúa 1 en todas las rutas del shell ops por la misma causa raíz (C2).

---

## 3. Hallazgos consolidados (los 4 Critical raíz + High por eje)

Los IDs (A1…, B1…, C-01…, D-01…, E1…) indican el bloque de auditoría que produjo cada hallazgo
(A dashboard/eventos · B incidentes/notificaciones · C flota/analítica · D configuración/forms ·
E públicas/bordes/legacy). Vista consolidada y deduplicada, con repro y criterio por hallazgo.

### C1 — La UI miente con datos de operación *(Critical · datos)*

- **C1-a "Visto hace X min" falso en flota** *(C-01)*. Los 255 activos muestran "visto hace 2–4
  min" a la vez porque el sync bumpea `last_seen_at` en bloque; activos "YA REMPLAZADO (SIN
  DISPOSITIVO VINCULADO)" con última posición de hace 190 días figuran "Activo · Visto hace 4 min".
  Repro: `/assets` → buscar "REMPLAZADO" → abrir `/assets/249`. Criterio: el "Visto" de dos activos
  difiere si sus señales difieren; >24h sin posición ⇒ no se muestra "hace N min".
- **C1-b Estados de incidente contradictorios entre superficies** *(C-02, B14)*. INC-2026-00008:
  "Escalated" en `/assets/130`, "In progress" en `/incidents`, "escalated" en la paleta — mismo
  minuto, verificado recargando. Criterio: la cadena de estado renderizada es idéntica en bandeja,
  detalle, paleta y detalle de activo.
- **C1-c Badges del sidebar hardcodeados** *(F0.2 vigente)*. `ops-layout.tsx:15`:
  `{ inbox: 14, rules: 2, integrations: 1 }` contra 39 incidentes abiertos y 70 reglas reales.
- Relacionados High: filtro Estado de la bandeja sin la opción "In progress" (estado del 97.5% de
  los datos → todo filtro da 0) *(B5)*; sort de `/events` reordena solo la página visible sin
  request ni cambio de URL — el "más viejo" mostrado es 8 días más nuevo que el real *(A1)*;
  el incidente crítico activo ni aparece en el top-5 del dashboard *(A9, Medium)*; las 282
  notificaciones en "Cancelada" sin explicación *(B10, Medium)*; "Versiones" de tenant-config no
  registró ninguno de los 4 guardados de la sesión *(D-18, Medium)*.

### C2 — Móvil: de "se ve mal" a "atrapa al operador" *(Critical · F0.1 agravado)*

Confirmado en TODAS las rutas del shell ops (sidebar fija ~232px deja ~155–160px de contenido;
el colapso manual existe pero NO persiste entre navegaciones). Lo nuevo de esta pasada:
- **Panel de detalle de incidente sin salida** *(B1)*: grid `[1fr_minmax(520px,700px)]` en
  contenedor de 332px con `overflow-x: hidden` → el botón "Cerrar detalle" queda en x=418 (fuera
  de un viewport de 390) e inalcanzable; el textarea de comentario mide **0px** (imposible comentar
  desde móvil).
- **Forms inutilizables** *(D-03)*: inputs de ~30px de ancho en "Nueva regla" (se lee "co", "No"),
  mismo patrón en automation y tenant-config.
- Mapa de ~160px mostrando el hemisferio; título "Flota" truncado a "FL…" *(C-03)*.

Criterio de cierre: en 390px — navegar con sidebar drawer/overlay, abrir y CERRAR un incidente,
escribir un comentario, y crear una regla de punta a punta sin overflow horizontal.

### C3 — Forms del shell ops: duplicados y basura aceptada *(Critical · forms)*

- **Doble click crea duplicados** en "Crear regla" *(D-01)* y "Crear workflow" *(D-02)*: 2×POST
  → 2×201, sin guard `processing` ni unicidad de `code` por tenant. El mismo patrón sin guard
  existe en login/register/forgot-password (2 POST medidos, quema rate-limit) *(E3, High)*.
  Contraste: el modal de invitación SÍ tiene el guard y emitió 1 solo POST — ese es el patrón a
  copiar.
- **Errores 422 = un toast en inglés que se esfuma** *(D-04, High)*: "The name field must not be
  greater than 200 characters.", solo el primer error, nada inline, y en el submit vacío el toast
  expiró dejando cero rastro (fallo aparentemente silencioso).
- **JSON inválido en modo avanzado guarda OTRAS condiciones silenciosamente** *(D-05, High)*:
  `{esto no es json` → toast "Regla creada." con las condiciones del builder previas.
- **tenant-config acepta valores sin sentido** *(D-07, High)*: GPS staleness `-5` y color primario
  `no-es-color` → 200 + toast de éxito, persisten tras reload.
- **Override acepta código de regla inexistente** *(D-06, High)*: `AUDIT-no-existe` → 201 y fila
  apuntando a nada.

### C4 — El shell ops no tiene salida ni atajos reales *(High agrupado · shell)*

- **Sin menú de usuario ni logout** *(E1/A7)*: el chip "Admin ServiExpress / Supervisor" es un
  `div` estático; cerrar sesión exige teclear la URL del shell legacy. Criterio: logout en ≤2
  clicks desde cualquier página ops.
- **Campana muerta con punto rojo hardcodeado** *(B4/A6)*: sin `onClick`, `bg-severity-critical`
  fijo en el JSX (`ops-topbar.tsx:100-107`).
- **⌘K sigue sin existir** *(F0.3 vigente)* y la paleta **abre sin foco en el input** *(A2)*:
  `useEffect(..., [])` corre al montar con `open=false` (`command-palette.tsx:68-71`) — teclado
  muerto hasta click manual.
- **La búsqueda promete "incidentes, activos…" pero el endpoint solo devuelve `{"incidents"}`**
  *(A3)*: buscar `T-426` (activo real) → "Sin resultados".
- **Atajos J/K/A/X anunciados en el footer de la bandeja no tienen handler** *(B3)*; la píldora
  "Conectado" es un `<button>` inerte *(A12, Medium)*.

### Highs restantes por eje

- **Eventos** *(bloque A)*: panel CONTEXTO dice "Sin datos." para el 100% de los eventos — el
  controller lee `normalized_events.context_json` (NULL en las 378 filas) en vez de
  `event_context_snapshots` que SÍ tiene los datos (`EventsPageController.php:175`) *(A4)*; galería
  MEDIA (18) = tiles grises sin thumbnail/cámara/hora que abren el archivo crudo de RustFS en otra
  pestaña — intriageable *(A5)*; evento sin media no ofrece el "Solicitar media" que el backend ya
  expone *(A13, Medium)*; payloads JSON no colapsables dominan la página *(F4.3 vigente)*.
- **Dashboard** *(bloque A)*: las filas de "Incidentes abiertos" navegan a la lista genérica, no a
  `/incidents/{id}` — la paleta sí deep-linkea *(A8)*.
- **Bandeja** *(bloque B)*: "Asignarme crítico más viejo" reasigna en bucle el mismo incidente ya
  mío (3 clicks → 3 POST 201 → 3 entradas de timeline idénticas; el filtro no excluye asignados y
  el backend no es idempotente) *(B2)*; las asignaciones no emiten broadcast
  (`AssignIncident.php` no dispara `IncidentUpdatedBroadcast`; ACK/Close/Escalate sí) → la bandeja
  "Conectada" de otro operador nunca se entera *(B6)*; timeline contaminado con "Assigned to queue
  #1" ×15–19 cada ~3s — ruido de job en loop que entierra el historial *(B7)*; tab "Míos" compara
  por INICIALES del asignado, no por id *(B11, Medium)*; tabs vacíos sin empty state *(B12,
  Medium)*; foco de teclado invisible en filas (outline none medido — Enter funciona pero a ciegas)
  *(B13, también C-07 en assets/drivers)*.
- **Flota/mapa** *(bloque C)*: 24 markers apilados en 9 coordenadas exactas, solo el de arriba es
  clickeable, sin clustering/spiderfy *(C-04)*; markers sin `tabindex` y con `aria-label="Map
  marker"` ×254 *(C-05)*; "Incidentes vinculados" del activo enlaza a la bandeja genérica, no al
  incidente *(C-06)*; relación activo↔conductor invisible en ambas direcciones aunque incidentes
  la conoce *(C-08)*; tiles del mapa siempre en estilo claro sobre UI oscura, sin skeleton *(C-14,
  Medium)*.
- **Analítica**: módulo completo vacío sin un solo control — promete "reportes descargables" y no
  ofrece camino a ninguno *(C-09)*.
- **Reglas/automation** *(bloque D)*: workflows NO editables ni eliminables tras crearse *(D-09)*;
  en reglas solo las condiciones son editables — nombre/código/prioridad/outcome inmutables sin
  aviso *(D-10, Medium)*; "Eliminar" override borra sin confirmación mientras roles sí confirma
  *(D-11, Medium)*; política de notificación añadida no se puede quitar antes de guardar — única
  salida: recargar perdiendo todo *(D-12, Medium)*.
- **Roles**: cada cambio de rol deja `PUT … → 405` en consola (redirect 302 que el browser re-emite
  como PUT; debe ser 303) *(D-08)*.
- **Locale/errores** *(bloque E)*: TODOS los errores de servidor en inglés pese a existir `lang/es/`
  completo — causa raíz `APP_LOCALE=en` en `.env` *(E2)*; páginas 403/404 default Laravel que
  además ignoran el tema de la app *(F0.4 vigente, A11, C-12)*; enumeración de usuarios en
  forgot-password *(E4, Medium)*; gate confirm-password con layout de invitado que saca al usuario
  del contexto *(E7, Medium)*.

### Mediums transversales (idioma y jerga — un solo esfuerzo)

Spanglish y slugs internos en todas partes *(A10, B8, C-10, C-11, D-13/14/15, E5/E6)*: estados
("In progress", "Active", "Vehicle"), timeline ("Incident resolved: handled_successfully"),
notificaciones (títulos en inglés + slug `incident.sla_breached` visible), FQCN
`App\Domains\…` en auditoría, model-id crudo "laravel-ai-sdk:gpt-5.4… · 0 ms" en la evaluación IA,
códigos de spec internos en copys de UI ("(P5)", "B9"), email de invitación "You've been invited…",
menú "Teams / New team", filtros con enums crudos. Y coordenadas lat/long sin geocodificar como
"ubicación" en la bandeja *(B15)*; edades sin humanizar "Creado hace 9941 min" *(B9)*.

### Lows (pulido, agrupados)

IDs de incidente en 3 formatos (`INC-40` / `INC-00040` / `INC-2026-00040`); "1 seleccionados";
voseo "Escribí…" vs tuteo; paginadores y kebabs sin `aria-label`; sin `aria-sort`; tabs y sort que
no van a la URL (reload los pierde) *(D-19, B16.7, C-13, C-15)*; drafts sucios al reabrir modales
*(D-17, Medium)*; passwords no se limpian tras error; targets <40px en login móvil; email sin TLD
aceptado en invitaciones; logo negro sin glifo en login claro; warning maplibre recurrente;
2 warnings Radix `aria-describedby`; "· —" colgantes; "últimas 0"; subtítulo erróneo en vista
"Sin mapear".

---

## 4. Estado de los hallazgos previos (docs/FRONTEND-ROADMAP.md)

| Previo | Estado v2 | Evidencia clave |
|---|---|---|
| F0.1 móvil shell | **Vigente y agravado** (C2: atrapa, no solo aplasta) | verificado en los 5 bloques |
| F0.2 badges sidebar | **Vigente** (`ops-layout.tsx:15`; 14 vs 39 reales) | A/B/D |
| F0.3 ⌘K | **Vigente** (Meta+k y Control+k probados; único listener global es Escape) | A |
| F0.4 páginas de error | **Vigente** + dato nuevo: ignoran el tema de la app | E |
| F0.5 APP_NAME/lang | **Vigente** + causa raíz: puro `.env` (config y lang/es ya correctos) | E §0 |
| F0.6 link Configuración legacy | **Vigente** (footer → dashboard; el del dropdown sí va bien) | E |
| F0.7 tab SLA crítico | **Resuelto en código** (filtro real `slaSeconds<900`), indistinguible con seed (todo vencido) | B |
| F1.1 shell legacy settings | **Vigente** (matiz: tipografía ya es la misma Geist) | E |
| F1.2 empty states | **Parcial**: automation aceptable ya; analytics y billing vigentes | C/D |
| F1.3 KPIs falsos | **Parcialmente resuelto**: datos reales con null-handling; residuo "PRECISIÓN IA 100%" vacuamente cierto | A |
| F1.4 drivers hueco | **Vigente y generalizado**: no existe ningún conductor "rico" (muestreo 6 ids) | C |
| F1.5 welcome starter | **Vigente** (links a laravel.com, Instrument Sans de bunny.net) | E |
| F1.6 fuentes CDN | **Vigente** (googleapis + bunny.net medidos en performance entries) | E |
| F2.2 teclado en tablas | **Parcial**: tabindex+Enter SÍ; foco invisible (B13/C-07) y atajos J/K/A/X fantasma (B3) | A/B/C |
| F2.3 loading states | **Resuelto** en media de incidente; mapa con alto reservado pero sin skeleton (C-14) | B/C |
| F2.4 placeholders | **Vigente** ("—" / "Sin asignar" / "Samsara · —" en la misma fila) | C |
| F2.5 reduced motion | **Resuelto de facto** (sparkline ya estático) | A |
| F3.1 jerarquía dashboard | **Vigente** (+ crítico invisible en top-5, A9) | A |
| F3.2 mono tabular | **Vigente mutado** (slugs en mono bajo títulos de notificaciones) | B/C |
| F3.3 chips sin sistema | **Vigente** (5 estilos distintos contados) | D |
| F3.4 keys crudas tenant-config | **Vigente** (hasta en labels de controles "amigables") | D |
| F3.5 mapa | **Mayormente resuelto** (fitBounds+leyenda+empty state existen); residuo: bounding box estirado por activos en EE. UU., sin "centrar flota" | C |
| F3.6 favicon/OG | **Vigente** (favicon default, 0 metas og:) | E |
| F4.3 payloads colapsables | **Vigente** (`JsonBlock` con scroll, no colapsable) | A |
| F4.4 asignarme crítico | **Feedback resuelto** (spinner/toast/reload) **pero nuevo bug funcional** (B2: bucle sobre el ya asignado) | B |

---

## 5. Roadmap priorizado

Cada ítem = un PR razonable con criterio de cierre verificable. Las reglas de CLAUDE.md §8.5
aplican: feature test (`assertInertia`) por página tocada + gates de front verdes.

### Fase 0 — Deja de mentir / deja de atrapar (Critical)

- [x] **R0.1 Quick wins de locale e identidad** *(E2, F0.5, D-08)*: `APP_LOCALE=es`,
  `APP_FALLBACK_LOCALE=es`, `APP_NAME=SAM` en `.env` + `.env.example`; redirect 303 tras el PUT de
  cambio de rol. Cierre: login fallido en español, `<html lang="es">`, títulos "… - SAM", cero 405
  en consola al cambiar un rol.
- [ ] **R0.2 Móvil del shell ops** *(C2 = F0.1 + B1 + D-03 + C-03)*: sidebar drawer/overlay bajo
  `lg:` con persistencia, panel de detalle de incidente full-width en <768px con cierre visible y
  textarea usable, forms apilados a 1 columna. Cierre: en 390px se puede navegar, abrir/cerrar/
  comentar un incidente y crear una regla sin overflow horizontal.
- [x] **R0.3 Datos veraces de flota e incidentes** *(C1-a, C1-b, B5)*: `last_seen_at` por señal
  real (o renombrar a "Última sincronización" + degradar estado sin señal); estado canónico único
  de incidente en bandeja/detalle/paleta/activo; filtro Estado con los estados reales y en español.
  Cierre: criterios de C1-a/C1-b y B5 (§3).
- [ ] **R0.4 Anti-duplicados y validación visible en forms ops** *(D-01, D-02, E3, D-04, D-05,
  D-06, D-07)*: guard `processing` transversal (patrón del modal de invitación), unicidad de `code`
  por tenant, errores 422 inline en español y persistentes, submit bloqueado con JSON inválido,
  validación de `rule_code` existente y de rangos/hex en tenant-config. Cierre: doble click = 1
  registro; cada repro de D-04..D-07 rechazado con error junto al campo.

### Fase 1 — El shell ops completo (High)

- [ ] **R1.1 Topbar vivo** *(E1/A7, B4/A6, A12, F0.2, F0.3, A2, A3)*: menú de usuario con
  Perfil/Configuración/Cerrar sesión; campana que abre notificaciones con badge real (o se quita);
  píldora Conectado no-focusable o con popover; badges de sidebar desde shared prop con counts
  reales; listener global Cmd/Ctrl+K; foco al abrir la paleta (`useEffect` dependiente de `open`);
  palette-search que incluya activos o copy que no los prometa. Cierre: logout en ≤2 clicks, ⌘K
  abre y se puede operar sin mouse, badge = count real.
- [ ] **R1.2 Bandeja honesta** *(B2, B3, B6, B7, B11, B12, B13+C-07)*: "Asignarme crítico" excluye
  asignados + idempotencia backend; atajos J/K/A/X reales o quitar el hint; broadcast en
  `AssignIncident`; dedup del spam "Assigned to queue" (backend) + colapso de entradas idénticas
  consecutivas (UI); tab Míos por `assignee.id`; empty state por tab; `focus-visible` en filas de
  todas las tablas. Cierre: criterios de B2/B3/B6/B7 (§3).
- [ ] **R1.3 Detalle de evento útil** *(A4, A5, A13, F4.3, A1)*: contexto proyectado desde
  `event_context_snapshots`; galería con thumbnails + cámara/hora + lightbox; CTA "Solicitar media"
  en eventos sin media; payloads en acordeón colapsado con copy-button; sort server-side en
  `/events` (param en URL). Cierre: criterios de A1/A4/A5 (§3).
- [ ] **R1.4 Dashboard accionable** *(A8, A9, F3.1)*: filas de incidentes deep-linkean al detalle;
  críticos siempre visibles en el panel (ponderar prioridad); pasada de jerarquía (KPIs compactos,
  panel de incidentes dominante). Cierre: click en INC-00040 abre `/{team}/incidents/40`; con ≥1
  crítico abierto, aparece en el panel.
- [x] **R1.5 Mapa y cross-links de flota** *(C-04, C-05, C-06, C-08, C-14)*: clustering/spiderfy
  para co-ubicados; markers enfocables con aria-label real; incidente vinculado → detalle del
  incidente; sección conductor en detalle de activo y viceversa (cuando el dato exista); estilo de
  tiles acorde al tema + skeleton hasta `load`. Cierre: criterios de C-04..C-08 (§3).
- [x] **R1.6 Ciclo de vida editable** *(edición de steps de workflow diferida; ver nota)* *(D-09, D-10, D-11, D-12, D-18)*: workflows editables (o al
  menos eliminables); edición completa de reglas o aviso de inmutabilidad; confirmación en todo
  destructivo (patrón único); quitar política antes de guardar; cada guardado registra versión.
  Cierre: criterios de D-09..D-12/D-18 (§3).
- [x] **R1.7 Páginas de error SAM** *(verificado 2026-06-12: ya cerrado por F0.4 en `bootstrap/app.php` + `errors/error.tsx`, posterior a la auditoría)* *(F0.4, A11, C-12)*: página Inertia de error (403/404/500/503)
  con shell mínimo, español, tema de la app y CTA de regreso, cableada en `bootstrap/app.php`.
  Cierre: `/esta-ruta-no-existe`, `/incidents/999999` y `/admin/tenants` muestran la página SAM en
  el tema correcto, consola limpia.

### Fase 2 — Un solo idioma, un solo producto (Medium)

- [ ] **R2.1 Barrido de localización de datos** *(A10, B8, C-11, D-13/14/15, E5/E6, B15, B9)*:
  mapa de presentación estado/severidad/tipo → español en un solo módulo front (`status-pill` y
  compañía); timeline y notificaciones generadas en español sin slugs visibles; plantillas de email
  (invitación) en español; permisos/roles y enums de selects con etiqueta humana; quitar códigos
  internos (P5/B9, model-id, "0 ms"); geocodificar o quitar lat/long de la bandeja; humanizar
  edades >120 min. Cierre: cero strings en inglés y cero slugs con puntos/underscores visibles en
  las 19 rutas del shell ops con datos seeded.
- [ ] **R2.2 Auditoría legible** *(C-10, C-13)*: labels en español para acción/categoría/actor,
  Resumen con contenido real, sin FQCN visibles, agrupación del ruido de telemetría, paginador con
  aria-labels, tab y rango de fechas validado en URL. Cierre: criterio de C-10/C-13.
- [x] **R2.3 Empty states con voz de producto** *(B12 quedó dentro de R1.2)* *(C-09, F1.2 residual, F1.4, B10, B12)*: analytics
  con CTA o explicación sin jerga; billing con acción de contacto; detalle de conductor colapsa
  secciones vacías y la lista puebla o elimina columnas muertas; explicar el estado "Cancelada" de
  notificaciones (o corregir la agregación). Cierre: todo empty state dice qué significa y qué
  hacer; cero nombres de jobs/clases en UI.
- [ ] **R2.4 Seguridad de cuenta y flujos auth** *(E4, E7, E10, D-21, D-16, D-17)*: mensaje neutro
  en forgot-password; confirm-password sin sacar del shell; limpiar passwords tras error;
  `email:rfc` con TLD en invitaciones; código de rol restringido a slug; modales de edición
  recargan estado del servidor al reabrir. Cierre: repros de E4/E7/D-16/D-17 corregidos.
- [ ] **R2.5 Estado en URL** *(D-19, B16.7, C-13b, C-15)*: tab activo y sort persistidos en query
  param en bandeja/rules/tenant-config/audit/assets. Cierre: F5 conserva tab y sort; un link a un
  tab concreto es compartible.

### Fase 3 — Marca y shell único (heredados vigentes)

- [ ] **R3.1 F1.1**: migrar settings de usuario/equipos al shell ops (con el menú de usuario de
  R1.1 como puerta de entrada); retirar `app-sidebar.tsx`/`app-layout.tsx` del árbol vivo; corregir
  F0.6 mientras tanto.
- [x] **R3.2 F1.5 + F1.6 + F3.6 + E11** *(verificado 2026-06-12: welcome/fuentes/OG ya cerrados post-auditoría; E11 corregido con glifo knockout `var(--background)`)*: welcome con marca SAM, fuentes self-hosted (cero requests
  a googleapis/bunny), favicon + OG, glifo del logo visible en login claro.
- [ ] **R3.3 F3.3 + F2.4 + IDs**: sistema único de chips/badges (3 variantes), placeholder único
  por tipo de celda, formato canónico de referencia de incidente.

### Fase 4 — Pulido y deuda de auditoría (Low + pendientes)

- [ ] **R4.1 A11y fina**: aria-labels de paginadores/kebabs/densidad, `aria-sort`, targets ≥40px en
  login móvil, `aria-describedby` de los diálogos Radix, contraste WCAG AA (F2.1, aún sin medición
  formal).
- [ ] **R4.2 Microcopy**: "1 seleccionados", voseo/tuteo, "· —" colgantes, "últimas 0", subtítulo
  de vista "Sin mapear", contador de caracteres en comentarios, "Aplicar configuración recomendada"
  con preview (D-23), warning de escalación con `[]` (D-22).
- [ ] **R4.3 Auditoría de `/admin/*`** (F4.1): seeder/comando para `global_role=super_admin` en dev
  y repetir este protocolo sobre las 6 páginas admin.
- [ ] **R4.4 Pendientes de re-verificación**: F0.7 con datos de SLA vivo; `/settings/security` con
  permiso de confirm-password; warning maplibre (`null` en expresión de estilo); flash de tema
  claro en la primera carga del mapa (observación no reproducida).

**Orden sugerido de PRs:** R0.1 (horas) → R0.4 + R0.3 (paran las mentiras) → R0.2 (el grande de
móvil) → R1.1 + R1.7 (shell completo) → R1.2/R1.3/R1.4 (ejes de operación) → R2.1 (barrido idioma,
mejor después de que R0.1 active `lang/es`) → resto de Fase 2 → Fase 3 → Fase 4.

---

## 6. Apéndice

### Rutas no auditables y por qué
- `/admin/*`: usuario seeded sin `global_role=super_admin`; una visita a `/admin/tenants` → 403
  default Laravel (documentado en R1.7/F0.4). Pendiente con seeder (R4.3).
- `/settings/security` (contenido): gate `confirm-password` de cuenta compartida; el gate sí quedó
  auditado (E2/E7). Reset de password y registro válido no se completaron (regla de seguridad).

### Errores de consola/red recopilados (todo lo demás: limpio)
- `PUT /serviexpress-jc/settings/roles → 405` tras CADA cambio de rol (D-08).
- 1 error de consola al cargar la 404 autenticada (`/drivers/99999`).
- Warning maplibre recurrente: "Expected value to be of type number, but found null".
- 2 warnings Radix `Missing Description… for DialogContent` (diálogo Resolver y lightbox).
- Tiles `ERR_ABORTED` al zoomear el mapa (cancelaciones benignas).
- 422 esperados durante el stress de forms (sin error JS propio); 2×POST por doble click en
  login/rules/automation (los bugs E3/D-01/D-02).

### Datos creados durante la auditoría (limpieza sugerida)
- **Quedan en BD (inactivos, evidencia):** 2 reglas `AUDIT-test-regla` (duplicado = evidencia
  D-01), 1 regla `AUDIT-cancel-test`, 2 workflows `AUDIT-test-workflow` (desactivados, trigger
  `manual_trigger`, jamás disparados).
- **INC-2026-00005:** 3 comentarios `AUDIT-*` (uno con `<script>` escapado), ciclo
  ACK→Resolver→Reabrir; quedó en "Assigned" (original "In progress", no hay acción para volver) y
  con 3 asignaciones duplicadas a Admin (evidencia B2). **INC-2026-00007:** asignado a Admin
  (evidencia B6). No existe "quitar asignación" en la UI.
- **Restaurado y verificado:** GPS staleness 120, color `#2563eb`, rol Monitorista, temas, drafts
  descartados, 2 invitaciones canceladas, override y rol AUDIT eliminados. 3 emails en Mailpit
  (2 invitación + 1 reset), inocuos.
- **Sin tocar:** "Solicitar media" (llamaría a Samsara), "Aplicar configuración recomendada SAM",
  integraciones, password/email/2FA del usuario.

### Método
Auditoría ejecutada en 5 bloques paralelos con playwright-cli (sesiones desktop 1440×900 y móvil
390×844 por bloque): A dashboard/eventos/búsqueda · B incidentes/notificaciones · C flota/mapa/
conductores/auditoría/analítica · D reglas/automation/integraciones/billing/tenant-config/roles ·
E públicas/errores/shell legacy. Los reportes intermedios y los 129 screenshots fueron artefactos
de sesión (no versionados); los repros de este documento son autosuficientes.
