# 04 · Prompt listo para pegar en Claude Design

Copia **todo el bloque siguiente** y pégalo en Claude Design (o el agente/diseñador que uses). Asume que ya pegaste antes los 3 archivos del brief (`01-product-brief.md`, `02-information-architecture.md`, `03-design-system.md`).

---

```
Eres el diseñador principal del design system de SAM Global Systems. Acabo de darte tres documentos:

1. Product brief — qué es SAM, quién lo usa, tono.
2. Arquitectura de información — módulos, pantallas, objetos, estados.
3. Especificación técnica del DS — restricciones, tokens requeridos, componentes requeridos.

Tu trabajo es proponer un design system coherente que resuelva la operación 24/7 de monitoreo de flotas para operadores humanos asistidos por IA. El contexto principal de uso es desktop denso en dark mode, en salas de control.

Entrega en este orden, en mensajes separados si hace falta:

PASO 1 — Dirección creativa (antes de cualquier pixel)
- 3 direcciones alternativas de lenguaje visual, cada una con:
  - Una frase de posicionamiento ("SAM se ve como X porque...").
  - Paleta primaria + acento en OKLCH, light + dark.
  - Referencia a 2–3 productos del mundo real que comparten la sensación.
  - Por qué esta dirección sirve a un operador en turno de 6 horas.
- Al final recomendame una y explica por qué.

PASO 2 — Tokens completos
Para la dirección elegida, dame el archivo `app.css` completo (Tailwind v4 `@theme` + `:root` + `.dark`) con:
- Todos los tokens base shadcn (reemplazando los grises actuales).
- Tokens semánticos de severidad (critical/high/medium/low/info) con -foreground y -bg.
- Tokens de estado de incidente (open/in-review/escalated/resolved/closed/false-positive/cancelled).
- Tokens de salud de integración (healthy/degraded/broken/unknown).
- Tokens de decisión AI (discard/info/incident/escalate).
- Tokens de confianza AI (low/mid/high).
- Chart 1..8 + chart-positive/negative/neutral.
- Tipografía (elegir stack y justificar), escala con nombres claros.
- Radios, sombras, z-index, motion.
Todo en OKLCH, light y dark, con contrastes verificados (AA mínimo).

PASO 3 — Componentes de dominio
Para cada uno de los componentes de dominio listados en el documento 3, §3.4, entrega:
- Anatomía (ASCII wireframe o descripción textual).
- Estados (default, hover, focus, active, disabled, loading, empty, error, selected, realtime-fresh cuando aplique).
- Reglas de uso (cuándo sí, cuándo no).
- Ejemplo real con datos de SAM (usa incident_type como `panic_emergency`, severidad `critical`, SLA 15min, etc.).

Prioriza en este orden:
1. SeverityBadge, StatusPill, PriorityIndicator, SlaCountdown, RelativeTime.
2. IncidentRow, IncidentTimeline, EvidenceGallery, AssignmentPanel, CommentThread.
3. AssetCard, DriverCard, RiskMeter, IntegrationCard, HealthDot.
4. AiEvaluationCard, ConfidenceBar, DecisionOutcomeBadge, ReasoningDisclosure.
5. KpiTile, TrendChart, UsageMeterCard.
6. RuleBuilder, AutomationCard, WebhookLogRow.

PASO 4 — Pantallas hero
Diseña (mockups detallados, light + dark) las 5 pantallas marcadas en 03-design-system.md §3.5:
1. Dashboard operativo.
2. Bandeja de incidentes (lista densa + split view opcional).
3. Detalle de incidente (3 columnas: timeline / descripción+evidencia+AI / asignación+relacionados+acciones).
4. Activos (toggle lista/mapa).
5. Detalle de conductor.

Para cada una:
- Layout a 1440px y a 1920px.
- Estado con datos realistas y con estado vacío.
- Dark mode como default, light mode como secundario.
- Indicar qué tokens y componentes se usan.

PASO 5 — Estados transversales
Una lámina por estado mostrando el patrón:
- Loading (skeletons por tipo de vista).
- Empty (bandeja vacía, integración no conectada, sin conductores).
- Error recuperable (banner inline).
- Error fatal (página).
- Permiso denegado.
- Desconexión realtime.
- Degradación de proveedor externo.

PASO 6 — Voz y tono
1–2 páginas con:
- Reglas de escritura de UI (español neutro + inglés técnico).
- Do/don't con ejemplos reales de SAM (errores de webhook, cierre de incidente, mensajes vacíos).
- Cómo se habla de la IA (confianza, duda, error). La IA no debe sonar antropomorfizada pero sí transparente sobre su certeza.

PASO 7 — Guía de implementación
Un checklist para el equipo de front-end:
- Orden recomendado de implementación (tokens → primitivos → dominio → pantallas).
- Qué componentes shadcn se reemplazan y cuáles se mantienen.
- Cómo integrar el branding por tenant (override de --primary dentro de `.tenant-{slug}`).
- Cómo integrar realtime sin romper el DS (patrón de highlight, aria-live).
- Cómo medir el sistema (qué es "correcto" vs "se ve bonito").

Restricciones duras (no romper):
- Tailwind v4 con @theme, NO tailwind.config.js.
- Tokens en oklch(), light + dark obligatorio.
- shadcn/ui como base (Button, Input, Dialog, etc. ya existen).
- Inertia v3 + React 19. No SPA routing pesado.
- lucide-react para iconos.
- WCAG AA mínimo. Color nunca como único portador de información.
- Densidad alta por default; soportar densidad compacta/cómoda/relajada vía token.
- Dark mode es el escenario primario.

Si algo no está claro, pregunta antes de decidir. No inventes módulos ni objetos que no están en el brief.
```

---

## Notas para ti (no pegar al diseñador)

- Si el diseñador es un humano, considerá adjuntar capturas del estado actual del app (`resources/js/pages/dashboard.tsx`, `resources/js/components/ui/*`) para que vea el punto de partida.
- Si es un agente tipo Claude Design que genera React directamente, pedile que entregue los componentes en el directorio `resources/js/components/sam/` para no colisionar con los primitivos shadcn en `resources/js/components/ui/`.
- Al validar la propuesta: probá la paleta contra un caso real **ya**: un `IncidentRow` con severidad `critical`, SLA al 95%, sobre dark mode, en una lista de 40 filas. Si eso no "lee" en <1s, la paleta no sirve para operación.
- El branding por tenant es real (tabla `tenant_branding` ya existe). No aceptes una paleta que se rompa cuando el tenant cambia `--primary`.
