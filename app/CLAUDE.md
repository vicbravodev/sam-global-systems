# Backend (app/)

## Estructura de un dominio

`app/Domains/{Dominio}/{Actions,Data,Enums,Events,Jobs,Listeners,Models,Policies,Queries,Services,Support}` + `{Dominio}ServiceProvider` registrado en `bootstrap/providers.php`. Dominio nuevo: seguir `specs/00-MASTER-GUIDE.md` §9; crear archivos con `php artisan make:* --no-interaction` y moverlos al dominio; cablear `RecordUsageEvent` en cada punto facturable del spec.

Nombres: modelos singular PascalCase, tablas plural snake_case, columnas JSON con sufijo `_json`, actions `VerboSustantivo`, jobs `...Job`, eventos en pasado, sufijo `Broadcast` sólo para desambiguar. Enums con keys TitleCase.

## Aislamiento de tenant

El tenant activo vive en el `Context` de Laravel (`App\Support\TenantContext`): viaja en el payload de cada job y el scope global de `BelongsToTenant` filtra también en colas. `currentTeamId()` lee primero el contexto y después el usuario autenticado. Usa `currentTeam()`/`currentTeamId()`, nunca `auth()->user()->currentTeam`.

| Situación | Patrón |
|---|---|
| Job o listener que entra por un id | Lookup de entrada **sin scope** y justo después `TenantContext::set($model->team_id)` (el worker hace `flush()` del contexto al arrancar cada job). |
| Action, query o servicio que recibe `int $teamId` | `TenantContext::for($teamId, fn () => ...)`. Nunca `set()`: debe devolver el contexto como estaba. |
| Trabajo de plataforma que cruza tenants a propósito (fan-out del scheduler, consola admin, agregados de facturación) | `TenantContext::withoutTenant(fn () => ...)` y dentro `TenantContext::for($row->team_id, ...)` por tenant. |
| Todo lo demás | `Model::query()` a secas. |

Checklist por feature (todo lo aplicable):

1. **Modelo** con `use App\Concerns\BelongsToTenant`. Si es "global o de tenant" (`team_id` nullable) NO lleva el trait y toda consulta usa `where('team_id', $teamId)` con fallback `whereNull('team_id')` (scope `availableToTeam()` donde exista; plantilla: `Automation/Actions/ResolveActionTemplate.php`). Tabla: ver `database/CLAUDE.md`.
2. **Queries fuera de HTTP** (Action, Job, Listener, Command, Query) dentro de un `TenantContext` según la tabla.
3. **`withoutGlobalScopes()`** sólo en: el lookup de entrada por el que un job descubre su tenant, la escritura de ingesta y las filas de plataforma (`team_id` null). En cualquier otro caso, `where('team_id', ...)` explícito en la misma cadena. Uno nuevo sin justificación es error de revisión.
4. **Ids de proveedor** (`external_id`, `provider_id`, ids de Samsara o de webhooks) son únicos platform-wide: verifica siempre que el registro resuelto pertenece al team del evento.
5. **Jobs y eventos** llevan `team_id` explícito en el constructor. Si un job recibe un id de recurso y un `teamId`, valida que concuerdan y aborta si no. Nunca reconstruyas el tenant con `currentTeam()` dentro de un job.
6. **Endpoints** bajo `/{current_team}/...` con `EnsureTeamMembership` + `$this->authorize(...)` y una Policy que compare `team_id` (si el modelo no lleva el trait, la Policy es la única barrera).
7. **Caché, locks, claves KV y nombres de archivo en storage** incluyen el `team_id`.
8. **Test de fuga** sobre el camino real: ver `tests/CLAUDE.md`.

## Estilo PHP

- Llaves siempre, también en cuerpos de una línea. Promoción de propiedades en constructores; sin `__construct()` vacíos.
- Tipos de retorno y de parámetros explícitos; shapes de arrays en PHPDoc. PHPDoc antes que comentarios inline.
- APIs con Eloquent API Resources (salvo que la zona existente no las use). Enlaces con rutas con nombre y `route()`.
- Artisan: `php artisan route:list --path=api`, `php artisan config:show clave`; tinker con comillas simples (`php artisan tinker --execute '...'`) y sólo si no hay test que lo cubra.
- Antes de usar la API de un paquete, confirma su versión (`composer show <paquete>`); no la supongas.

## Logging (narrativo y seguro)

- Todo log pasa por `App\Support\SystemLog` (`ok`/`skipped`/`degraded`/`failed`/`measure`); nunca `Log::`, `logger()` ni `info()` (lo impide `LoggingConventionsTest`).
- **Toda rama de decisión** (return temprano, skip, gate, fallback, dedupe, umbral) y **todo cálculo** registra su código `dominio.etapa.resultado` con `reason` (si no es `ok`), `input` y, en cálculos, `calc` con cada término y umbral — debe poder rehacerse a mano.
- Excepciones como `error: $e` (SafeException), nunca `getMessage()`. Nunca teléfonos, emails, nombres, tokens, secretos, payloads, texto libre ni prompts.
- Para **persistir** un error (columnas `error_message`/`last_error_message`, JSON, `DeliveryResult`) o mandarlo en un evento o respuesta: `SafeErrorMessage::from($e)` (mensaje redactado sólo de excepciones propias de su allowlist; de las ajenas, clase + status/código). Lo vigila `RawExceptionMessageConventionTest`.
- Cada código nuevo: entrada en `docs/SAM/logging.md` y un test con `Tests\Concerns\AssertsSystemLog` (`assertSystemLogged` + `assertNoSensitiveDataLogged`).
