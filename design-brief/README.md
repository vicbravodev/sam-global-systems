# SAM Global Systems — Design Brief para Claude Design

Este paquete contiene todo lo que un diseñador (humano o IA) necesita para proponer un **design system** para SAM sin tener que leer el repo completo. Está pensado para pegarse en Claude Design (u otro agente/diseñador) en una sola conversación.

## Cómo usarlo

1. Abre Claude Design (o el agente que uses).
2. Pega, en este orden, el contenido de:
   1. [`01-product-brief.md`](01-product-brief.md) — qué es SAM, a quién sirve, qué tono debe tener.
   2. [`02-information-architecture.md`](02-information-architecture.md) — mapa de módulos, pantallas clave, objetos y estados.
   3. [`03-design-system.md`](03-design-system.md) — tokens actuales, restricciones técnicas, componentes requeridos, estados transversales.
   4. [`04-prompt-para-claude-design.md`](04-prompt-para-claude-design.md) — el prompt final con lo que quieres que entregue.
3. Adjunta como referencia (opcional) los specs canónicos si el diseñador los pide: [`specs/00-MASTER-GUIDE.md`](../specs/00-MASTER-GUIDE.md) y los módulos relevantes.

## Qué NO debe hacer el diseñador

- Inventar módulos que no estén en el brief.
- Cambiar el stack (Tailwind v4 + shadcn/ui + Inertia + React 19).
- Proponer paletas en HEX/RGB: los tokens viven en `oklch()` (Tailwind v4 + CSS vars).
- Romper el contrato de dark mode (`.dark { ... }` override).
- Ignorar multi-tenant: toda pantalla vive bajo `/{team_slug}/...` y puede cambiar de branding por tenant.

## Qué sí debe entregar

- Tokens (color, tipografía, radio, espaciado, sombra, motion) en formato Tailwind v4 `@theme` + CSS vars, con light/dark.
- Escala **semántica** para severidad operativa (critical / high / medium / low / info) y estados de incidente (open / in-review / escalated / resolved / closed / false-positive / cancelled).
- Biblioteca de componentes de dominio (ver §3 de `03-design-system.md`), con anatomía + estados + ejemplos.
- 5 pantallas "hero" diseñadas a fondo: Dashboard operativo, Bandeja de incidentes, Detalle de incidente, Mapa/lista de activos, Detalle de conductor.
- Guía de voz/tono corta (español neutro + inglés para UI técnica).
- Accesibilidad: WCAG AA mínimo, foco visible, densidad alta sin romper tamaños de hit.

## Archivos

| Archivo | Propósito |
|---------|-----------|
| [`01-product-brief.md`](01-product-brief.md) | Producto, usuarios, tono, diferenciadores |
| [`02-information-architecture.md`](02-information-architecture.md) | Módulos → pantallas → objetos → estados |
| [`03-design-system.md`](03-design-system.md) | Tokens, componentes, restricciones técnicas |
| [`04-prompt-para-claude-design.md`](04-prompt-para-claude-design.md) | Prompt listo para pegar |
