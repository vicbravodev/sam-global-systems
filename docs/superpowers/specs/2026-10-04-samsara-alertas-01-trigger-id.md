# Spec 1 — Reconocer las alertas de Samsara por `triggerId`

Fecha: 2026-10-04 · Rama: `feat/alertas-samsara-por-trigger-id` · Índice: [00](2026-10-04-samsara-alertas-00-indice.md)

## Problema

Un `AlertIncident` se clasifica con reglas de mapeo que comparan
`data.conditions.0.description == 'Panic Button'`:

- **El texto es frágil.** Es lo que Samsara muestra, no un identificador; el poll
  de respaldo ya identifica el pánico por `triggerTypeId` 1034. Dos criterios para
  el mismo concepto.
- **Sólo mira la condición 0.** Una configuración con varias condiciones (p. ej.
  pánico + tampering) sólo se clasifica por la primera.
- **La unidad y el conductor sólo se resuelven para el pánico**
  (`data.conditions.0.details.panicButton.vehicle.id`). Una alerta de tampering
  llega sin unidad.

## Objetivo

Clasificar y resolver cualquier `AlertIncident` por el `triggerId` de **cualquiera**
de sus condiciones, igual que el poll, y resolver unidad y conductor desde los
`details` de cualquier disparador.

## Diseño

### 1. Condiciones con comodín en las reglas de mapeo

`external_conditions_json` sigue siendo un mapa plano `ruta => valor` (AND). Se
añade un comodín de lista:

- Una ruta con `*` (p. ej. `data.conditions.*.triggerId`) se resuelve con
  `data_get` y se cumple si **algún** elemento es igual al esperado.
- Comparación de escalares por su forma de texto: `1034` (int del payload) y
  `"1034"` (lo que escribe un operador en la UI) son iguales. `true`/`false`/`null`
  se comparan estrictos (`"true"` no es `true`).

La lógica vive en una clase nueva, `App\Domains\Normalization\Support\MappingConditionMatcher`,
que usan `MapExternalEventType` y el probador de reglas (`RuleTestController::testMapping`),
que hoy duplica la comparación. Devuelve la primera ruta que falla (para el log) o
`null`.

### 2. Reglas sembradas por `triggerId`

`NormalizationSeeder::seedSamsaraMappingRules`:

| Condición | Tipo | Prioridad |
|---|---|---|
| `data.conditions.*.triggerId = 1034` | `panic_button` | 20 |
| `data.conditions.*.triggerId = 1045` | `tampering` | 15 |
| `data.conditions.*.description = Camera Obstructed` | `camera_obstructed` | 10 |

- El pánico tiene la prioridad más alta: una configuración con pánico y otra
  condición se clasifica como pánico (lo más grave gana).
- "Camera Obstructed" no tiene `triggerTypeId` en la API pública; se queda por
  descripción, pero mirando **todas** las condiciones.
- Las reglas de pánico y tampering existentes se actualizan en sitio (misma clave
  `provider + external_event_type + mapped_event_type_id`): `updateOrCreate`
  reemplaza condiciones y prioridad. No quedan reglas por `description` de esos dos.
- Una migración de datos aplica el mismo cambio a las reglas ya sembradas en los
  entornos existentes (sólo si existen; idempotente; no toca reglas creadas por
  operadores con otras condiciones).

### 3. Unidad y conductor desde cualquier disparador

`NormalizeRawEvent::resolveAssetId` / `resolveDriverId` añaden, después de las
rutas de la raíz (`asset.id`, `vehicle.id`, `vehicleId` / `driver.id`), la búsqueda
en `data.conditions.*.details.*.vehicle.id` (y `.driver.id`): la primera condición
y el primer detalle con un id no vacío ganan. La ruta usada se registra en el log
con su forma genérica (`data.conditions.*.details.*.vehicle.id`), nunca el id.

`ResolveAlertIncidentIdentity::fingerprint` ya recorre todos los `details` de la
condición 0; pasa a recorrer todas las condiciones con la misma regla.

`buildNormalizedPayload` guarda además `provider_trigger_ids` (lista de enteros de
las condiciones) para que el resto del pipeline no relea el payload crudo.

### 4. Texto de ayuda en la UI de reglas

`mapping-rule-sheet.tsx`: el ejemplo pasa a `data.conditions.*.triggerId = 1034`
(y explica que `*` significa "cualquier condición").

## Logging

Se reutilizan `normalization.type.mapped` / `normalization.type.unmapped` (el
`failed_path` ya existe) y `normalization.asset.resolved` /
`normalization.driver.resolved` (el `asset_path_used` cambia de valor). Sin
códigos nuevos.

## Tests

- `MappingConditionMatcherTest` (unit): comodín con match en la condición 1,
  ninguna condición, lista vacía, tipos (`1034` vs `"1034"`, `true` vs `"true"`),
  rutas sin comodín como hoy.
- `MapExternalEventTypeTest`: pánico en la condición 1 de 2 gana por prioridad;
  tampering solo; payload sin `conditions` → sin regla.
- `NormalizeRawEventTest`: tampering resuelve la unidad desde
  `details.tamperingDetected.vehicle.id`; id de unidad de **otro tenant** en un
  `details` cualquiera se rechaza (fuga).
- `NormalizationSeederTest`: reglas por `triggerId` creadas y re-sembrar no
  duplica; la migración actualiza la regla de `description` existente.
- `RuleTestController`: el probador usa el mismo matcher (comodín funciona).
- Regresión: los fixtures reales de pánico (`database/fixtures/samsara-panic-events.json`)
  siguen dando `panic_button`.

## Fuera de alcance

- Otros `triggerId` (geocercas, velocidad, safety events): specs 2 y 3.
