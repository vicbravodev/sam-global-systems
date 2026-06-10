# PRODUCT.md — SAM Global Systems

Contexto de producto para sesiones de diseño/UI. Complementa [`CLAUDE.md`](CLAUDE.md) (operativa) y [`DESIGN.md`](DESIGN.md) (sistema visual).

## Registro

**Product register (impeccable).** SAM es una consola de operación de flotas: la UI sirve a la tarea del operador, no al espectáculo. El estándar es "familiaridad ganada" tipo Linear/Stripe — densidad bien usada, jerarquía clara, cero decoración gratuita. La pregunta de cierre de cada pantalla: *¿un usuario fluido en Linear/Stripe confiaría en esta pantalla?*

## Qué es SAM

Plataforma multi-tenant de monitoreo de flotas. Ingiere webhooks/eventos de proveedores (Samsara, etc.), los normaliza, los enriquece con contexto operacional, los evalúa con IA y genera incidentes + automatizaciones. Billing metered local por transferencia bancaria (sin Stripe).

## Usuarios

| Perfil | Contexto | Implicaciones de UI |
|--------|----------|---------------------|
| Operador de flota | Hispanohablante; monitorea la bandeja de incidentes durante turnos largos | **Todo el copy en español neutro.** Tablas densas, `tabular-nums`, estados visibles de un vistazo. |
| Monitor 24/7 | Pantalla encendida toda la noche, sala de control | **Modo dark es el default real.** Contraste cuidado, sin blancos quemados, realtime visible (flash de llegada, pulso SLA). |
| Admin del tenant | Configura reglas, automatizaciones, escalaciones | Builders estructurados (no JSON crudo), validación con mensajes en español, probador de reglas. |
| Super-admin (SAM) | Consola de administración de tenants, impersonación | Misma calidad que el resto; banner de impersonación siempre visible. |

## Idioma

Español hardcodeado (decisión del usuario, sin librería i18n). Backend con `lang/es`; labels de Enums en español; glosario de términos en [`DESIGN.md`](DESIGN.md).

## Principios operativos

1. **Color restrained**: tokens OKLCH de [`resources/css/app.css`](resources/css/app.css) son la única fuente; acento solo para acción primaria/selección/estado. Cero colores Tailwind hardcodeados.
2. **Consistencia es la feature**: un solo patrón de tabla, de header de página, de empty state, de filtro. Si "Guardar" se ve distinto en dos pantallas, una está mal.
3. **Estados completos**: default/hover/focus/active/disabled/loading/error en todo interactivo. Skeletons con la forma del layout final. Empty states que enseñan + CTA.
4. **Motion sobrio**: UI <300ms, `--ease-out`, solo `transform`/`opacity`, exits más rápidos que enters, `prefers-reduced-motion` respetado.
5. **Anti-slop (bans)**: sin side-stripe borders >1px como acento, sin gradient text, sin glassmorphism decorativo, sin cards anidadas, sin modal como primer reflejo (preferir inline/sheet), sin em dashes en copy.
