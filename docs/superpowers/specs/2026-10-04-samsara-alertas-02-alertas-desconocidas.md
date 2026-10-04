# Spec 2 — Alertas de Samsara desconocidas: se registran, no se escalan

Fecha: 2026-10-04 · Rama: `feat/alertas-samsara-desconocidas-sin-escalar` · Índice: [00](2026-10-04-samsara-alertas-00-indice.md) · Depende de: spec 1

## Problema

Hoy un `AlertIncident` sin regla de mapeo:

1. Se normaliza como `unmapped` (queda en la vista "Sin mapear" de Eventos). Bien.
2. `AlertOnUnmappedProviderAlert` lo escala con `AlertPipelineFailure::forUnmappedAlert`
   ("alerta sin clasificar, posible pánico") a los responsables del tenant.
3. No pasa por la IA: un `unmapped` sólo despacha `EventUnmapped`, nunca
   `EventNormalized` (corregido al implementar; la primera versión de este spec
   decía lo contrario).

Eso es correcto para un payload que **no podemos leer** (podría ser un pánico
malformado). Pero un cliente con geocercas, velocidad o fallas de motor
configuradas en Samsara recibiría un aviso de "posible pánico" por cada una.
Confunde "no lo conozco" con "puede ser una emergencia".

## Objetivo

Distinguir dos casos de `AlertIncident` sin regla:

| Caso | Cómo se reconoce | Qué hace SAM |
|---|---|---|
| **Ilegible** | ninguna condición trae un `triggerId` entero | Igual que hoy: se registra y se escala como posible pánico |
| **Reconocido, sin clasificar** | al menos una condición trae `triggerId`, y ninguno es de emergencia | Se registra en "Sin mapear" para que alguien cree la regla. **No** se escala |

Un `triggerId` de emergencia sin regla (hoy sólo 1034, si alguien desactivara la
regla) se trata como ilegible: se escala. Fallar hacia el lado seguro.

## Diseño

### Lectura única de los disparadores

Enum nuevo `App\Domains\Normalization\Enums\SamsaraAlertTrigger` (int-backed, con
los `triggerTypeId` que SAM conoce: `PanicButton = 1034`, `TamperingDetected = 1045`
y los del spec 3):

- `fromPayload(array $payload): list<int>` — `triggerId` enteros de
  `data.conditions.*` (acepta enteros y cadenas numéricas; ignora el resto).
- `classify(list<int>): 'unreadable'|'emergency'|'recognized'`.
- `isEmergency()`: sólo `PanicButton`. El poll
  (`PollAlertIncidentsJob::PANIC_BUTTON_TRIGGER_TYPE_ID`) pasa a usar
  `SamsaraAlertTrigger::PanicButton->value`.

### Escalado (`AlertOnUnmappedProviderAlert`)

Tras confirmar que el tipo externo está en `pipeline.unmapped_alert_types`, lee
los disparadores del raw event:

- `recognized` → `SystemLog::skipped('normalization.unmapped_alert.skipped', reason: 'recognized_non_emergency_trigger', calc: ['trigger_ids' => [...]])` y termina.
- `unreadable` / `emergency` → escala como hoy (`normalization.unmapped_alert.escalated`), con `trigger_class` en el `calc`.

### Payload del `unmapped`

El payload normalizado de un `unmapped` (hoy, el crudo tal cual) gana
`external_event_type` y `provider_trigger_ids`, para que la vista "Sin mapear" y
cualquier consumidor no tengan que releer el raw event. Sin evaluación de IA no
hay decisión ni incidente (como hasta hoy); el evento sigue visible en Eventos →
"Sin mapear" y cuenta en el KPI `unmapped` de la página.

### Poll de respaldo

Sin cambios: `IngestAlertIncident` sólo ingiere emergencias o alertas sin regla,
y con el spec 1 las configuraciones que consulta son las de pánico.

## Logging

- `normalization.unmapped_alert.skipped` gana el motivo
  `recognized_non_emergency_trigger` (documentar en `docs/SAM/logging.md`).
- `normalization.unmapped_alert.escalated` gana `calc.trigger_class` y `calc.trigger_ids`.

## Tests

- `SamsaraAlertTriggerTest` (unit): sin `conditions`, `conditions` sin
  `triggerId`, `triggerId` no numérico, mezcla de reconocido + emergencia →
  `emergency`, cadena numérica.
- `UnmappedProviderAlertTest`: geocerca (`triggerId` 5016) sin regla → no escala,
  log `recognized_non_emergency_trigger`; payload sin `conditions` → escala como
  hoy; tipo externo fuera de `unmapped_alert_types` → como hoy.
- La geocerca sin regla no despacha `EventNormalized` (no llega a la IA).
- Fuga de tenant: el escalado de un ilegible avisa sólo a los responsables del
  tenant del raw event (el test existente se extiende con un segundo tenant).
- `assertSystemLogged` + `assertNoSensitiveDataLogged` en los nuevos motivos.

## Fuera de alcance

- Mapear geocercas, velocidad, etc. a tipos propios: lo hace un operador desde la
  pantalla de reglas cuando lo necesite.
