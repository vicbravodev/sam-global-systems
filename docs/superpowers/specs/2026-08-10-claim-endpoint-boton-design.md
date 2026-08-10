# Toma humana de incidentes: endpoint + botón en la bandeja

**Fecha:** 2026-08-10
**Estado:** diseño aprobado, pendiente de implementar
**Cierra:** último gap del ticket de semana 1 (`docs/superpowers/plans/2026-08-09-produccion-semana-1.md`, Task 4)

## Problema

La capa de dominio de la toma humana (*claim*) está completa y probada, pero **no está expuesta**: no hay endpoint HTTP ni botón. Hoy un monitorista solo puede tomar un incidente vía `tinker`. Mientras tanto la supresión de escalado opera únicamente a través del acuse de recibo (ACK), que sí tiene UI.

Lo que ya existe y **no se toca**:

- `ClaimIncident::execute(Incident, User): bool` — bloqueo pesimista (`lockForUpdate`), gana exactamente uno en carrera, guarda cross-tenant (`team_id !== $user->current_team_id`), re-claim del mismo usuario es idempotente.
- `ReleaseIncident::execute(Incident, User): bool` — solo suelta quien tenía la toma; si el incidente nunca fue atendido y no es terminal, re-arma el vigilante SLA (`CheckIncidentAcknowledgementJob`) al `sla_due_at` original.
- `IncidentSuppression::isUnderHumanControl()` — `claimed_by_user_id !== null || acknowledged_at !== null`.
- Columnas `claimed_by_user_id` / `claimed_at` + índice `['team_id','claimed_by_user_id']`.
- 7 tests en `ClaimIncidentTest` + supresión en `HumanControlSuppressionTest`.

## Alcance

Exponer claim/release por HTTP y en la bandeja, con paridad de convenciones respecto al flujo ACK. Se descubrió durante el diseño un requisito que el ticket no anticipaba: **el payload de la bandeja no lleva ningún estado de claim**, así que hay que añadirlo o el botón no puede saber qué pintar.

Fuera de alcance: acción masiva de toma múltiple; devices/telemetría de assets (gaps distintos, ver §Notas).

## Diseño

### 1. Dominio — paridad con `AcknowledgeIncident`

`ClaimIncident` y `ReleaseIncident` hoy no dejan rastro ni avisan a nadie, mientras `AcknowledgeIncident` sí hace ambas cosas. Sin broadcast, dos monitoristas no ven en vivo que el otro ya tomó el incidente — que es exactamente la colisión que la función existe para evitar.

Se añade a ambas actions, dentro de la transacción existente:

- `AppendTimelineEntry` con dos tipos nuevos de `TimelineEntryType`: `Claimed` y `Released`, `actorType: User`, `actorId: $user->id`.
- `broadcast(IncidentUpdatedBroadcast::fromModel($fresh))` tras confirmar la escritura.

El broadcast va **fuera** del `DB::transaction` (después del commit), igual que el dispatch del re-armado en `ReleaseIncident`, para no emitir eventos de una transacción que puede revertirse. El contrato de retorno (`bool`) no cambia: solo se emite cuando la operación devolvió `true`.

### 2. Autorización

Se reutiliza la ability `update` de `IncidentPolicy`, igual que ACK — que ya comprueba `currentTeam()`, coincidencia de `team_id`, no-terminal, y el permiso `incidents.manage`. No se crea ability nueva: claim es una operación de gestión ordinaria y `update` ya expresa exactamente ese conjunto de condiciones.

La guarda cross-tenant de las actions se mantiene: es defensa en profundidad, no redundancia — protege también a los llamadores que no pasan por el controlador.

### 3. Endpoint

Dos rutas web (no API), dentro del grupo `{current_team}` con `['auth','verified',EnsureTeamMembership]`, junto a `incidents.acknowledge`:

```
POST /{current_team}/incidents/{incident}/claim     → incidents.claim
POST /{current_team}/incidents/{incident}/release   → incidents.release
```

Solo web, igual que `acknowledge`: las consume la bandeja Inertia con la cookie de sesión. Sin FormRequest — no llevan cuerpo.

En `IncidentController`, siguiendo la firma establecida:

```php
public function claim(Request $request, Team $current_team, Incident $incident, ClaimIncident $claim): JsonResponse
{
    $this->authorize('update', $incident);

    if (! $claim->execute($incident, $request->user())) {
        return response()->json(
            ['message' => 'Otro monitorista ya tomó este incidente.'],
            Response::HTTP_CONFLICT,
        );
    }

    return response()->json(['data' => $incident->fresh()]);
}
```

**409 Conflict** es la decisión de diseño relevante: el `false` de `ClaimIncident` significa «lo tiene otro», que no es ni un error de permisos (403) ni de validación (422). El front lo traduce a un mensaje específico y refresca la fila para mostrar quién lo tiene. `release` devuelve 409 igual cuando el usuario no era el dueño.

### 4. Estado de claim en el payload

`IncidentInboxPresenter::toRow()` emite hoy 19 claves y ninguna de claim. Se añaden:

- `claimedBy: { id, name, initials } | null` — misma forma que `assignee`, reutilizando `MockAssignee`.
- `claimedAt: string | null` (ISO 8601).
- `claimedByMe: boolean` — derivado en el servidor comparando con el usuario autenticado, para que el front no tenga que conocer el id propio.

Requiere: una relación `claimedBy(): BelongsTo` en `Incident` (hoy no existe), y meter `claimed_by_user_id` en la colección que `IncidentInboxController::index()` pasa a `resolveUsers()`, de modo que el nombre del que tomó se resuelva **en la misma consulta** que ya resuelve los asignados — sin N+1 ni consulta extra.

`toDetail()` hace spread de `toRow()`, así que hereda las tres claves sin cambios.

En `resources/js/types/sam.ts`, `MockIncident` gana los tres campos.

### 5. UI

**Panel de detalle** (`detail-side.tsx`): un botón secundario junto a «Atender (ACK)», en la misma fila de acciones, con el markup idéntico a sus hermanos. Alterna según `claimedByMe`:

- sin tomar → «Tomar» (icono `Hand`)
- tomado por mí → «Soltar» (icono `HandHelping`)
- tomado por otro → deshabilitado, «Tomado por {nombre}»

Las mutaciones pasan por `incident-actions-context.tsx`, que ya centraliza todo: se añaden `claim` y `release` a `IncidentActionsValue` y dos entradas `run(...)` en el `useMemo`. `run()` ya gestiona el estado `pending`, el toast, el 403 y el refresco vía `onMutated` — solo hay que añadir el caso 409 a su manejo de errores para dar el mensaje de colisión.

**Fila de la bandeja** (`incident-row.tsx`): una `<td>` final con el control de toma, más el `<th>` correspondiente en `inbox-table.tsx`. Como el `<tr>` entero tiene `onClick`, el handler llama `e.stopPropagation()`, igual que hace ya la celda del checkbox. Tras la mutación, `router.reload({ only: ['incidents'] })` — el patrón exacto de `assignIncidentToMe()`. La variante móvil `IncidentCard` recibe el mismo control.

Cuando lo tiene otro, la fila muestra las iniciales del dueño en vez de un botón: el objetivo es que el monitorista vea de un vistazo quién tiene qué sin abrir nada.

## Flujo de datos

```
click «Tomar»
  → POST /{team}/incidents/{id}/claim
  → IncidentPolicy::update  (team + no-terminal + incidents.manage)
  → ClaimIncident::execute  (lockForUpdate → guarda cross-tenant → escribe)
      ├─ false → 409 «Otro monitorista ya tomó este incidente» → refresca fila
      └─ true  → AppendTimelineEntry(Claimed) → commit → broadcast(IncidentUpdated)
                 → 200 → toast + onMutated/router.reload
                 → los demás monitoristas lo ven por el canal de broadcast
```

Efecto sobre el vigilante: al quedar `claimed_by_user_id` no nulo, `IncidentSuppression::isUnderHumanControl()` pasa a `true` y el escalado queda suprimido sin tocar nada más. Al soltar, `ReleaseIncident` re-arma el `CheckIncidentAcknowledgementJob` si el incidente nunca fue atendido — comportamiento ya existente y probado.

## Errores

| Caso | Respuesta | UI |
|---|---|---|
| Sin permiso `incidents.manage` / otro tenant / terminal | 403 (policy) | «No tienes permisos para esta acción.» |
| Ya lo tiene otro (claim) | **409** | «Otro monitorista ya tomó este incidente.» + refresco |
| No eres el dueño (release) | **409** | «Este incidente lo tiene otro monitorista.» + refresco |
| Re-claim propio | 200 (idempotente) | sin cambio visible |
| Red caída | — | «Error de red. Vuelve a intentarlo.» |

## Pruebas

Endpoint, en `tests/Feature/Domains/Incidents/IncidentInboxActionsTest.php` (clonando sus convenciones, incluido el helper `memberOf()` que hace `switchTeam` — `current_team_id` por sí solo no basta):

1. claim marca el incidente y devuelve 200
2. claim sobre incidente ya tomado por otro devuelve **409** y no reescribe el dueño
3. release por el dueño lo libera; release por un tercero devuelve 409
4. claim/release son aislados por tenant (clonar `test_actions_are_tenant_isolated`)
5. claim sobre incidente terminal lo prohíbe la policy (clonar `test_escalate_terminal_incident_is_forbidden_by_policy`)

Dominio, ampliando `ClaimIncidentTest`:

6. claim escribe entrada de timeline `Claimed` y emite `IncidentUpdatedBroadcast`
7. release escribe `Released` y emite broadcast
8. una operación fallida (colisión) **no** emite broadcast ni escribe timeline

Presenter: `toRow()` expone `claimedBy`/`claimedAt`/`claimedByMe` y resuelve el nombre sin consulta adicional.

Inertia: la bandeja sigue renderizando `incidents/index` con las claves nuevas presentes.

Se usa `Event::fake([IncidentUpdatedBroadcast::class])` para el broadcast y factories siempre — nunca `Model::create()`.

## Notas

Durante la investigación paralela del fallo de colas aparecieron dos gaps ajenos a este trabajo, registrados aquí para no perderlos:

- `AttachDeviceToAsset` no se invoca desde código de aplicación (solo desde su test): `asset_devices` está vacía para los 253 assets, y por eso la flota entera muestra «· sin dispositivo vinculado».
- Nada escribe nunca `AssetTelemetrySnapshot`: no existe action que lo haga y el panel de telemetría queda permanentemente vacío.
