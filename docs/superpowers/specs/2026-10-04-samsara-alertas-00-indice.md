# Safety events y AlertIncidents de Samsara — índice

Fecha: 2026-10-04 · Estado: propuesta aprobada para implementar en 5 PRs

## Por qué

Revisión del modelo mental (2026-10-03). SAM procesa bien el caso que más importa
(pánico y choque), pero tenía tres confusiones de concepto:

1. Un **safety event** es una entidad con ciclo de vida en Samsara
   (`needsReview → reviewed/coached/dismissed`, y su etiqueta puede cambiar), y SAM
   lo trata como una serie de hechos inmutables: cada cambio de estado crea otro
   `normalized_event`.
2. Un **AlertIncident** es "una regla que el cliente configuró en Samsara se
   disparó". SAM lo trata casi como si sólo existiera el pánico: se reconoce por el
   texto de `conditions.0.description` y cualquier otra alerta se escala como
   "posible pánico".
3. El mismo hecho puede llegar por los dos canales (safety event por poll y
   AlertIncident por webhook) sin nada que los una.

Y una fricción operativa: el cliente crea el webhook a mano y copia la Secret Key.
Ese copiar/pegar ya rompió la recepción de pánicos una vez.

## Hechos de partida (dev, 2026-10-03)

- 5,597 safety events en 3.5 días; sólo 2 cambiaron de estado y ninguno tiene más
  de un `normalized_event` (el cliente no revisa en Samsara). El fallo de la
  entidad existe en el código, pero hoy no infla nada.
- 86 AlertIncidents; 81 son pánicos (`triggerId` 1034, `description` "Panic
  Button"), 5 sin `conditions` (pruebas/malformados). Todos con una sola condición.
- El webhook de `AlertIncident` trae `data.conditions[].triggerId` (el
  `triggerTypeId` de la configuración) y `details.<clave>` según el disparador
  (`panicButton`, `harshEvent`, `tamperingDetected`, …).
- La API de Samsara permite `POST /webhooks` (devuelve `secretKey`, scope
  *Write Webhooks*) y `POST/PATCH/DELETE /alerts/configurations` (scope *Write
  Alerts*, acción Webhook = `actionTypeId` 4).

## Specs y orden de implementación

| # | Spec | PR | Depende de |
|---|---|---|---|
| 1 | [Reconocer alertas por `triggerId`](2026-10-04-samsara-alertas-01-trigger-id.md) | `feat/alertas-samsara-por-trigger-id` | — |
| 2 | [Alertas desconocidas sin escalar](2026-10-04-samsara-alertas-02-alertas-desconocidas.md) | `feat/alertas-samsara-desconocidas-sin-escalar` | 1 |
| 3 | [Eco de safety events en alertas](2026-10-04-samsara-alertas-03-eco-safety-events.md) | `feat/alertas-samsara-eco-de-safety-events` | 1, 2 |
| 4 | [Safety event como entidad con estado](2026-10-04-samsara-alertas-04-safety-event-entidad.md) | `feat/safety-event-entidad-con-estado` | — |
| 5 | [Alta automática del webhook](2026-10-04-samsara-alertas-05-alta-automatica-webhook.md) | `feat/alta-automatica-webhook-samsara` | 1 |

El orden difiere de la lista de recomendaciones: el reconocimiento por `triggerId`
(1) es la base de las alertas desconocidas (2), del eco (3) y de la alerta propia
que crea el alta automática (5). La entidad (4) es independiente.

## Decisiones que aplican a los cinco

- **Webhook y poller se quedan juntos.** El webhook es el canal rápido; el poll de
  pánicos (`PollAlertIncidentsJob`) es la red de seguridad y avisa cuando el
  webhook no entrega. Los safety events sólo existen por poll.
- **Pre-producción:** se prefiere el diseño limpio a shims de compatibilidad
  (decisión 2026-09-27). Las reglas de mapeo se migran en sitio.
- Cada PR lleva tests (happy path, fallos, bordes y fuga de tenant sobre el camino
  real), logging narrativo con `SystemLog` y sus códigos en `docs/SAM/logging.md`.
