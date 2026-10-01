# SAM Global Systems

Plataforma multi-tenant de flotas: ingiere eventos de proveedores (Samsara), los normaliza, enriquece, evalúa con IA y genera incidentes, automatizaciones y notificaciones (Twilio). **Tenant = `Team`** (no existe modelo `Tenant`).

**Stack:** Laravel 13 · PHP 8.5 · Inertia v3 · React 19 · Tailwind v4 · PostgreSQL 18 · Valkey (no Redis Cluster) · RustFS (S3) · Soketi · Horizon · PHPUnit 13 (no Pest).

Reglas por zona (se cargan al trabajar ahí): [`app/CLAUDE.md`](app/CLAUDE.md) · [`database/CLAUDE.md`](database/CLAUDE.md) · [`tests/CLAUDE.md`](tests/CLAUDE.md) · [`resources/js/CLAUDE.md`](resources/js/CLAUDE.md). Specs de negocio: `specs/NN-*.md` (arquitectura: `specs/00-MASTER-GUIDE.md`). Si el código contradice un spec o un doc, **manda el código**.

## Comandos

| Qué | Comando |
|---|---|
| Formato PHP | automático: hook `PostToolUse` corre Pint en cada `.php` editado · manual: `vendor/bin/pint --dirty --format agent` — nunca `--test` |
| Tests filtrados | `php artisan test --compact --filter=Nombre` · `php artisan test --compact tests/Feature/Domains/{Dominio}` |
| Suite completa | `php artisan test --compact` |
| Frontend | `npm run types:check && npm run lint:check && npm run format:check` · `npm run build` |
| Análisis estático | `composer analyse` (Larastan nivel 8 + phpstan strict-rules y deprecation-rules, baseline vacío en `phpstan-baseline.neon`) |
| Wayfinder (tras cambiar rutas/controladores) | `php artisan wayfinder:generate --with-form` |
| Gate antes de push | los cinco de arriba (formato, suite, lint/format, types, análisis estático) o `composer ci:check` |
| Dev | `composer run dev` · servicios: `./vendor/bin/sail up -d pgsql valkey rustfs soketi mailpit` |
| Worktree nuevo | skill `worktree-bootstrap` ANTES de cualquier gate (`vendor/`, `.env` y tipos Wayfinder no vienen en el checkout) |

## Mapa

| Ruta | Qué hay |
|---|---|
| `app/Domains/{Dominio}/` | todo el código de negocio (17 dominios), un `ServiceProvider` por dominio en `bootstrap/providers.php` |
| `app/Support/TenantContext.php`, `app/Concerns/BelongsToTenant.php` | el aislamiento por tenant |
| `app/Contracts/` → `app/Infrastructure/` | contratos cross-domain y sus implementaciones |
| `routes/api.php` · `routes/web.php` · `routes/channels.php` · `routes/console.php` | rutas, canales de broadcast, scheduler |
| `config/horizon.php` | colas y supervisores |
| `resources/js/pages` · `resources/js/components/{ui,sam}` | páginas Inertia y componentes |
| `tests/Feature/Domains/{Dominio}` · `tests/Concerns/AssertsTenantIsolation.php` | tests por dominio y helper de fuga |
| `specs/` · `docs/SAM/` · `docs/ROADMAP.md` | specs, docs de producto, roadmap vivo |

## Invariantes de negocio (no negociables)

1. **Aislamiento de tenant.** Una fuga cross-tenant es el peor bug posible. Ninguna feature está terminada sin scope de tenant en tabla, modelo, queries, jobs, endpoints, caché y tests. Checklist completo y los 4 patrones de `TenantContext`: [`app/CLAUDE.md`](app/CLAUDE.md). Ante la duda, filtrar de más.
2. **Uso facturable** sólo vía `App\Domains\Tenancy\Actions\RecordUsageEvent` con `event_key` idempotente. Cobro por **tracto-día**: sólo se sondea, evalúa y cobra lo `monitored` (`monitoring_state`); el tope contratado es suave (se audita y se cobra el extra). Sin planes ni trial (`plans` = plantillas de topes). Detalle en `config/billing.php`, `ResolveBillingTerms`, `AssetDayPricing`.
3. **Cobro por transferencia.** Stripe/Cashier retirados: no iniciar trabajo de Stripe.
4. **Webhooks:** el tenant se resuelve desde `WebhookEndpoint` en DB (nunca del payload) y la firma se valida antes de ingerir.
5. **Toda feature nueva lleva tests y logging, o no está terminada.** Tests: happy path, fallos, bordes y fuga de tenant sobre el camino real ([`tests/CLAUDE.md`](tests/CLAUDE.md)). Logging narrativo vía `App\Support\SystemLog` en cada decisión, degradación y fallo, con su código en `docs/SAM/logging.md` y un test `AssertsSystemLog` (`assertSystemLogged` + `assertNoSensitiveDataLogged`) ([`app/CLAUDE.md`](app/CLAUDE.md)). Sin cualquiera de los dos, no se abre PR.
6. **Pipeline:** emergencias (pánico, colisión, vuelco) abren incidente sin esperar IA ni decisiones. Las categorías de `ai.skip_evaluation_categories` (`safety`, `maintenance`) no se evalúan con IA y, por tanto, no generan decisión ni incidente.

## Convenciones no obvias

- Jobs en colas con nombre por dominio. Supervisores: `high` = ingestion/normalization/decisions/incidents · `medium` = context/ai-evaluation/automation/notifications/billing · `long` = sync · `telematics` · `realtime` = broadcasts · `low` = default/audit/analytics.
- Broadcasts tenant-scoped en `private-accounts.{teamId}`; todo `ShouldBroadcast` usa el trait `QueuesRealtimeBroadcast` + `ShouldRescue` (los del feed de telemática: `ShouldBroadcastNow` + `ShouldRescue`).
- `TenantConfigServiceProvider` bindea TODOS los contratos `TenantConfig`; los dominios consumidores no bindean sus propios `Null...Resolver`.
- `Integrations` rompe dependencias circulares con `Null*` bindeados vía `singletonIf`; los `NullImplementations/` son para tests o contratos sin implementación real.

## Qué NO hacer

- Directorios nuevos en `app/`, cambios en `composer.json`/`package.json`, o docs/README nuevos, sin aprobación.
- Reemplazar `User`, `Team`, `Membership`, `TeamInvitation` (extenderlos).
- Mockear la DB en tests de feature.
- Borrar o debilitar tests existentes.
- Agregar errores a `phpstan-baseline.neon` o usar `@phpstan-ignore` para pasar CI (arreglar la causa; el baseline sólo se achica).

## Git (reglas duras)

- Commits sólo con la identidad del usuario: **sin** `Co-Authored-By`, `--author`, `--trailer` ni banners "Generated with Claude Code". Formato `type: subject en minúsculas` (`feat|fix|chore|refactor|ci|docs|test|perf|style`), un cambio atómico por commit.
- Ramas desde `main` actualizada con prefijo `feat/ fix/ refactor/ chore/ ci/ docs/ test/` (excepción: `claude/...`). Nunca push a `main`: todo entra por PR con CI verde (`Lint & Format` + `PHPUnit`) y la rama al día con `main`.
- Merge (`gh pr merge --merge`) sólo con autorización del usuario (caso a caso o amplia vigente). Tras cada merge: `git -C <checkout-principal> checkout main && git pull --ff-only origin main`. El hook `post-merge` poda solo los worktrees ya mergeados de `.claude/worktrees/`; reporta los que tienen trabajo sin mergear — revísalos.
- Al empezar una tarea, reporta (no borres) trabajo huérfano: worktrees con cambios y ramas sin mergear (`git branch --no-merged main`, también remotas; si su PR está `CLOSED`, suele ser abandono intencional).
- Tras `git push`, esperar CI (`gh pr checks --watch`) y arreglar lo rojo con un commit nuevo.
- Nunca, salvo petición explícita en el turno: `--force`/`--force-with-lease`, `--no-verify`, `--amend` de lo publicado, `reset --hard`, `checkout .`, `clean -fd`, `branch -D`, `rebase -i`, `gh release`, bypass del ruleset.
- Si falla un hook, arreglar la causa y hacer un commit nuevo.

## Al compactar

Conserva siempre la lista de archivos modificados y los comandos de test usados (con su resultado).
