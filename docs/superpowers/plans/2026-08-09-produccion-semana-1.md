# Producción Semana 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los gaps que hoy impiden poner SAM en producción con clientes reales: una fuga multi-tenant, un secreto sin cifrar, la imposibilidad de que un humano tome una alerta antes que la IA, la operación a ciegas ante fallos, y un SLA que no se puede vender por cliente.

**Architecture:** Todo el trabajo es aditivo sobre la arquitectura domain-modular existente. No se crean dominios nuevos. Las migraciones son additive-only (`add column` / `create table`), respetando la regla de §8.4 de CLAUDE.md. Los cambios de comportamiento van acompañados de su test de regresión antes de la implementación (TDD estricto).

**Tech Stack:** Laravel 13.24 · PHP 8.5 · PHPUnit 13 · Inertia v3 + React 19 + TypeScript · Tailwind v4 · PostgreSQL 18 · Valkey · Horizon · Pint.

## Global Constraints

- Base: `main` @ `f5866a5`. Suite verde de referencia: **1414 tests / 5357 assertions**.
- Una rama por tarea, nombrada `fix/...`, `feat/...` o `chore/...`; un PR por tarea; merge sólo con autorización explícita del usuario (CLAUDE.md §6.1).
- Commits firmados sólo con la identidad del usuario. **Nunca** `Co-Authored-By`, nunca banners de generación.
- Migraciones **additive-only**: prohibido `dropColumn`, `renameColumn`, `dropTable` y cambios de tipo destructivos.
- Toda tabla tenant-scoped nueva lleva `foreignId('team_id')->constrained()->cascadeOnDelete()` + `index('team_id')`, y su modelo usa `App\Concerns\BelongsToTenant`.
- Tests en **PHPUnit 13**, clases bajo `tests/Feature/Domains/{Dominio}/`. Nunca Pest. Siempre factories, nunca `Model::create()` manual.
- Tras cada cambio PHP: `vendor/bin/pint --dirty --format agent`.
- Gate de cierre por tarea: `php artisan test --compact` verde + `vendor/bin/pint --test` limpio. Si la tarea toca frontend, además `npm run types:check && npm run lint:check && npm run format:check && npm run build`.
- Prohibido tocar `composer.json` / `package.json` sin aprobación explícita.

---

## Alcance: qué entra esta semana y qué no

**Entra (12 tareas, ~5 días).** Los cuatro P0 que bloquean producción, el SLA por cliente que hoy impide vender niveles de servicio diferenciados, tres defectos de comportamiento baratos y la higiene de datos que evita que la base crezca sin control.

**No entra, deliberadamente:**

| Gap | Por qué se queda fuera |
|---|---|
| Conectar los 5 campos muertos del perfil de IA | Conectarlos bien es ~1 día y requiere decidir cómo cada campo altera el prompt y los umbrales. La Tarea 12 los **retira de la interfaz** en 1 hora, que elimina el daño real (un cliente configurando algo que no hace nada). Conectarlos es trabajo de la semana 2. |
| Overrides de reglas y políticas de escalación de Decisions | Misma lógica: configuración guardada que ningún código consume. Se retiran de la UI en la Tarea 12 y se conectan después. |
| Retención de media con personas y rastros GPS | Es cumplimiento, no disponibilidad. Necesita una decisión de negocio sobre plazos legales antes de escribir código. |
| Inmutabilidad de `audit_logs` a nivel de base de datos | El guard de aplicación ya existe. El trigger de PostgreSQL es defensa en profundidad, no un bloqueo de salida. |
| i18n residual y pulido de UX del monitorista | No bloquea operar. |

**Una nota sobre el plazo.** Cinco días alcanzan para las 12 tareas si se ejecutan en orden y sin bloqueos. Lo que no alcanza es para *validarlas en carga real*: recomiendo que la semana 1 termine con un cliente piloto y no con el corte general.

---

## Estructura de archivos

| Archivo | Responsabilidad | Tarea |
|---|---|---|
| `app/Domains/Drivers/Models/DriverAssignment.php` | Añadir el scope de tenant que le falta | 1 |
| `app/Domains/Integrations/Models/WebhookEndpoint.php` | Cifrar `secret` at-rest | 2 |
| `app/Console/Commands/EncryptWebhookSecrets.php` | Migrar secretos existentes a texto cifrado | 2 |
| `app/Support/JobFailureReporter.php` | Punto único de reporte de job fallido | 3 |
| `routes/console.php` | Programar `horizon:snapshot`, purga de dedup y expiración de reportes | 3, 11 |
| `database/migrations/*_add_claim_to_incidents_table.php` | Columnas `claimed_by_user_id` / `claimed_at` | 4 |
| `app/Domains/Incidents/Actions/ClaimIncident.php` | Tomar un incidente con bloqueo pesimista | 4 |
| `app/Domains/Incidents/Actions/ReleaseIncident.php` | Soltar un incidente tomado | 4 |
| `app/Domains/Incidents/Support/IncidentSuppression.php` | Decide si la automatización debe callarse | 5 |
| `database/migrations/*_create_tenant_incident_slas_table.php` | SLA por tenant, tipo y prioridad | 6 |
| `app/Domains/TenantConfig/Models/TenantIncidentSla.php` | Modelo del SLA por tenant | 6 |
| `app/Domains/TenantConfig/Actions/ResolveIncidentSla.php` | Resolver el SLA aplicable con cascada de fallback | 6 |
| `resources/js/pages/tenant-config/slas.tsx` | Pantalla de configuración de SLA | 7 |
| `app/Domains/Notifications/NotificationsServiceProvider.php` | Desregistrar el listener que duplica avisos | 8 |
| `app/Domains/AI/Actions/EvaluateEventMultimodally.php` | Filtrar la evidencia a sólo imágenes | 9 |
| `database/seeders/NormalizationSeeder.php` | Sembrar el tipo de evento `unmapped` | 10 |
| `app/Domains/Ingestion/Jobs/PruneDeduplicationKeysJob.php` | Purgar claves de dedup vencidas | 11 |

---

# DÍA 1 — Cerrar fugas

### Task 1: `DriverAssignment` queda aislado por tenant

**El gap.** `DriverAssignment` tiene columna `team_id` pero **no usa el trait `BelongsToTenant`**. Todos los demás modelos tenant-scoped del repo sí lo usan, y ese trait es el que instala el scope global que filtra por equipo. Sin él, cualquier consulta directa al modelo —`DriverAssignment::all()`, una relación cargada desde otro dominio, un reporte— devuelve asignaciones de **todos los clientes**. Es la definición de fuga cross-tenant: el cliente A puede ver qué conductor maneja qué unidad en el cliente B.

**Cómo lo cerramos.** Añadir el trait. El trait ya resuelve las dos mitades del problema: instala el scope global de lectura y rellena `team_id` automáticamente al crear. El riesgo del cambio es que algún test o código existente dependiera de leer sin scope; el test de aislamiento y la suite completa lo destapan.

**Files:**
- Modify: `app/Domains/Drivers/Models/DriverAssignment.php`
- Test: `tests/Feature/Domains/Drivers/DriverAssignmentTenantIsolationTest.php`

**Interfaces:**
- Consumes: `App\Concerns\BelongsToTenant` (trait existente).
- Produces: `DriverAssignment` con scope global de tenant. Cualquier consulta posterior queda filtrada por `currentTeam()`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Models\DriverAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAssignmentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_from_other_teams_are_not_visible(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        DriverAssignment::factory()->count(2)->create(['team_id' => $teamA->id]);
        DriverAssignment::factory()->count(3)->create(['team_id' => $teamB->id]);

        $user = User::factory()->create(['current_team_id' => $teamA->id]);
        $this->actingAs($user);

        $visible = DriverAssignment::query()->get();

        $this->assertCount(2, $visible);
        $this->assertTrue($visible->every(fn ($a) => $a->team_id === $teamA->id));
    }

    public function test_created_assignment_inherits_current_team(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $this->actingAs($user);

        $assignment = DriverAssignment::factory()->create(['team_id' => null]);

        $this->assertSame($team->id, $assignment->fresh()->team_id);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=DriverAssignmentTenantIsolationTest`
Expected: FAIL — el primer test verá 5 registros en vez de 2.

- [ ] **Step 3: Añadir el trait**

En `app/Domains/Drivers/Models/DriverAssignment.php`, junto a los demás `use` de la clase:

```php
use App\Concerns\BelongsToTenant;

class DriverAssignment extends Model
{
    use BelongsToTenant;
    // ... traits y cuerpo existentes sin tocar
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=DriverAssignmentTenantIsolationTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Correr la suite completa para detectar consultas que dependían de leer sin scope**

Run: `php artisan test --compact`
Expected: 1414+ passed. Si algún test falla porque esperaba ver asignaciones de otro equipo, **ese test estaba documentando la fuga**: corregirlo para que use el equipo correcto, nunca quitando el trait.

- [ ] **Step 6: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Drivers/Models/DriverAssignment.php tests/Feature/Domains/Drivers/DriverAssignmentTenantIsolationTest.php
git commit -m "fix(drivers): DriverAssignment aisla por tenant con BelongsToTenant"
```

---

### Task 2: El secreto del webhook se guarda cifrado

**El gap.** `WebhookEndpoint` guarda en `secret` la clave con la que se valida la firma HMAC de cada webhook entrante. Su método `casts()` sólo declara `'last_received_at' => 'datetime'`: **el secreto está en texto plano en la base de datos**. Quien tenga lectura de la base —un respaldo, un volcado, un operador de infraestructura— puede firmar webhooks falsos y meter eventos arbitrarios en el tenant, incluidos pánicos inventados.

**Cómo lo cerramos.** Añadir el cast `encrypted`, que cifra al guardar y descifra al leer de forma transparente usando `APP_KEY`. Como ya existen filas con secretos en claro, hace falta un comando idempotente que las cifre una sola vez. El comando detecta si un valor ya está cifrado intentando descifrarlo.

**Files:**
- Modify: `app/Domains/Integrations/Models/WebhookEndpoint.php:57-62`
- Create: `app/Console/Commands/EncryptWebhookSecrets.php`
- Test: `tests/Feature/Domains/Integrations/WebhookSecretEncryptionTest.php`

**Interfaces:**
- Consumes: `WebhookEndpoint::$secret` (string en claro desde el punto de vista del código PHP; el cifrado es transparente).
- Produces: comando `webhooks:encrypt-secrets` — idempotente, seguro de correr varias veces.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_encrypted_at_rest(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'mi-secreto-real']);

        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertNotSame('mi-secreto-real', $raw, 'El secreto quedó en texto plano');
        $this->assertSame('mi-secreto-real', $endpoint->fresh()->secret, 'El descifrado transparente falló');
    }

    public function test_encrypt_command_is_idempotent(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'abc123']);

        $this->artisan('webhooks:encrypt-secrets')->assertSuccessful();
        $this->artisan('webhooks:encrypt-secrets')->assertSuccessful();

        $this->assertSame('abc123', $endpoint->fresh()->secret);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=WebhookSecretEncryptionTest`
Expected: FAIL — el valor crudo es igual al secreto, y el comando no existe.

- [ ] **Step 3: Añadir el cast**

En `app/Domains/Integrations/Models/WebhookEndpoint.php`, dentro de `casts()`:

```php
    protected function casts(): array
    {
        return [
            'last_received_at' => 'datetime',
            'secret' => 'encrypted',
        ];
    }
```

- [ ] **Step 4: Crear el comando de migración de datos**

Create `app/Console/Commands/EncryptWebhookSecrets.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptWebhookSecrets extends Command
{
    protected $signature = 'webhooks:encrypt-secrets';

    protected $description = 'Cifra at-rest los secretos de webhook que sigan en texto plano';

    public function handle(): int
    {
        $encrypted = 0;

        DB::table('webhook_endpoints')
            ->whereNotNull('secret')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$encrypted) {
                foreach ($rows as $row) {
                    if ($this->alreadyEncrypted($row->secret)) {
                        continue;
                    }

                    DB::table('webhook_endpoints')
                        ->where('id', $row->id)
                        ->update(['secret' => Crypt::encryptString($row->secret)]);

                    $encrypted++;
                }
            });

        $this->info("Secretos cifrados: {$encrypted}");

        return self::SUCCESS;
    }

    private function alreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=WebhookSecretEncryptionTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Correr los tests de webhook para confirmar que la firma sigue validando**

Run: `php artisan test --compact tests/Feature/Domains/Integrations`
Expected: PASS. La validación de firma lee `$endpoint->secret`, que ahora se descifra solo.

- [ ] **Step 7: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Integrations/Models/WebhookEndpoint.php app/Console/Commands/EncryptWebhookSecrets.php tests/Feature/Domains/Integrations/WebhookSecretEncryptionTest.php
git commit -m "fix(integrations): cifra at-rest el secreto de webhook y migra los existentes"
```

> **Nota de despliegue:** `php artisan webhooks:encrypt-secrets` debe correrse **una vez** tras desplegar, antes de que llegue el siguiente webhook. Añadirlo al runbook de la Tarea 3.

---

### Task 3: Los fallos dejan de ser invisibles

**El gap.** Hay 24 métodos `failed()` en jobs del repo y **sólo uno** reporta a algo: el resto escribe una advertencia en log y muere ahí. No hay servicio de errores conectado, y `horizon:snapshot` **no está programado** — sin él las métricas de cola de Horizon están vacías, así que ni siquiera se ve una cola atascada. Hoy, si el job que crea incidentes falla, nadie se entera hasta que un cliente pregunta por qué su pánico no generó nada.

**Cómo lo cerramos.** Un punto único de reporte que eleva el fallo a nivel `error` (lo que sí cruza el umbral de cualquier canal de alerta que se conecte después) e incluye el contexto mínimo para diagnosticar: job, cola, tenant, excepción. Más `horizon:snapshot` cada 5 minutos para que las métricas existan.

**Files:**
- Create: `app/Support/JobFailureReporter.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Support/JobFailureReporterTest.php`

**Interfaces:**
- Produces: `JobFailureReporter::report(string $jobClass, \Throwable $e, array $context = []): void` — para llamar desde cualquier `failed()`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Support;

use App\Support\JobFailureReporter;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class JobFailureReporterTest extends TestCase
{
    public function test_reports_at_error_level_with_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Job failed')
                    && $context['job'] === 'App\\Jobs\\Fake'
                    && $context['team_id'] === 7
                    && $context['exception'] === 'RuntimeException'
                    && str_contains($context['message'], 'boom');
            });

        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('boom'), ['team_id' => 7]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=JobFailureReporterTest`
Expected: FAIL con "Class App\Support\JobFailureReporter not found".

- [ ] **Step 3: Crear el reporter**

Create `app/Support/JobFailureReporter.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class JobFailureReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(string $jobClass, Throwable $e, array $context = []): void
    {
        Log::error('Job failed: '.$jobClass, array_merge([
            'job' => $jobClass,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ], $context));
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=JobFailureReporterTest`
Expected: PASS.

- [ ] **Step 5: Cablear el reporter en los jobs de las colas críticas**

En cada job que ya tiene `failed()` bajo `app/Domains/Ingestion/Jobs/`, `app/Domains/Incidents/Jobs/`, `app/Domains/Notifications/Jobs/` y `app/Domains/Decisions/Jobs/`, sustituir el `Log::warning(...)` existente por:

```php
    public function failed(\Throwable $e): void
    {
        \App\Support\JobFailureReporter::report(static::class, $e, [
            'team_id' => $this->teamId ?? null,
        ]);
    }
```

Si el job no expone `$this->teamId`, omitir esa clave del arreglo en vez de inventar una propiedad.

- [ ] **Step 6: Programar el snapshot de Horizon**

En `routes/console.php`, junto a las demás llamadas `Schedule::`:

```php
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
```

- [ ] **Step 7: Verificar que la tarea quedó registrada**

Run: `php artisan schedule:list`
Expected: aparece `horizon:snapshot` con cadencia de 5 minutos.

- [ ] **Step 8: Suite completa, formatear y commitear**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Support/JobFailureReporter.php tests/Feature/Support/JobFailureReporterTest.php routes/console.php app/Domains
git commit -m "feat(observabilidad): reporte unificado de jobs fallidos y snapshot de Horizon"
```

---

# DÍA 2–3 — Toma humana

### Task 4: Un operador puede tomar un incidente, y sólo uno gana

**El gap.** El requisito central del producto es *"el humano SIEMPRE puede tomar la alerta y terminarla antes que la IA"*. Hoy no se cumple: la tabla `incidents` no tiene ninguna columna de propiedad —`grep claimed_by` no devuelve nada en todo el repo— y en ninguna parte se usa `lockForUpdate`. Dos operadores pueden abrir el mismo incidente y ambos creer que lo están atendiendo, y ninguno tiene forma de señalar al sistema que ya lo tomó.

**Cómo lo cerramos.** Dos columnas (`claimed_by_user_id`, `claimed_at`) y una acción que las escribe dentro de una transacción con bloqueo pesimista. El bloqueo es lo que hace que en una carrera entre dos operadores gane exactamente uno: el segundo lee la fila ya reclamada y recibe una negativa clara, no un pisotón silencioso.

**Files:**
- Create: `database/migrations/2026_08_09_100000_add_claim_to_incidents_table.php`
- Modify: `app/Domains/Incidents/Models/Incident.php`
- Create: `app/Domains/Incidents/Actions/ClaimIncident.php`
- Create: `app/Domains/Incidents/Actions/ReleaseIncident.php`
- Test: `tests/Feature/Domains/Incidents/ClaimIncidentTest.php`

**Interfaces:**
- Produces: `ClaimIncident::execute(Incident $incident, User $user): bool` — `true` si este usuario se quedó con el incidente, `false` si ya lo tenía otro.
- Produces: `ReleaseIncident::execute(Incident $incident, User $user): bool` — `true` si lo soltó; `false` si no era suyo.
- Produces: columnas `incidents.claimed_by_user_id` (FK nullable a `users`) y `incidents.claimed_at` (timestamp nullable).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ClaimIncident;
use App\Domains\Incidents\Actions\ReleaseIncident;
use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_claimer_wins_and_second_is_rejected(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);
        $beto = User::factory()->create(['current_team_id' => $team->id]);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertFalse($claim->execute($incident->fresh(), $beto));

        $this->assertSame($ana->id, $incident->fresh()->claimed_by_user_id);
        $this->assertNotNull($incident->fresh()->claimed_at);
    }

    public function test_reclaiming_by_the_same_user_is_allowed(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertTrue($claim->execute($incident->fresh(), $ana));
    }

    public function test_only_the_owner_can_release(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);
        $beto = User::factory()->create(['current_team_id' => $team->id]);

        app(ClaimIncident::class)->execute($incident, $ana);
        $release = app(ReleaseIncident::class);

        $this->assertFalse($release->execute($incident->fresh(), $beto));
        $this->assertTrue($release->execute($incident->fresh(), $ana));
        $this->assertNull($incident->fresh()->claimed_by_user_id);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=ClaimIncidentTest`
Expected: FAIL — no existen ni las columnas ni las clases.

- [ ] **Step 3: Crear la migración**

Create `database/migrations/2026_08_09_100000_add_claim_to_incidents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('claimed_by_user_id')->nullable()->after('acknowledged_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by_user_id');
            $table->index(['team_id', 'claimed_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'claimed_by_user_id']);
            $table->dropConstrainedForeignId('claimed_by_user_id');
        });
    }
};
```

- [ ] **Step 4: Declarar el cast en el modelo**

En `app/Domains/Incidents/Models/Incident.php`, dentro del arreglo que devuelve `casts()`, añadir:

```php
            'claimed_at' => 'datetime',
```

Y añadir `'claimed_by_user_id'` y `'claimed_at'` al `$fillable` si el modelo declara uno explícito.

- [ ] **Step 5: Crear la acción de reclamo**

Create `app/Domains/Incidents/Actions/ClaimIncident.php`:

```php
<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimIncident
{
    /**
     * Toma el incidente para este usuario. Devuelve false si ya lo tenía otro.
     * El bloqueo pesimista garantiza que en una carrera gane exactamente uno.
     */
    public function execute(Incident $incident, User $user): bool
    {
        return DB::transaction(function () use ($incident, $user) {
            $locked = Incident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->claimed_by_user_id !== null && $locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ])->save();

            return true;
        });
    }
}
```

- [ ] **Step 6: Crear la acción de liberación**

Create `app/Domains/Incidents/Actions/ReleaseIncident.php`:

```php
<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReleaseIncident
{
    /**
     * Suelta el incidente. Sólo lo consigue quien lo tenía tomado.
     */
    public function execute(Incident $incident, User $user): bool
    {
        return DB::transaction(function () use ($incident, $user) {
            $locked = Incident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ])->save();

            return true;
        });
    }
}
```

- [ ] **Step 7: Migrar y correr el test**

Run: `php artisan migrate && php artisan test --compact --filter=ClaimIncidentTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Domains/Incidents tests/Feature/Domains/Incidents/ClaimIncidentTest.php
git commit -m "feat(incidents): toma humana con bloqueo pesimista (claim/release)"
```

---

### Task 5: Tomar o reconocer un incidente silencia a la máquina

**El gap.** Las columnas de la Tarea 4 no sirven de nada si la automatización las ignora. Hoy `AcknowledgeIncident` marca `acknowledged_at` y **nada más**: la cadena de escalación sigue corriendo, la verificación por voz sigue llamando y los workflows de automatización siguen disparando. Un operador que ya está atendiendo el caso sigue recibiendo llamadas del sistema, y el cliente ve a SAM insistiendo sobre algo que ya está resuelto.

**Cómo lo cerramos.** Un predicado único —¿este incidente está bajo control humano?— consultado por los tres consumidores antes de actuar. Un incidente está bajo control humano si alguien lo tomó o si alguien acusó recibo.

**Files:**
- Create: `app/Domains/Incidents/Support/IncidentSuppression.php`
- Modify: `app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php`
- Modify: `app/Domains/Incidents/Jobs/PlaceVerificationCallJob.php`
- Modify: `app/Domains/Automation/Listeners/TriggerAutomationOnIncidentEscalated.php`
- Test: `tests/Feature/Domains/Incidents/HumanControlSuppressionTest.php`

**Interfaces:**
- Consumes: `Incident::$claimed_by_user_id`, `Incident::$acknowledged_at` (Tarea 4 y esquema existente).
- Produces: `IncidentSuppression::isUnderHumanControl(Incident $incident): bool`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HumanControlSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_incident_is_under_human_control(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_acknowledged_incident_is_under_human_control(): void
    {
        $incident = Incident::factory()->create(['acknowledged_at' => now()]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_untouched_incident_is_not_under_human_control(): void
    {
        $incident = Incident::factory()->create([
            'claimed_by_user_id' => null,
            'claimed_at' => null,
            'acknowledged_at' => null,
        ]);

        $this->assertFalse(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_escalation_watchdog_stops_when_incident_is_claimed(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'sla_due_at' => now()->subMinutes(5),
            'acknowledged_at' => null,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        (new CheckIncidentAcknowledgementJob($incident->id, 1, 1))->handle();

        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => 'sla_breached',
        ]);
    }
}
```

> Si el constructor de `CheckIncidentAcknowledgementJob` no acepta exactamente `(int $incidentId, int $level, int $attempt)`, ajustar la llamada del último test a la firma real que tenga el job; el resto del test no cambia.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=HumanControlSuppressionTest`
Expected: FAIL — no existe `IncidentSuppression`.

- [ ] **Step 3: Crear el predicado**

Create `app/Domains/Incidents/Support/IncidentSuppression.php`:

```php
<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;

final class IncidentSuppression
{
    /**
     * Un incidente está bajo control humano cuando alguien lo tomó o
     * acusó recibo. La automatización debe callarse en ese caso: el
     * operador ya está encima y las llamadas o escalaciones sólo estorban.
     */
    public static function isUnderHumanControl(Incident $incident): bool
    {
        return $incident->claimed_by_user_id !== null
            || $incident->acknowledged_at !== null;
    }
}
```

- [ ] **Step 4: Consultar el predicado en el vigilante de SLA**

En `app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php`, en `handle()`, inmediatamente después de cargar el incidente y antes de cualquier comprobación de vencimiento:

```php
        if (\App\Domains\Incidents\Support\IncidentSuppression::isUnderHumanControl($incident)) {
            return;
        }
```

- [ ] **Step 5: Consultar el predicado antes de llamar por voz**

En `app/Domains/Incidents/Jobs/PlaceVerificationCallJob.php`, en `handle()`, tras cargar el incidente y antes de colocar la llamada, añadir el mismo bloque de guarda del paso anterior.

- [ ] **Step 6: Consultar el predicado antes de disparar automatización por escalación**

En `app/Domains/Automation/Listeners/TriggerAutomationOnIncidentEscalated.php`, en `handle()`, tras obtener el incidente del evento, añadir el mismo bloque de guarda.

- [ ] **Step 7: Correr el test y la suite de incidentes**

Run: `php artisan test --compact --filter=HumanControlSuppressionTest && php artisan test --compact tests/Feature/Domains/Incidents`
Expected: PASS. Si algún test existente asumía que la escalación corre sobre un incidente ya reconocido, corregir la fixture para dejar `acknowledged_at` en `null`.

- [ ] **Step 8: Suite completa, formatear y commitear**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Domains/Incidents app/Domains/Automation tests/Feature/Domains/Incidents/HumanControlSuppressionTest.php
git commit -m "feat(incidents): la toma o el acuse de recibo silencian escalacion, voz y automatizacion"
```

---

# DÍA 3–4 — SLA vendible

### Task 6: SLA configurable por cliente

**El gap.** El vencimiento de un incidente sale de `incident_priorities.sla_seconds`. Esa tabla **no tiene columna `team_id`** —lo verifiqué: `grep team_id` sobre su migración no devuelve nada— y no existe ninguna pantalla ni endpoint para editarla. Los cuatro valores (crítica 5 min, alta 30, media 60, baja sin SLA) viven en un seeder y son **iguales para todos los clientes**. Comercialmente eso significa que no puedes vender niveles de servicio diferenciados ni cumplir un contrato específico. Operativamente, un incidente de prioridad baja **nunca arma reloj**: no hay vigilancia de ningún tipo.

**Cómo lo cerramos.** Una tabla de overrides por tenant y prioridad, con una cascada de resolución explícita: si el cliente definió un valor, manda; si no, el del catálogo global; si tampoco, sin SLA. El catálogo global sigue existiendo como valor de fábrica, así que ningún cliente actual cambia de comportamiento al desplegar.

**Files:**
- Create: `database/migrations/2026_08_09_110000_create_tenant_incident_slas_table.php`
- Create: `app/Domains/TenantConfig/Models/TenantIncidentSla.php`
- Create: `database/factories/Domains/TenantConfig/TenantIncidentSlaFactory.php`
- Create: `app/Domains/TenantConfig/Actions/ResolveIncidentSla.php`
- Modify: el punto donde se calcula `sla_due_at` al crear el incidente (`app/Domains/Incidents/Jobs/CreateIncidentJob.php`)
- Test: `tests/Feature/Domains/TenantConfig/ResolveIncidentSlaTest.php`

**Interfaces:**
- Produces: `ResolveIncidentSla::execute(int $teamId, int $incidentPriorityId): ?int` — segundos de SLA, o `null` si no aplica vigilancia.
- Produces: tabla `tenant_incident_slas` con `unique(['team_id', 'incident_priority_id'])`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Actions\ResolveIncidentSla;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveIncidentSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_override_wins_over_global_catalog(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        TenantIncidentSla::factory()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 180,
        ]);

        $this->assertSame(180, app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_falls_back_to_global_catalog(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        $this->assertSame(1800, app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_returns_null_when_neither_defines_an_sla(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => null]);

        $this->assertNull(app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_override_of_one_tenant_does_not_leak_to_another(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        TenantIncidentSla::factory()->create([
            'team_id' => $teamA->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 120,
        ]);

        $resolver = app(ResolveIncidentSla::class);

        $this->assertSame(120, $resolver->execute($teamA->id, $priority->id));
        $this->assertSame(1800, $resolver->execute($teamB->id, $priority->id));
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=ResolveIncidentSlaTest`
Expected: FAIL — no existen la tabla, el modelo ni la acción.

- [ ] **Step 3: Crear la migración**

Create `database/migrations/2026_08_09_110000_create_tenant_incident_slas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_incident_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_priority_id')->constrained('incident_priorities')->cascadeOnDelete();
            $table->unsignedInteger('sla_seconds')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'incident_priority_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_incident_slas');
    }
};
```

- [ ] **Step 4: Crear el modelo**

Create `app/Domains/TenantConfig/Models/TenantIncidentSla.php`:

```php
<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\TenantConfig\TenantIncidentSlaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantIncidentSla extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'incident_priority_id',
        'sla_seconds',
    ];

    protected function casts(): array
    {
        return [
            'sla_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): TenantIncidentSlaFactory
    {
        return TenantIncidentSlaFactory::new();
    }
}
```

- [ ] **Step 5: Crear la factory**

Create `database/factories/Domains/TenantConfig/TenantIncidentSlaFactory.php`:

```php
<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantIncidentSlaFactory extends Factory
{
    protected $model = TenantIncidentSla::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'incident_priority_id' => IncidentPriority::factory(),
            'sla_seconds' => 900,
        ];
    }
}
```

- [ ] **Step 6: Crear el resolver**

Create `app/Domains/TenantConfig/Actions/ResolveIncidentSla.php`:

```php
<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;

class ResolveIncidentSla
{
    /**
     * Cascada: override del tenant → catálogo global → sin SLA.
     * Devuelve null sólo cuando ninguno de los dos define vigilancia.
     */
    public function execute(int $teamId, int $incidentPriorityId): ?int
    {
        $override = TenantIncidentSla::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('incident_priority_id', $incidentPriorityId)
            ->first();

        if ($override !== null && $override->sla_seconds !== null) {
            return $override->sla_seconds;
        }

        return IncidentPriority::query()
            ->whereKey($incidentPriorityId)
            ->value('sla_seconds');
    }
}
```

- [ ] **Step 7: Migrar y correr el test**

Run: `php artisan migrate && php artisan test --compact --filter=ResolveIncidentSlaTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Consumir el resolver al crear el incidente**

En `app/Domains/Incidents/Jobs/CreateIncidentJob.php`, localizar la línea donde hoy se calcula `sla_due_at` a partir de `$priority->sla_seconds` y sustituir esa lectura directa por:

```php
        $slaSeconds = app(\App\Domains\TenantConfig\Actions\ResolveIncidentSla::class)
            ->execute($teamId, $priority->id);

        $slaDueAt = $slaSeconds !== null ? now()->addSeconds($slaSeconds) : null;
```

Dejar intacto el resto del flujo: si `$slaDueAt` es `null` no se agenda vigilante, que es el comportamiento actual para prioridad baja.

- [ ] **Step 9: Suite completa, formatear y commitear**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add database/migrations database/factories app/Domains/TenantConfig app/Domains/Incidents tests/Feature/Domains/TenantConfig/ResolveIncidentSlaTest.php
git commit -m "feat(tenant-config): SLA de incidentes configurable por tenant con fallback al catalogo"
```

---

### Task 7: Pantalla de SLA en la configuración del cliente

**El gap.** El resolver de la Tarea 6 no sirve de nada si nadie puede escribir los overrides. Sin pantalla, cada cliente con SLA propio requiere que alguien edite la base de datos a mano — que es exactamente el trabajo manual que hace insostenible sumar clientes.

**Cómo lo cerramos.** Una pantalla dentro de la configuración del tenant con una fila por prioridad, mostrando el valor de fábrica y permitiendo sobrescribirlo. Reutiliza las primitivas `Field` y `FormCard` que ya entraron en `main` con el PR #92.

**Files:**
- Create: `app/Http/Controllers/TenantConfig/IncidentSlaController.php`
- Create: `resources/js/pages/tenant-config/slas.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/TenantConfig/IncidentSlaControllerTest.php`

**Interfaces:**
- Consumes: `ResolveIncidentSla` (Tarea 6), `TenantIncidentSla` (Tarea 6).
- Produces: rutas `GET tenant-config/slas` (nombre `tenant-config.slas.index`) y `PUT tenant-config/slas` (nombre `tenant-config.slas.update`).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Http\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IncidentSlaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_priorities_with_effective_values(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        IncidentPriority::factory()->create(['code' => 'critical', 'sla_seconds' => 300]);

        $this->actingAs($user)
            ->get(route('tenant-config.slas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tenant-config/slas')
                ->has('priorities', 1)
            );
    }

    public function test_update_persists_override_for_current_team_only(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        $this->actingAs($user)
            ->put(route('tenant-config.slas.update'), [
                'slas' => [
                    ['incident_priority_id' => $priority->id, 'sla_seconds' => 240],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_incident_slas', [
            'team_id' => $team->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 240,
        ]);
        $this->assertDatabaseMissing('tenant_incident_slas', ['team_id' => $other->id]);
    }

    public function test_guests_are_rejected(): void
    {
        $this->get(route('tenant-config.slas.index'))->assertRedirect();
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=IncidentSlaControllerTest`
Expected: FAIL — la ruta no existe.

- [ ] **Step 3: Crear el controlador**

Create `app/Http/Controllers/TenantConfig/IncidentSlaController.php`:

```php
<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncidentSlaController extends Controller
{
    public function index(): Response
    {
        $teamId = currentTeam()->id;

        $overrides = TenantIncidentSla::query()
            ->where('team_id', $teamId)
            ->pluck('sla_seconds', 'incident_priority_id');

        $priorities = IncidentPriority::query()
            ->orderBy('level')
            ->get()
            ->map(fn (IncidentPriority $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'default_sla_seconds' => $p->sla_seconds,
                'sla_seconds' => $overrides[$p->id] ?? null,
            ]);

        return Inertia::render('tenant-config/slas', [
            'priorities' => $priorities,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slas' => ['required', 'array'],
            'slas.*.incident_priority_id' => ['required', 'integer', 'exists:incident_priorities,id'],
            'slas.*.sla_seconds' => ['nullable', 'integer', 'min:30', 'max:86400'],
        ]);

        $teamId = currentTeam()->id;

        foreach ($data['slas'] as $row) {
            TenantIncidentSla::query()->updateOrCreate(
                [
                    'team_id' => $teamId,
                    'incident_priority_id' => $row['incident_priority_id'],
                ],
                ['sla_seconds' => $row['sla_seconds']],
            );
        }

        return back()->with('status', 'Tiempos de respuesta actualizados.');
    }
}
```

- [ ] **Step 4: Registrar las rutas**

En `routes/web.php`, dentro del grupo autenticado donde ya viven las demás rutas de `tenant-config`:

```php
Route::get('tenant-config/slas', [IncidentSlaController::class, 'index'])->name('tenant-config.slas.index');
Route::put('tenant-config/slas', [IncidentSlaController::class, 'update'])->name('tenant-config.slas.update');
```

- [ ] **Step 5: Crear la página React**

Create `resources/js/pages/tenant-config/slas.tsx`:

```tsx
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface PriorityRow {
    id: number;
    code: string;
    name: string;
    default_sla_seconds: number | null;
    sla_seconds: number | null;
}

export default function Slas({ priorities }: { priorities: PriorityRow[] }) {
    const form = useForm({
        slas: priorities.map((p) => ({
            incident_priority_id: p.id,
            sla_seconds: p.sla_seconds,
        })),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('tenant-config.slas.update'), { preserveScroll: true });
    };

    const setMinutes = (index: number, value: string) => {
        const next = [...form.data.slas];
        next[index] = {
            ...next[index],
            sla_seconds: value === '' ? null : Number(value) * 60,
        };
        form.setData('slas', next);
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <h1 className="sam-h2">Tiempos de respuesta</h1>
            <p className="sam-body text-fg-2">
                Minutos para que alguien reconozca un incidente antes de que SAM escale. Vacío usa el valor recomendado.
            </p>

            {priorities.map((p, i) => (
                <label key={p.id} className="flex items-center justify-between gap-4">
                    <span className="sam-body">{p.name}</span>
                    <input
                        type="number"
                        min={1}
                        max={1440}
                        className="w-28 rounded-md border border-border bg-surface-1 px-2 py-1 text-right"
                        placeholder={
                            p.default_sla_seconds ? String(p.default_sla_seconds / 60) : 'sin SLA'
                        }
                        value={
                            form.data.slas[i].sla_seconds === null
                                ? ''
                                : String(form.data.slas[i].sla_seconds! / 60)
                        }
                        onChange={(e) => setMinutes(i, e.target.value)}
                    />
                </label>
            ))}

            <button type="submit" disabled={form.processing} className="self-start rounded-md bg-primary px-4 py-2 text-primary-foreground">
                Guardar
            </button>
        </form>
    );
}
```

- [ ] **Step 6: Regenerar tipados, correr tests y gates de frontend**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=IncidentSlaControllerTest
npm run types:check && npm run lint:check && npm run format:check && npm run build
```
Expected: 3 tests PASS y los cuatro gates de frontend en verde.

- [ ] **Step 7: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TenantConfig routes/web.php resources/js/pages/tenant-config/slas.tsx tests/Feature/Http/TenantConfig/IncidentSlaControllerTest.php
git commit -m "feat(tenant-config): pantalla de tiempos de respuesta por prioridad"
```

---

# DÍA 4 — Defectos de comportamiento

### Task 8: Una acción de envío deja de notificar dos veces

**El gap.** `NotifyOnActionExecuted` escucha `ActionExecuted` y, cuando el tipo de acción es `send_email`, `send_sms`, `send_push` o `send_whatsapp`, llama a `SendNotification`. Pero esa acción **ya envió el mensaje** — eso es lo que hace un ejecutor de tipo envío. El listener genera un **segundo** aviso, y como el payload crudo del workflow no trae destinatarios explícitos, `ResolveRecipients` cae a su ruta por defecto: **fan-out a todos los miembros del equipo**. Resultado: cada acción de envío dirigida se convierte además en un correo a toda la organización.

**Cómo lo cerramos.** Desregistrar el listener. No hay un caso de uso que lo justifique: la notificación dirigida ya la produjo la acción. Se conserva la clase por si más adelante se quiere un aviso de *fallo* de acción, pero deja de estar suscrita.

**Files:**
- Modify: `app/Domains/Notifications/NotificationsServiceProvider.php`
- Test: `tests/Feature/Domains/Notifications/ActionExecutedDoesNotDoubleNotifyTest.php`

**Interfaces:**
- Consumes: `App\Domains\Automation\Events\ActionExecuted`.
- Produces: ningún listener de Notifications suscrito a `ActionExecuted`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionExecutedDoesNotDoubleNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_action_does_not_produce_a_second_notification(): void
    {
        $team = Team::factory()->create();
        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => 'send_email',
        ]);

        $before = Notification::query()->count();

        event(new ActionExecuted($execution));

        $this->assertSame($before, Notification::query()->count());
    }
}
```

> Si `ActionExecution::$action_type` es un enum casteado, pasar el caso del enum en vez de la cadena. Si el constructor de `ActionExecuted` recibe más argumentos, completarlos con lo que ya usen los tests existentes de Automation.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=ActionExecutedDoesNotDoubleNotifyTest`
Expected: FAIL — el conteo de notificaciones aumenta.

- [ ] **Step 3: Desregistrar el listener**

En `app/Domains/Notifications/NotificationsServiceProvider.php`, eliminar la línea que registra el listener:

```php
        // BORRAR esta línea:
        Event::listen(ActionExecuted::class, NotifyOnActionExecuted::class);
```

Eliminar también el `use` de `ActionExecuted` y de `NotifyOnActionExecuted` si quedan sin uso en el archivo. Añadir sobre el bloque de registros restante:

```php
        // NotifyOnActionExecuted no se registra: las acciones de tipo envío ya
        // notifican por sí mismas, y un segundo aviso sin destinatarios explícitos
        // termina en fan-out a todo el equipo.
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=ActionExecutedDoesNotDoubleNotifyTest`
Expected: PASS.

- [ ] **Step 5: Correr las suites de Notifications y Automation**

Run: `php artisan test --compact tests/Feature/Domains/Notifications tests/Feature/Domains/Automation`
Expected: PASS. Si algún test existente afirmaba que `ActionExecuted` genera notificación, **ese test documentaba el defecto**: borrarlo y dejar constancia en el mensaje de commit.

- [ ] **Step 6: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Notifications tests/Feature/Domains/Notifications/ActionExecutedDoesNotDoubleNotifyTest.php
git commit -m "fix(notifications): elimina la notificacion duplicada al ejecutar acciones de envio"
```

---

### Task 9: El análisis visual recibe sólo imágenes

**El gap.** `EvaluateEventMultimodally` manda al modelo **toda** la evidencia asociada al evento. Las imágenes van como imagen; el video y el audio van como documento en base64 — un formato pensado para PDFs. El modelo no puede interpretarlos como contenido audiovisual, y aun así se paga el envío de cada byte. La decisión de producto ya tomada es *solo imágenes, estricto*; falta en el código.

**Cómo lo cerramos.** Filtrar la colección a `MediaType::Image` y `MediaType::Snapshot` antes de construir las peticiones al modelo. Los archivos excluidos siguen guardados y visibles como evidencia en el incidente: se excluyen del análisis, no del expediente.

**Files:**
- Modify: `app/Domains/AI/Actions/EvaluateEventMultimodally.php:70-105`
- Test: `tests/Feature/Domains/AI/MultimodalImageOnlyTest.php`

**Interfaces:**
- Consumes: `App\Domains\Context\Enums\MediaType`.
- Produces: sin cambio de firma pública; cambia el conjunto de evidencia evaluada.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultimodalImageOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_and_audio_are_not_sent_to_the_model(): void
    {
        $team = Team::factory()->create();

        $image = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'media_type' => MediaType::Image,
        ]);
        EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'media_type' => MediaType::Video,
            'normalized_event_id' => $image->normalized_event_id,
        ]);

        $evaluated = app(EvaluateEventMultimodally::class)
            ->execute($image->normalized_event_id);

        $this->assertCount(1, $evaluated, 'Se evaluó más de una pieza: el video no fue filtrado');
    }
}
```

> Ajustar la firma de `execute()` y el tipo de retorno a los reales del archivo; el aserto sobre el conteo es lo que importa. Si `execute()` no devuelve la colección evaluada, afirmar sobre el número de filas creadas en `ai_media_assessments`.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=MultimodalImageOnlyTest`
Expected: FAIL — se evalúan 2 piezas.

- [ ] **Step 3: Filtrar la colección**

En `app/Domains/AI/Actions/EvaluateEventMultimodally.php`, inmediatamente después de cargar la colección de media y antes del bucle que arma las peticiones:

```php
        // Solo imágenes: el modelo no interpreta video ni audio, y enviarlos
        // como documento base64 se paga sin obtener señal. El archivo excluido
        // sigue siendo evidencia del incidente, no se borra.
        $media = $media->filter(fn ($item) => in_array(
            $item->media_type,
            [MediaType::Image, MediaType::Snapshot, null],
            true,
        ))->values();
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=MultimodalImageOnlyTest`
Expected: PASS.

- [ ] **Step 5: Correr la suite de AI**

Run: `php artisan test --compact tests/Feature/Domains/AI`
Expected: PASS.

- [ ] **Step 6: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/AI tests/Feature/Domains/AI/MultimodalImageOnlyTest.php
git commit -m "fix(ai): el analisis multimodal evalua solo imagenes"
```

---

### Task 10: Los eventos no mapeados dejan de disfrazarse de pánico

**El gap.** Cuando llega un evento sin regla de mapeo, `NormalizeRawEvent` lo marca como `unmapped` buscando `EventType::where('code', 'unmapped')`. Ese tipo **nunca se siembra**: `grep "'unmapped'" database/seeders/NormalizationSeeder.php` devuelve **cero**. El código cae entonces a su respaldo, que es *el primer tipo de evento de la tabla* — y ese resulta ser `panic_button`. En una base sembrada normalmente, **todo evento desconocido se clasifica como botón de pánico**, con la severidad y el protocolo que eso arrastra. Sólo existe correctamente en los fixtures de tests, que lo crean a mano.

**Cómo lo cerramos.** Sembrarlo. Una entrada en el seeder, con categoría operacional y severidad baja, para que el respaldo nunca se use.

**Files:**
- Modify: `database/seeders/NormalizationSeeder.php`
- Test: `tests/Feature/Domains/Normalization/UnmappedEventTypeSeededTest.php`

**Interfaces:**
- Produces: fila `event_types` con `code = 'unmapped'`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Normalization\Models\EventType;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmappedEventTypeSeededTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmapped_event_type_exists_after_seeding(): void
    {
        $this->seed(NormalizationSeeder::class);

        $unmapped = EventType::query()->where('code', 'unmapped')->first();

        $this->assertNotNull($unmapped, 'El tipo unmapped no se sembró: los eventos desconocidos caerían en panic_button');
        $this->assertNotSame(
            EventType::query()->orderBy('id')->value('code'),
            'unmapped',
            'unmapped no debe ser el primer tipo: el fallback dejaría de ser detectable',
        );
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=UnmappedEventTypeSeededTest`
Expected: FAIL con "El tipo unmapped no se sembró".

- [ ] **Step 3: Sembrar el tipo**

En `database/seeders/NormalizationSeeder.php`, **al final** del arreglo de definiciones de tipos de evento (nunca al principio: el respaldo toma el primero y queremos que siga siendo detectable):

```php
            [
                'code' => 'unmapped',
                'label' => 'Sin mapear',
                'category' => 'operational',
                'default_severity' => 'low',
            ],
```

Ajustar las claves del arreglo a las que ya usan las demás entradas del mismo bloque.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=UnmappedEventTypeSeededTest`
Expected: PASS.

- [ ] **Step 5: Correr la suite de Normalization**

Run: `php artisan test --compact tests/Feature/Domains/Normalization`
Expected: PASS.

- [ ] **Step 6: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/NormalizationSeeder.php tests/Feature/Domains/Normalization/UnmappedEventTypeSeededTest.php
git commit -m "fix(normalization): siembra el tipo unmapped para que los eventos desconocidos no caigan en panic_button"
```

> **Nota de despliegue:** correr `php artisan db:seed --class=NormalizationSeeder` tras desplegar. El seeder usa `updateOrCreate`, así que es seguro sobre datos existentes.

---

# DÍA 5 — Higiene de datos y verdad en la interfaz

### Task 11: La base deja de crecer sin control

**El gap.** Las claves de deduplicación de eventos se crean con `expires_at = now()->addHours(24)`, pero **nadie las borra**: `grep` sobre delete/prune/purge de `EventDeduplicationKey` devuelve cero. La tabla crece con cada evento recibido, para siempre. Y `ExpireOldReports` existe pero **no está programado**, así que los archivos de reportes en almacenamiento nunca se eliminan pese a que hay política de retención escrita.

**Cómo lo cerramos.** Un job de purga diario para las claves vencidas y el registro de `ExpireOldReports` en el scheduler.

**Files:**
- Create: `app/Domains/Ingestion/Jobs/PruneDeduplicationKeysJob.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Domains/Ingestion/PruneDeduplicationKeysJobTest.php`

**Interfaces:**
- Produces: `PruneDeduplicationKeysJob` sin argumentos, cola `ingestion`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Jobs\PruneDeduplicationKeysJob;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneDeduplicationKeysJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_expired_keys_are_removed(): void
    {
        $expired = EventDeduplicationKey::factory()->create(['expires_at' => now()->subHour()]);
        $alive = EventDeduplicationKey::factory()->create(['expires_at' => now()->addHour()]);

        (new PruneDeduplicationKeysJob)->handle();

        $this->assertDatabaseMissing('event_deduplication_keys', ['id' => $expired->id]);
        $this->assertDatabaseHas('event_deduplication_keys', ['id' => $alive->id]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=PruneDeduplicationKeysJobTest`
Expected: FAIL — la clase no existe.

- [ ] **Step 3: Crear el job**

Create `app/Domains/Ingestion/Jobs/PruneDeduplicationKeysJob.php`:

```php
<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PruneDeduplicationKeysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        EventDeduplicationKey::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    public function failed(Throwable $e): void
    {
        JobFailureReporter::report(static::class, $e);
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=PruneDeduplicationKeysJobTest`
Expected: PASS.

- [ ] **Step 5: Programar la purga y la expiración de reportes**

En `routes/console.php`:

```php
Schedule::job(new \App\Domains\Ingestion\Jobs\PruneDeduplicationKeysJob)->dailyAt('03:15')->onOneServer();
Schedule::command('reports:expire')->dailyAt('03:30')->onOneServer();
```

Si `ExpireOldReports` es una acción y no un comando de consola, envolverla en un job y programar el job en vez del comando; verificar con `grep -rn "class ExpireOldReports" app/`.

- [ ] **Step 6: Verificar el registro y commitear**

```bash
php artisan schedule:list
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Domains/Ingestion/Jobs/PruneDeduplicationKeysJob.php routes/console.php tests/Feature/Domains/Ingestion/PruneDeduplicationKeysJobTest.php
git commit -m "chore(ingestion): purga diaria de claves de dedup vencidas y expiracion de reportes programada"
```

---

### Task 12: La interfaz deja de prometer configuración que no existe

**El gap.** Tres áreas tienen formulario completo, permisos y persistencia, y **ningún consumidor**:

1. **Perfil de IA** — de sus seis campos editables, sólo `automation_level` llega al pipeline; lo verifiqué en `TenantAIProfileData`, que transporta exactamente `teamId`, `automationLevel`, `monthlyTokenLimit`, `dailyCallLimit` y `preferredModel`. Los otros cinco (tolerancia al riesgo, tolerancia a falsos positivos, estrategia de media, overrides de prompt, política de revisión humana) se leen de la base y se descartan.
2. **Overrides de reglas** — se guardan; nadie invoca el aplicador.
3. **Políticas de escalación del módulo de decisiones** — su evento se emite y no tiene ningún oyente.

Un cliente que configure cualquiera de las tres y no vea cambios concluye, con razón, que el sistema no funciona. Es el daño más barato de eliminar y el más caro de dejar.

**Cómo lo cerramos.** Retirar de la interfaz los controles sin consumidor. Los datos y los endpoints se quedan: cuando se conecte la lógica, la interfaz vuelve. Lo que se elimina es la **promesa falsa**, no el trabajo hecho.

**Files:**
- Modify: `resources/js/pages/tenant-config/` (el formulario del perfil de IA)
- Modify: la vista de overrides de reglas y la de políticas de escalación de Decisions
- Test: `tests/Feature/Http/TenantConfig/AiProfileFormExposesOnlyLiveFieldsTest.php`

**Interfaces:**
- Consumes: `TenantAIProfileData` (define qué campos están realmente vivos).
- Produces: formularios que sólo muestran campos con consumidor real.

- [ ] **Step 1: Localizar los tres formularios**

```bash
grep -rn "risk_tolerance\|false_positive_tolerance\|media_strategy\|prompt_override\|human_review_policy" resources/js/
grep -rn "rule_override\|RuleOverride" resources/js/
grep -rn "escalation_polic" resources/js/pages/rules resources/js/pages/tenant-config 2>/dev/null
```

Anotar cada archivo y línea antes de tocar nada.

- [ ] **Step 2: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Http\TenantConfig;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProfileFormExposesOnlyLiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dead_ai_fields_are_not_offered_to_the_tenant(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('tenant-config.index'));

        $response->assertOk();
        foreach (['risk_tolerance', 'false_positive_tolerance', 'media_strategy', 'human_review_policy'] as $dead) {
            $response->assertDontSee($dead);
        }
    }
}
```

> Ajustar el nombre de la ruta al real que devuelva `php artisan route:list | grep tenant-config`.

- [ ] **Step 3: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=AiProfileFormExposesOnlyLiveFieldsTest`
Expected: FAIL — los campos aparecen en los props de Inertia.

- [ ] **Step 4: Retirar los campos muertos del formulario de IA**

Eliminar del componente React los controles de los cinco campos sin consumidor y dejar sólo el nivel de automatización. Sobre el bloque restante, añadir el comentario:

```tsx
{/*
  Sólo se expone automation_level: es el único campo de TenantAIProfile que
  hoy llega al pipeline (ver app/Domains/AI/Data/TenantAIProfileData.php).
  Los demás se retiraron de la UI hasta que exista consumidor real.
*/}
```

- [ ] **Step 5: Retirar la entrada de overrides de reglas y la de políticas de escalación de Decisions**

Quitar los enlaces de navegación y las pestañas que llevan a esas dos pantallas. No borrar los controladores ni las rutas: quedan sin enlazar hasta que se conecten.

- [ ] **Step 6: Correr el test, los gates de frontend y commitear**

```bash
php artisan test --compact --filter=AiProfileFormExposesOnlyLiveFieldsTest
php artisan test --compact
npm run types:check && npm run lint:check && npm run format:check && npm run build
vendor/bin/pint --dirty --format agent
git add resources/js tests/Feature/Http/TenantConfig/AiProfileFormExposesOnlyLiveFieldsTest.php
git commit -m "fix(ui): retira de la configuracion los campos sin consumidor real"
```

---

## Runbook de despliegue

Ejecutar **en este orden** tras mergear las 12 tareas:

```bash
php artisan migrate --force
php artisan webhooks:encrypt-secrets
php artisan db:seed --class=NormalizationSeeder --force
php artisan horizon:terminate
php artisan schedule:list
```

Verificaciones manuales antes de abrir a clientes:

- [ ] Un webhook de prueba con firma válida sigue entrando (confirma que el cifrado del secreto no rompió la validación).
- [ ] Un evento de tipo desconocido aparece como `unmapped` en la bandeja, no como pánico.
- [ ] Dos operadores intentan tomar el mismo incidente: uno lo consigue, el otro recibe la negativa.
- [ ] Un incidente tomado no recibe llamada de verificación ni escalación.
- [ ] Cambiar el SLA de "crítica" a 3 minutos para un tenant y confirmar que otro tenant sigue en 5.
- [ ] `php artisan horizon:snapshot` produce métricas visibles en el panel de Horizon.

---

## Semana 2 — lo que queda

Por orden de valor, para retomar sin volver a auditar:

1. Conectar los cinco campos muertos del perfil de IA (o eliminarlos del esquema si se decide que no aportan).
2. Conectar los overrides de reglas y las políticas de escalación de Decisions, o retirarlos del modelo de datos.
3. Política de retención de media con personas y de rastros GPS — requiere decisión legal previa.
4. Trigger de PostgreSQL para inmutabilidad de `audit_logs`.
5. Reintentos y respaldo de canal de notificación: el código existe y tiene tests, sólo falta despacharlo.
6. Materializar el incidente en estado "entrante" antes de la evaluación de IA, para que exista algo tomable desde el primer segundo (la Tarea 4 cubre el bloqueo; esto cubre la ventana previa).
7. Filtrar la escalación por `escalation_type` en lugar de tomar el primer config activo del tenant.
8. Dar reloj de SLA a los incidentes creados manualmente.
