# Spec 3 — Alertas que son eco de un safety event

Fecha: 2026-10-04 · Rama: `feat/alertas-samsara-eco-de-safety-events` · Índice: [00](2026-10-04-samsara-alertas-00-indice.md) · Depende de: specs 1 y 2

## Problema

Un cliente puede configurar en Samsara alertas que se disparan **por** un safety
event: "A safety event occurred" (5039), "… with a driver assigned" (5033),
"Harsh Event" (1023, obsoleto) o "severely speeding" (5022). Entonces el mismo
hecho llega dos veces:

- como **safety event** por el poll (`/safety-events/stream`, cada 2 min), con
  etiqueta, estado y media;
- como **AlertIncident** por el webhook, en segundos, pero sólo con unidad,
  conductor e instante (los `details` de `harshEvent` no traen la etiqueta).

Hoy el segundo cae como `unmapped`, y con el spec 2 quedaría como "reconocido,
sin clasificar". En ningún caso se relaciona con el primero.

## Objetivo

1. Reconocer estas alertas como **eco** de un safety event: se registran con un
   tipo propio y nunca abren un incidente por sí mismas.
2. Enlazar el eco con su safety event para que en Eventos se vea que es el mismo
   hecho.
3. Usar el eco como señal para **adelantar** el poll de safety events del tenant:
   el webhook llega antes que el poll.

## Diseño

### Tipo y reglas

- Tipo nuevo `provider_safety_alert` ("Alerta de seguridad de Samsara"), categoría
  `safety`, severidad `low`. Al ser `safety`, la IA lo omite
  (`ai.skip_evaluation_categories`) y no genera decisión ni incidente.
- Reglas sembradas (prioridad 5, por debajo de pánico y tampering):
  `data.conditions.*.triggerId` ∈ {1023, 5033, 5039, 5022} → `provider_safety_alert`.
  Una regla por `triggerId` (el matcher es AND de igualdades).
- `ProviderAlertTriggers::SAFETY_ECHO_TRIGGER_IDS` lista esos ids (lo usan el
  seeder y el listener).

¿Por qué no mapear el eco directamente al tipo del safety event (p. ej.
`collision`)? Porque el `AlertIncident` no trae la etiqueta: no sabemos si fue un
frenazo o un choque. El dato bueno llega por el poll. Decisión consciente: **un
choque abre incidente cuando llega el safety event `Crash`**, no antes; el eco
sólo adelanta ese poll.

### Correlación

Listener `CorrelateSafetyAlertEcho` sobre `EventNormalized` (Normalization), que
llama a la acción `CorrelateSafetyAlertEcho`:

- Si el evento es un eco (`provider_safety_alert`): busca un safety event del
  **mismo tenant** y la **misma unidad** con `occurred_at` a ±
  `pipeline.safety_alert_echo.window_seconds` (default 120). Si lo encuentra,
  guarda `echo_of_normalized_event_id` en el `payload_normalized_json` del eco.
- Si el evento es un safety event del feed: busca ecos sin enlazar de la misma
  unidad en la ventana y los enlaza a él (el eco suele llegar primero).
- Si hay varios candidatos, gana el más cercano en el tiempo; un eco se enlaza a
  un solo safety event.
- Sin unidad resuelta no se correlaciona (no hay con qué).

Un safety event se reconoce por su raw event (`deduplication_key` que empieza por
`safety:`), o por `provider_event_key` cuando exista el spec 4.

### Adelantar el poll

Cuando un eco no encuentra su safety event, se despacha
`PollSafetyEventsJob` para la integración activa de Samsara del tenant con un
retraso de `pipeline.safety_alert_echo.poll_delay_seconds` (default 30; Samsara
tarda en publicar el evento en el stream). El job ya es `ShouldBeUnique` por
integración, así que una ráfaga de ecos no multiplica los polls.

### UI

En el detalle del evento (Eventos), si el payload trae
`echo_of_normalized_event_id`, se muestra "Es la misma alerta que el evento #N"
con enlace. Sin cambios en la bandeja de incidentes.

## Logging

- `normalization.safety_echo.correlated` (ok): `input` ids de ambos eventos,
  `calc` diferencia en segundos y dirección (`alert_first` | `event_first`).
- `normalization.safety_echo.unmatched` (skipped): `reason` `no_asset` |
  `no_candidate`, con `window_seconds`.
- `normalization.safety_echo.poll_requested` (ok): `integration_id`,
  `delay_seconds`; `skipped` con `reason: no_active_integration`.

## Tests

- Eco antes que el safety event: queda pendiente, se pide el poll (Bus fake) y al
  normalizar el safety event se enlazan.
- Safety event antes que el eco: el eco se enlaza al llegar.
- Fuera de ventana, otra unidad, sin unidad → sin enlace.
- Dos candidatos → el más cercano.
- El eco no pasa por IA ni abre incidente (camino real hasta `EventNormalized`).
- **Fuga**: un safety event de otro tenant con la misma unidad externa y el mismo
  instante nunca se enlaza; el poll se pide sólo para la integración del tenant
  del eco.
- `assertSystemLogged` + `assertNoSensitiveDataLogged`.

## Fuera de alcance

- Unir pánico y choque de la misma unidad (son tipos distintos de incidente).
