# 01 · Product Brief — SAM Global Systems

## Qué es SAM

**SAM es un monitorista autónomo con IA para flotas y activos telemáticos.** Es una plataforma SaaS multi-tenant que:

1. **Ingesta** eventos/webhooks desde proveedores externos (Samsara, Motive, etc.) de forma agnóstica.
2. **Normaliza** esos eventos a un formato interno estándar.
3. **Enriquece** cada evento con contexto operativo (activo, conductor, ruta, histórico).
4. **Evalúa con IA** si el evento es real, falso positivo, o requiere seguimiento.
5. **Aplica reglas** de negocio (motor de decisiones) por tenant.
6. **Genera incidentes** accionables con evidencia, timeline, responsables y resolución.
7. **Automatiza** acciones: notificar, escalar, registrar evidencia, ejecutar reglas.
8. **Factura por uso** (Stripe metered billing) por tenant.

### Elevator pitch

> SAM es un SaaS con IA que automatiza el monitoreo de activos telemáticos, actuando como un monitorista autónomo que analiza eventos en tiempo real, filtra falsos positivos y escala o resuelve incidentes críticos sin intervención humana.

## A quién sirve (usuarios)

| Persona | Perfil | Qué hace en SAM |
|---------|--------|-----------------|
| **Operador / monitorista** | Turnos 24/7, alta carga cognitiva, decide en segundos. Usa pantallas grandes, mouse + teclado, a menudo dual screen. | Vive en la **Bandeja de incidentes** y en el **detalle de incidente**. Necesita ver severidad, SLA, evidencia y actuar rápido. |
| **Supervisor / Lead de operación** | Coordina equipo de operadores, revisa desempeño, ajusta reglas. | Revisa colas, reasignaciones, métricas, auditoría. Usa analytics y configuración. |
| **Fleet Manager / COO** | Dueño del negocio de flota. Busca visión agregada, KPIs, costo operativo. | Dashboard ejecutivo, analítica, tendencias, SLA cumplido. |
| **Admin de tenant** | Configura integraciones, usuarios, roles, branding, plan y límites. | Módulo de configuración, usuarios/roles, integraciones, billing. |
| **Conductor (indirecto)** | Sujeto de monitoreo, rara vez entra al sistema. | Aparece como entidad en incidentes y contexto. |

**El usuario principal de la experiencia core es el operador.** El resto usa subsecciones. El diseño debe priorizar operación en tiempo real sobre dashboards bonitos.

## Contexto de uso

- **Ambiente:** escritorio (resoluciones 1440+), a menudo pantalla secundaria con Google Maps/Samsara en vivo.
- **Iluminación:** salas de monitoreo tienden a estar **oscuras**. **Dark mode es el default real**, no un afterthought.
- **Sesiones:** largas (6–8 horas). Evitar saturación cromática; reservar color para señales.
- **Interrupciones:** constantes. La UI debe permitir volver al estado anterior sin perder contexto.
- **Ruido:** eventos llegan en ráfagas. Hay que distinguir rápidamente ruido de señal.

## Diferenciadores que deben leerse en el diseño

1. **SAM no es un panel de telemetría más.** Es un **agente** que ya tomó decisiones. La UI debe mostrar la decisión de la IA con su razonamiento ("por qué esto es un incidente"), no solo datos crudos.
2. **Trazabilidad total.** Cada cambio de estado, asignación, reclasificación y evidencia queda en un timeline. La UI debe hacer obvio ese recorrido.
3. **Multi-fuente agnóstica.** Eventos de distintos proveedores deben mostrarse de forma unificada, pero sin ocultar su origen.
4. **Operable, no ornamental.** Densidad alta, atajos de teclado, menos clics. Nada de hero illustrations en zonas operativas.
5. **Confianza calibrada.** La IA a veces se equivoca. El diseño debe exponer score/confianza y permitir al humano reclasificar sin fricción.

## Tono de voz

- **Español neutro (LATAM)** como idioma principal de UI. Términos técnicos en inglés cuando ya están adoptados por el gremio (webhook, payload, SLA, dashboard).
- **Directo, profesional, no condescendiente.** Los operadores son expertos en su dominio.
- **Sin jerga de marketing.** Nada de "¡Genial!" ni "¡Oops!". Preferir: "Incidente creado", "No se pudo guardar. Reintentar.", "Sin eventos en las últimas 24h."
- **Mensajes de error específicos.** "Webhook rechazado: firma inválida" > "Algo salió mal".
- **Tiempos relativos** ("hace 2 min") con tooltip al timestamp absoluto.

## Identidad visual — punto de partida

El repo ya trae una base **neutral monocromática** (OKLCH grises) de shadcn/ui. Eso es un **lienzo, no la marca final**. El diseño debe proponer:

- Una paleta primaria (marca) sobria y operativa. Evitar azules corporativos saturados tipo "otra plataforma SaaS más". Referencias que funcionan: paneles de control aeroespacial, Bloomberg Terminal moderno, Linear, Vercel, Grafana.
- **Color semántico fuerte y consistente** para severidad/estado (ver `03-design-system.md`). El color es **señal operativa**, no decoración.
- **Tipografía de trabajo**: la fuente actual es `Instrument Sans`. Evaluar si mantener o cambiar a algo con mejor legibilidad en tablas densas (Inter, Geist, IBM Plex Sans, JetBrains Mono para datos).
- **Branding por tenant**: cada tenant puede tener logo y color propio (tabla `tenant_branding` ya existe). El sistema debe aguantar overrides sin romperse.

## No-goals (qué NO queremos)

- Gamificación, badges, emojis decorativos.
- Ilustraciones 3D, glassmorphism, gradientes decorativos.
- Modales apilados que tapan contexto operativo.
- Animaciones largas (>200ms) en el flujo principal. Realtime tiene que sentirse instantáneo.
- Mobile-first. Mobile es un caso secundario; el diseño principal es desktop denso. Mobile queda para supervisores viendo KPIs fuera de oficina.
