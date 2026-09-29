# Logging narrativo — Fase 1: Fundación — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar lista la base del logging narrativo y seguro:
- la API `SystemLog`;
- redacción global de datos sensibles y `SafeException`;
- la red automática (jobs, HTTP saliente, auth, peticiones denegadas);
- la configuración JSON por defecto;
- la migración de los ~55 logs existentes, con sus fugas corregidas;
- las guardias (test arquitectural, helper de test, regla y catálogo).

**Architecture:** Todo en `app/Support/` (sin directorios nuevos):
- `SystemLog` escribe líneas con esquema fijo (código `dominio.etapa.resultado`, `outcome`, `reason`, `input`, `calc`, `result`, `duration_ms`, `error`) en el canal por defecto, y el `Context` de `PipelineTrace` añade `trace_id`/`team_id`;
- un processor de Monolog (`RedactSensitiveLogData`), enganchado por `tap` a todos los canales, enmascara claves y patrones sensibles;
- `AutomaticSystemLog` escucha eventos del framework;
- `DeniedRequestLog` se invoca desde `bootstrap/app.php`.

**Tech Stack:** Laravel 13.24 · Monolog 3.10 · PHPUnit 13 · PHP 8.5.

**Spec:** `docs/superpowers/specs/2026-09-28-system-logging-design.md`

## Global Constraints

- Código de log: regex `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`, en inglés snake_case, sin texto variable.
- `outcome` ∈ `ok | skipped | degraded | failed`. Nivel: `ok`/`skipped` → `info` (o `debug` si se pasa `debug: true`), `degraded` → `warning`, `failed` → `error`.
- `reason` es obligatorio y no vacío cuando `outcome` ≠ `ok`, y es un código estable (snake_case), nunca una frase.
- Nunca en un log: teléfonos, emails, nombres de personas, direcciones, tokens, secretos, firmas, URLs con query, payloads crudos, texto libre de operadores, prompts/respuestas de IA, `getMessage()` sin sanear.
- Sin directorios nuevos en `app/`, sin cambios en `composer.json`/`package.json`. El único doc nuevo aprobado es `docs/SAM/logging.md`.
- Tests: PHPUnit (no Pest), DB real SQLite en memoria, sin mockear la DB, sin borrar ni debilitar tests existentes. Los tests que hoy hacen `Log::spy()` se migran a `AssertsSystemLog` y afirman lo mismo o más.
- Commits `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla de `CLAUDE.md`).
- Correr en el worktree `.claude/worktrees/system-logging`, que tiene `vendor/` real. Si falta `.env`, los tests muestran un warning inocuo de dotenv que puede ignorarse.

## Review Focus

1. **Números que no son teléfonos:** un timestamp ISO (`2026-09-28T10:00:00Z`), una fecha `2026-09-28`, un decimal `0.123456789`, un ULID o un id entero NO se enmascaran como `[phone]`. Los tests del Task 1 lo fijan.
2. **Claves técnicas que contienen palabras sensibles:** `raw_event_id`, `token_id`, `event_name`, `has_signature` (bool) y `signature_mode` NO se redactan. Solo se redactan las claves cuyo valor sería el dato sensible. Lo fija el Task 1.
3. **Orden de los processors:** el `trace_id` del `Context` tiene que salir en `extra` y **también** pasar por la redacción (un `Context::add('phone', …)` sale enmascarado). Lo fija el Task 3 escribiendo a archivo real.
4. **Tests con `Event::fake()`:** capturar logs no puede depender del dispatcher de eventos. Si dependiera, un test que falsea todos los eventos no vería nada. `SystemLog::listen` es independiente de eventos, y lo fija el Task 2.
5. **Excepción con datos sensibles en el mensaje** (pasada como `exception` al contexto por el handler de Laravel): sale como `SafeException::describe`, sin el teléfono ni la URL firmada y sin romper el JSON. Lo fija el Task 3.

---

## Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| Create `app/Support/RedactSensitiveLogData.php` | Processor Monolog + `sanitize()`/`redact()` estáticos (reglas de claves y patrones). |
| Create `app/Support/RedactLogChannel.php` | Clase `tap` que engancha el processor a un canal. |
| Create `app/Support/SafeException.php` | `describe(Throwable, bool $withTrace = false): array` seguro. |
| Create `app/Support/SystemLog.php` | API narrativa y seam `listen()` para tests. |
| Create `app/Support/AutomaticSystemLog.php` | Red automática: colas, HTTP saliente, auth. |
| Create `app/Support/DeniedRequestLog.php` | 401/403/419/429 y 404 de webhooks. |
| Modify `app/Support/JobFailureReporter.php` | Delegar en `SystemLog::failed`. |
| Modify `app/Support/ObjectStorageFailure.php` | Delegar en `SystemLog::failed`. |
| Modify `app/Providers/AppServiceProvider.php` | `AutomaticSystemLog::register()`. |
| Modify `bootstrap/app.php` | `DeniedRequestLog::record()` en `respond()` y `context()`. |
| Modify `config/logging.php`, `.env.example` | `tap` en todos los canales, `json` → `system.json`, stderr opcional, `telematics` JSON, `LOG_STACK=single,json`. |
| Modify ~35 archivos de `app/Domains/**` y `app/Infrastructure/**` | Migración de `Log::` a `SystemLog` (Tasks 7 y 8). |
| Create `tests/Concerns/AssertsSystemLog.php` | Helper de aserción. |
| Create `tests/Unit/Support/RedactSensitiveLogDataTest.php`, `tests/Unit/Support/SafeExceptionTest.php`, `tests/Feature/Support/SystemLogTest.php`, `tests/Feature/Support/LoggingChannelsTest.php`, `tests/Feature/Support/AutomaticSystemLogTest.php`, `tests/Feature/Support/DeniedRequestLogTest.php`, `tests/Feature/Architecture/LoggingConventionsTest.php` | Tests. |
| Modify `tests/Feature/Support/JobFailureReporterTest.php`, `tests/Feature/Domains/Drivers/DriverSyncHandlerServiceTest.php`, `tests/Feature/Domains/Context/ExtractVideoFramesJobTest.php`, `tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php`, `tests/Feature/Domains/Analytics/ReportDownloadStorageFailureTest.php` | De `Log::spy` a `AssertsSystemLog`. |
| Modify `app/CLAUDE.md`; Create `docs/SAM/logging.md` | Regla y catálogo. |

---

### Task 1: Redacción y excepción segura

**Files:**
- Create: `app/Support/RedactSensitiveLogData.php`
- Create: `app/Support/RedactLogChannel.php`
- Create: `app/Support/SafeException.php`
- Test: `tests/Unit/Support/RedactSensitiveLogDataTest.php`, `tests/Unit/Support/SafeExceptionTest.php`

**Interfaces:**
- Produces:
  - `RedactSensitiveLogData::MASK = '[redacted]'`;
  - `RedactSensitiveLogData::sanitize(string $text): string` (solo patrones);
  - `RedactSensitiveLogData::redact(mixed $value, ?string $key = null): mixed` (claves + patrones, recursivo; los `Throwable` pasan por `SafeException::describe($e, true)`);
  - `RedactSensitiveLogData::findings(mixed $value): list<string>` (rutas `a.b.c` que se redactarían; para el helper de test);
  - `__invoke(LogRecord $record): LogRecord`;
  - `RedactLogChannel::__invoke(\Illuminate\Log\Logger $logger): void`;
  - `SafeException::describe(Throwable $e, bool $withTrace = false): array{class: string, code: int|string, message: string, at: string, previous?: string, sqlstate?: string, trace?: list<string>}`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Support/RedactSensitiveLogDataTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\RedactSensitiveLogData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedactSensitiveLogDataTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitiveStrings(): array
    {
        return [
            'e164 phone' => ['llamada a +525512345678 falló', 'llamada a [phone] falló'],
            'spaced phone' => ['tel 55 1234 5678 ok', 'tel [phone] ok'],
            'email' => ['user Ana.Perez+x@empresa.com.mx rechazado', 'user [email] rechazado'],
            'signed url' => ['GET https://s3.amazonaws.com/b/k.jpg?X-Amz-Signature=abc&X-Amz-Credential=d failed', 'GET https://s3.amazonaws.com/b/k.jpg?[redacted] failed'],
            'bearer' => ['Authorization: Bearer eyJhbGciOi.abc-def_ghi', 'Authorization: Bearer [redacted]'],
        ];
    }

    #[DataProvider('sensitiveStrings')]
    public function test_sanitize_masks_sensitive_patterns(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactSensitiveLogData::sanitize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function harmlessStrings(): array
    {
        return [
            'iso timestamp' => ['2026-09-28T10:00:00Z'],
            'date' => ['desde 2026-09-28 hasta 2026-10-01'],
            'decimal' => ['riesgo 0.123456789'],
            'ulid' => ['01k6b7yq3m9x2c4d5e6f7g8h9j'],
            'uuid' => ['0e8f1c2a-3b4d-4e5f-8a9b-123456789012'],
            'short number' => ['intento 3 de 5, 1200 ms'],
            'url without query' => ['https://api.samsara.com/fleet/vehicles/stats'],
        ];
    }

    #[DataProvider('harmlessStrings')]
    public function test_sanitize_keeps_harmless_values(string $input): void
    {
        $this->assertSame($input, RedactSensitiveLogData::sanitize($input));
    }

    public function test_redact_masks_sensitive_keys_recursively_but_keeps_technical_keys(): void
    {
        $out = RedactSensitiveLogData::redact([
            'from' => '+525512345678',
            'token' => 'ABC123',
            'phone_number' => '5512345678',
            'recipient' => ['email' => 'a@b.co', 'name' => 'Ana', 'user_id' => 9],
            'raw_payload' => ['x' => 1],
            'signature' => 'deadbeef',
            'raw_event_id' => 5,
            'token_id' => 7,
            'event_name' => 'IncidentCreated',
            'has_signature' => true,
            'signature_mode' => 'raw_header',
            'job' => 'App\\Jobs\\X',
            'count' => 12345678901,
        ]);

        $this->assertSame('[phone]', $out['from']);
        $this->assertSame('[redacted]', $out['token']);
        $this->assertSame('[redacted]', $out['phone_number']);
        $this->assertSame(['email' => '[redacted]', 'name' => '[redacted]', 'user_id' => 9], $out['recipient']);
        $this->assertSame('[redacted]', $out['raw_payload']);
        $this->assertSame('[redacted]', $out['signature']);
        $this->assertSame(5, $out['raw_event_id']);
        $this->assertSame(7, $out['token_id']);
        $this->assertSame('IncidentCreated', $out['event_name']);
        $this->assertTrue($out['has_signature']);
        $this->assertSame('raw_header', $out['signature_mode']);
        $this->assertSame('App\\Jobs\\X', $out['job']);
        $this->assertSame(12345678901, $out['count']);
    }

    public function test_redact_describes_throwables_safely(): void
    {
        $out = RedactSensitiveLogData::redact(['exception' => new RuntimeException('no se pudo llamar a +525512345678')]);

        $this->assertSame(RuntimeException::class, $out['exception']['class']);
        $this->assertSame('no se pudo llamar a [phone]', $out['exception']['message']);
        $this->assertArrayHasKey('trace', $out['exception']);
    }

    public function test_findings_lists_the_paths_that_would_be_redacted(): void
    {
        $this->assertSame(
            ['from', 'recipient.email'],
            RedactSensitiveLogData::findings(['from' => '+525512345678', 'recipient' => ['email' => 'a@b.co', 'user_id' => 1], 'ok' => 'x']),
        );
        $this->assertSame([], RedactSensitiveLogData::findings(['raw_event_id' => 1, 'reason' => 'skip_category']));
    }

    public function test_api_keys_are_redacted_even_though_key_is_a_technical_suffix(): void
    {
        $this->assertSame(
            ['api_key' => '[redacted]', 'apiKey' => '[redacted]', 'event_key' => 'evt:1'],
            RedactSensitiveLogData::redact(['api_key' => 'sk-1', 'apiKey' => 'sk-2', 'event_key' => 'evt:1']),
        );
    }

    public function test_processor_redacts_message_context_and_extra(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'aviso a ana@x.com',
            context: ['phone' => '5512345678', 'raw_event_id' => 1],
            extra: ['trace_id' => '01k6b7yq3m9x2c4d5e6f7g8h9j', 'email' => 'b@y.com'],
        );

        $out = (new RedactSensitiveLogData)($record);

        $this->assertSame('aviso a [email]', $out->message);
        $this->assertSame(['phone' => '[redacted]', 'raw_event_id' => 1], $out->context);
        $this->assertSame(['trace_id' => '01k6b7yq3m9x2c4d5e6f7g8h9j', 'email' => '[redacted]'], $out->extra);
    }
}
```

`tests/Unit/Support/SafeExceptionTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\SafeException;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SafeExceptionTest extends TestCase
{
    public function test_describes_class_code_location_and_sanitized_message(): void
    {
        $e = new RuntimeException('GET https://bucket.s3/x.jpg?X-Amz-Signature=secret failed for a@b.co', 42, new LogicException('inner'));

        $d = SafeException::describe($e);

        $this->assertSame(RuntimeException::class, $d['class']);
        $this->assertSame(42, $d['code']);
        $this->assertSame('GET https://bucket.s3/x.jpg?[redacted] failed for [email]', $d['message']);
        $this->assertStringContainsString('SafeExceptionTest.php:', $d['at']);
        $this->assertStringStartsNotWith('/', $d['at']);
        $this->assertSame(LogicException::class, $d['previous']);
        $this->assertArrayNotHasKey('trace', $d);
    }

    public function test_truncates_long_messages_to_300_chars(): void
    {
        $d = SafeException::describe(new RuntimeException(str_repeat('a', 1000)));

        $this->assertLessThanOrEqual(300, mb_strlen($d['message']));
    }

    public function test_query_exceptions_never_expose_bindings(): void
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation');
        $pdo->errorInfo = ['23505', 7, 'duplicate'];
        $e = new QueryException('pgsql', 'insert into users (email, phone) values (?, ?)', ['ana@x.com', '+525512345678'], $pdo);

        $d = SafeException::describe($e);

        $this->assertSame('23505', $d['sqlstate']);
        $this->assertSame('insert into users (email, phone) values (?, ?)', $d['message']);
        $this->assertStringNotContainsString('ana@x.com', json_encode($d));
        $this->assertStringNotContainsString('5512345678', json_encode($d));
    }

    public function test_trace_lists_frames_without_arguments(): void
    {
        $d = SafeException::describe(new RuntimeException('x'), withTrace: true);

        $this->assertNotEmpty($d['trace']);
        $this->assertLessThanOrEqual(15, count($d['trace']));
        $this->assertMatchesRegularExpression('/^.+:\d+ .+$|^\[internal\] .+$/', $d['trace'][0]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Support/RedactSensitiveLogDataTest.php tests/Unit/Support/SafeExceptionTest.php`
Expected: FAIL con `Class "App\Support\RedactSensitiveLogData" not found`.

- [ ] **Step 3: Implement**

`app/Support/RedactSensitiveLogData.php`:

```php
<?php

namespace App\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Red de seguridad de TODOS los canales de log: enmascara lo que nunca debe
 * salir (teléfonos, emails, tokens, secretos, firmas, URLs firmadas, payloads
 * crudos) aunque se cuele por un mensaje de excepción o por el Context.
 *
 * `SystemLog` ya debe llegar limpio; esto atrapa lo que no. Se engancha con
 * `RedactLogChannel` (tap) y corre DESPUÉS del processor del Context, así que
 * también cubre `extra`.
 */
final class RedactSensitiveLogData implements ProcessorInterface
{
    public const string MASK = '[redacted]';

    /**
     * Palabras que, como segmento de una clave, marcan su valor como dato
     * sensible (`phone_number`, `recipientEmail`, `raw_payload`).
     *
     * @var list<string>
     */
    private const array SENSITIVE_WORDS = [
        'phone', 'email', 'password', 'secret', 'token', 'signature', 'authorization',
        'cookie', 'apikey', 'otp', 'payload', 'body', 'raw', 'prompt', 'address',
        'name', 'credential', 'credentials',
    ];

    /**
     * Último segmento que convierte la clave en metadato técnico aunque
     * contenga una palabra sensible (`raw_event_id`, `token_id`, `signature_mode`).
     *
     * @var list<string>
     */
    private const array TECHNICAL_SUFFIXES = [
        'id', 'ids', 'count', 'type', 'status', 'class', 'mode', 'variant',
        'present', 'length', 'bytes', 'source', 'strategy', 'key',
    ];

    /**
     * Claves exactas que nunca se redactan.
     *
     * @var list<string>
     */
    private const array ALLOWED_KEYS = [
        'job', 'queue', 'channel', 'connection', 'event_name', 'agent_name',
        'class', 'route_name', 'meter_code', 'code',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::sanitize($record->message),
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        );
    }

    /**
     * Enmascara patrones sensibles dentro de un texto libre.
     */
    public static function sanitize(string $text): string
    {
        $text = (string) preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text);
        $text = (string) preg_replace('~(https?://[^\s?#"\']+)\?[^\s"\'#]*~i', '$1?'.self::MASK, $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 '.self::MASK, $text);

        return (string) preg_replace_callback(
            '/(?<![\w.:\/-])\+?\d[\d\s()-]{7,}\d(?![\w.:\/-])/',
            static function (array $match): string {
                $candidate = $match[0];
                $digits = strlen((string) preg_replace('/\D/', '', $candidate));

                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate) === 1 || $digits < 10 || $digits > 15) {
                    return $candidate;
                }

                return '[phone]';
            },
            $text,
        );
    }

    /**
     * Redacta un valor de contexto: por clave (recursivo) y por patrón.
     */
    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof Throwable) {
            return SafeException::describe($value, withTrace: true);
        }

        if ($key !== null && self::isSensitiveKey($key) && ! is_bool($value) && $value !== null) {
            return self::MASK;
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $childKey => $child) {
                $out[$childKey] = self::redact($child, is_string($childKey) ? $childKey : null);
            }

            return $out;
        }

        return is_string($value) ? self::sanitize($value) : $value;
    }

    /**
     * Rutas (`a.b.c`) cuyo valor se redactaría. El helper de test lo usa para
     * detectar que se INTENTÓ loguear un dato prohibido.
     *
     * @return list<string>
     */
    public static function findings(mixed $value, string $path = ''): array
    {
        if (! is_array($value)) {
            return [];
        }

        $found = [];

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($child) && ! (is_string($key) && self::isSensitiveKey($key))) {
                array_push($found, ...self::findings($child, $childPath));

                continue;
            }

            if (self::redact($child, is_string($key) ? $key : null) !== $child) {
                $found[] = $childPath;
            }
        }

        return $found;
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::ALLOWED_KEYS, true)) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key));

        // `key` es sufijo técnico (`event_key`), salvo en estas claves exactas.
        if (in_array($normalized, ['api_key', 'code_hash'], true)) {
            return true;
        }

        $words = array_values(array_filter(
            preg_split('/[_\-.\s]+/', $normalized) ?: [],
            static fn (string $word): bool => $word !== '',
        ));

        if ($words === [] || in_array(end($words), self::TECHNICAL_SUFFIXES, true) || in_array($words[0], ['has', 'is'], true)) {
            return false;
        }

        return array_intersect($words, self::SENSITIVE_WORDS) !== [];
    }
}
```

`app/Support/RedactLogChannel.php`:

```php
<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * `tap` de config/logging.php: engancha la redacción a un canal. Se registra
 * antes que el processor del Context de Laravel, y Monolog ejecuta los
 * processors del último al primero, así que la redacción ve también `extra`.
 */
final class RedactLogChannel
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveLogData);
        }
    }
}
```

`app/Support/SafeException.php`:

```php
<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Descripción de una excepción apta para logs: clase, código, mensaje
 * saneado y ubicación. Sustituye a todo `getMessage()` crudo, que puede
 * llevar bindings SQL, URLs firmadas, teléfonos o el prompt de la IA.
 */
final class SafeException
{
    private const int MAX_MESSAGE = 300;

    private const int MAX_FRAMES = 15;

    /**
     * @return array{class: string, code: int|string, message: string, at: string, previous?: string, sqlstate?: string, trace?: list<string>}
     */
    public static function describe(Throwable $e, bool $withTrace = false): array
    {
        $description = [
            'class' => $e::class,
            'code' => $e->getCode(),
            'message' => self::message($e),
            'at' => self::relative($e->getFile()).':'.$e->getLine(),
        ];

        if ($e->getPrevious() !== null) {
            $description['previous'] = $e->getPrevious()::class;
        }

        if ($e instanceof QueryException) {
            $description['sqlstate'] = (string) ($e->errorInfo[0] ?? $e->getCode());
        }

        if ($withTrace) {
            $description['trace'] = self::trace($e);
        }

        return $description;
    }

    private static function message(Throwable $e): string
    {
        // El mensaje de QueryException incluye los bindings: sólo el SQL con `?`.
        $message = $e instanceof QueryException ? $e->getSql() : $e->getMessage();

        return Str::limit(RedactSensitiveLogData::sanitize($message), self::MAX_MESSAGE, '…');
    }

    /**
     * @return list<string>
     */
    private static function trace(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $frames[] = isset($frame['file'])
                ? self::relative($frame['file']).':'.($frame['line'] ?? 0).' '.$call
                : '[internal] '.$call;
        }

        return $frames;
    }

    private static function relative(string $path): string
    {
        // Fuera de la app (tests unitarios) no hay base_path: cae al cwd.
        try {
            $base = rtrim(base_path(), '/').'/';
        } catch (Throwable) {
            $base = rtrim((string) getcwd(), '/').'/';
        }

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, '/');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Unit/Support/RedactSensitiveLogDataTest.php tests/Unit/Support/SafeExceptionTest.php`
Expected: PASS (todos). Si falla un caso de `harmlessStrings`, ajusta el regex de teléfonos. No cambies el caso.

- [ ] **Step 5: Commit**

```bash
git add app/Support/RedactSensitiveLogData.php app/Support/RedactLogChannel.php app/Support/SafeException.php tests/Unit/Support/RedactSensitiveLogDataTest.php tests/Unit/Support/SafeExceptionTest.php
git commit -m "feat: redacción de datos sensibles en logs y descripción segura de excepciones"
```

---

### Task 2: `SystemLog` y helper de test

**Files:**
- Create: `app/Support/SystemLog.php`
- Create: `tests/Concerns/AssertsSystemLog.php`
- Test: `tests/Feature/Support/SystemLogTest.php`

**Interfaces:**
- Consumes: `SafeException::describe`, `RedactSensitiveLogData::findings`.
- Produces:
  - `SystemLog::ok(string $code, array $input = [], ?array $calc = null, array $result = [], ?int $durationMs = null, bool $debug = false, ?string $channel = null): void`
  - `SystemLog::skipped(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], bool $debug = false, ?string $channel = null): void`
  - `SystemLog::degraded(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], ?Throwable $error = null, ?int $durationMs = null, bool $debug = false, ?string $channel = null): void`
  - `SystemLog::failed(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], ?Throwable $error = null, ?int $durationMs = null, ?string $channel = null): void`
  - `SystemLog::measure(string $code, Closure $callback, array $input = [], bool $debug = false, ?string $channel = null): mixed`
  - `SystemLog::listen(Closure(array{level: string, code: string, context: array<string, mixed>, channel: ?string}): void $listener): void` y `SystemLog::flushListeners(): void`
  - Trait `Tests\Concerns\AssertsSystemLog`:
    - `assertSystemLogged(string $code, ?Closure $where = null): array` (devuelve el contexto de la primera línea que cumple);
    - `assertSystemNotLogged(string $code): void`;
    - `assertNoSensitiveDataLogged(): void`;
    - `systemLogEntries(?string $code = null): list<array>`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/SystemLogTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Support\SystemLog;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use AssertsSystemLog;

    public function test_ok_writes_the_fixed_schema_at_info(): void
    {
        SystemLog::ok('ai.risk.calculated', input: ['severity' => 'high'], calc: ['base' => 0.5, 'final' => 0.65], result: ['priority' => 'high'], durationMs: 12);

        $ctx = $this->assertSystemLogged('ai.risk.calculated');

        $this->assertSame([
            'outcome' => 'ok',
            'input' => ['severity' => 'high'],
            'calc' => ['base' => 0.5, 'final' => 0.65],
            'result' => ['priority' => 'high'],
            'duration_ms' => 12,
        ], $ctx);
        $this->assertSame('info', $this->systemLogEntries('ai.risk.calculated')[0]['level']);
    }

    public function test_level_follows_the_outcome_and_debug_only_lowers_ok_and_skipped(): void
    {
        SystemLog::skipped('ai.gate.skipped', reason: 'skip_category', debug: true);
        SystemLog::degraded('ai.evaluation.rules_only', reason: 'quota_exceeded', debug: true);
        SystemLog::failed('queue.job.failed', reason: 'exception');

        $this->assertSame('debug', $this->systemLogEntries('ai.gate.skipped')[0]['level']);
        $this->assertSame('warning', $this->systemLogEntries('ai.evaluation.rules_only')[0]['level']);
        $this->assertSame('error', $this->systemLogEntries('queue.job.failed')[0]['level']);
        $this->assertSame('skip_category', $this->systemLogEntries('ai.gate.skipped')[0]['context']['reason']);
    }

    public function test_errors_are_described_safely(): void
    {
        SystemLog::degraded('media.download.failed', reason: 'http_error', error: new RuntimeException('GET https://x.s3/a.jpg?sig=1 → 403'));

        $ctx = $this->assertSystemLogged('media.download.failed');

        $this->assertSame(RuntimeException::class, $ctx['error']['class']);
        $this->assertSame('GET https://x.s3/a.jpg?[redacted] → 403', $ctx['error']['message']);
    }

    public function test_invalid_codes_and_missing_reasons_throw_outside_production(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SystemLog::ok('Media download ok');
    }

    public function test_missing_reason_throws_outside_production(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SystemLog::skipped('ai.gate.skipped', reason: '');
    }

    public function test_in_production_a_schema_violation_is_still_written_and_flagged(): void
    {
        $this->app['env'] = 'production';

        SystemLog::ok('Bad Code');

        $this->assertTrue($this->systemLogEntries('Bad Code')[0]['context']['schema_violation']);
    }

    public function test_measure_records_duration_and_rethrows_failures(): void
    {
        $this->assertSame(7, SystemLog::measure('samsara.api.request', fn () => 7, input: ['path' => '/fleet']));
        $ctx = $this->assertSystemLogged('samsara.api.request', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertIsInt($ctx['duration_ms']);

        try {
            SystemLog::measure('samsara.api.request', fn () => throw new RuntimeException('boom'));
            $this->fail('should rethrow');
        } catch (RuntimeException) {
            $this->assertSystemLogged('samsara.api.request', fn (array $c) => $c['outcome'] === 'failed' && $c['reason'] === 'exception');
        }
    }

    public function test_capture_works_even_when_every_event_is_faked(): void
    {
        Event::fake();

        SystemLog::ok('ai.gate.passed');

        $this->assertSystemLogged('ai.gate.passed');
    }

    public function test_no_sensitive_data_assertion_catches_raw_values_passed_to_system_log(): void
    {
        SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'no_keyword', input: ['from' => '+525512345678']);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertNoSensitiveDataLogged();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Support/SystemLogTest.php`
Expected: FAIL con `Trait "Tests\Concerns\AssertsSystemLog" not found`.

- [ ] **Step 3: Implement**

`app/Support/SystemLog.php`:

```php
<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Log narrativo del sistema: una línea JSON por decisión, con esquema fijo.
 *
 *   message      código estable `dominio.etapa.resultado` (filtrar por él)
 *   outcome      ok | skipped | degraded | failed
 *   reason       código estable, obligatorio si outcome ≠ ok
 *   input        datos que decidieron la rama (ids, códigos, flags, umbrales)
 *   calc         cada término y umbral de un cálculo: se rehace a mano
 *   result       qué quedó (ids creados, estado, conteos)
 *   duration_ms  en operaciones medidas
 *   error        SafeException::describe() de la excepción, nunca getMessage()
 *
 * `trace_id`, `team_id` e ids de etapa los añade el Context (PipelineTrace).
 * Nunca teléfonos, emails, nombres, tokens, payloads ni texto libre: ver
 * docs/SAM/logging.md. RedactSensitiveLogData es la red, no el permiso.
 */
final class SystemLog
{
    public const string OK = 'ok';

    public const string SKIPPED = 'skipped';

    public const string DEGRADED = 'degraded';

    public const string FAILED = 'failed';

    public const string CODE_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/';

    /**
     * @var list<Closure(array{level: string, code: string, context: array<string, mixed>, channel: ?string}): void>
     */
    private static array $listeners = [];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     * @param  array<string, mixed>  $result
     */
    public static function ok(string $code, array $input = [], ?array $calc = null, array $result = [], ?int $durationMs = null, bool $debug = false, ?string $channel = null): void
    {
        self::write(self::OK, $code, null, $input, $calc, $result, $durationMs, null, $debug, $channel);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     * @param  array<string, mixed>  $result
     */
    public static function skipped(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], bool $debug = false, ?string $channel = null): void
    {
        self::write(self::SKIPPED, $code, $reason, $input, $calc, $result, null, null, $debug, $channel);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     * @param  array<string, mixed>  $result
     */
    public static function degraded(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], ?Throwable $error = null, ?int $durationMs = null, bool $debug = false, ?string $channel = null): void
    {
        self::write(self::DEGRADED, $code, $reason, $input, $calc, $result, $durationMs, $error, $debug, $channel);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     * @param  array<string, mixed>  $result
     */
    public static function failed(string $code, string $reason, array $input = [], ?array $calc = null, array $result = [], ?Throwable $error = null, ?int $durationMs = null, ?string $channel = null): void
    {
        self::write(self::FAILED, $code, $reason, $input, $calc, $result, $durationMs, $error, false, $channel);
    }

    /**
     * Ejecuta y registra la operación: `ok` con duración, o `failed`
     * (reason `exception`) con duración y relanza.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $input
     * @return TReturn
     */
    public static function measure(string $code, Closure $callback, array $input = [], bool $debug = false, ?string $channel = null): mixed
    {
        $started = hrtime(true);

        try {
            $value = $callback();
        } catch (Throwable $e) {
            self::write(self::FAILED, $code, 'exception', $input, null, [], self::elapsedMs($started), $e, false, $channel);

            throw $e;
        }

        self::write(self::OK, $code, null, $input, null, [], self::elapsedMs($started), null, $debug, $channel);

        return $value;
    }

    /**
     * Seam de tests: recibe cada línea ANTES de la redacción y sin depender
     * del dispatcher de eventos (sobrevive a `Event::fake()`).
     *
     * @param  Closure(array{level: string, code: string, context: array<string, mixed>, channel: ?string}): void  $listener
     */
    public static function listen(Closure $listener): void
    {
        self::$listeners[] = $listener;
    }

    public static function flushListeners(): void
    {
        self::$listeners = [];
    }

    public static function elapsedMs(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1_000_000);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     * @param  array<string, mixed>  $result
     */
    private static function write(string $outcome, string $code, ?string $reason, array $input, ?array $calc, array $result, ?int $durationMs, ?Throwable $error, bool $debug, ?string $channel): void
    {
        $violation = self::violation($outcome, $code, $reason);

        if ($violation !== null && ! app()->environment('production')) {
            throw new InvalidArgumentException("SystemLog: {$violation} [{$code}]");
        }

        $context = array_filter([
            'outcome' => $outcome,
            'reason' => $reason,
            'input' => $input,
            'calc' => $calc,
            'result' => $result,
            'duration_ms' => $durationMs,
            'error' => $error !== null ? SafeException::describe($error) : null,
            'schema_violation' => $violation !== null ? true : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $level = match ($outcome) {
            self::FAILED => 'error',
            self::DEGRADED => 'warning',
            default => $debug ? 'debug' : 'info',
        };

        foreach (self::$listeners as $listener) {
            $listener(['level' => $level, 'code' => $code, 'context' => $context, 'channel' => $channel]);
        }

        if ($channel === null) {
            Log::log($level, $code, $context);

            return;
        }

        Log::channel($channel)->log($level, $code, $context);
    }

    private static function violation(string $outcome, string $code, ?string $reason): ?string
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return 'código inválido, se espera dominio.etapa.resultado';
        }

        if ($outcome !== self::OK && ($reason === null || preg_match('/^[a-z0-9_]+$/', $reason) !== 1)) {
            return 'reason obligatorio en snake_case cuando outcome ≠ ok';
        }

        return null;
    }
}
```

`tests/Concerns/AssertsSystemLog.php`:

```php
<?php

namespace Tests\Concerns;

use App\Support\RedactSensitiveLogData;
use App\Support\SystemLog;
use Closure;

/**
 * Captura las líneas de SystemLog del test (antes de la redacción) para
 * afirmar la narrativa: qué código, con qué reason y qué campos de calc.
 */
trait AssertsSystemLog
{
    /**
     * @var list<array{level: string, code: string, context: array<string, mixed>, channel: ?string}>
     */
    private array $capturedSystemLog = [];

    protected function setUpAssertsSystemLog(): void
    {
        $this->capturedSystemLog = [];
        SystemLog::flushListeners();
        SystemLog::listen(function (array $entry): void {
            $this->capturedSystemLog[] = $entry;
        });
    }

    protected function tearDownAssertsSystemLog(): void
    {
        SystemLog::flushListeners();
    }

    /**
     * @return list<array{level: string, code: string, context: array<string, mixed>, channel: ?string}>
     */
    protected function systemLogEntries(?string $code = null): array
    {
        return array_values(array_filter(
            $this->capturedSystemLog,
            static fn (array $entry): bool => $code === null || $entry['code'] === $code,
        ));
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $where
     * @return array<string, mixed> el contexto de la primera línea que cumple
     */
    protected function assertSystemLogged(string $code, ?Closure $where = null): array
    {
        foreach ($this->systemLogEntries($code) as $entry) {
            if ($where === null || $where($entry['context'])) {
                $this->addToAssertionCount(1);

                return $entry['context'];
            }
        }

        $seen = implode(', ', array_unique(array_column($this->capturedSystemLog, 'code')));
        $this->fail("No se registró [{$code}]".($where !== null ? ' con la condición dada' : '').". Registrados: [{$seen}]");
    }

    protected function assertSystemNotLogged(string $code): void
    {
        $this->assertSame([], $this->systemLogEntries($code), "Se registró [{$code}] y no debía.");
    }

    protected function assertNoSensitiveDataLogged(): void
    {
        foreach ($this->capturedSystemLog as $entry) {
            $findings = RedactSensitiveLogData::findings($entry['context']);

            $this->assertSame([], $findings, "[{$entry['code']}] recibió datos sensibles en: ".implode(', ', $findings));
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/SystemLogTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/SystemLog.php tests/Concerns/AssertsSystemLog.php tests/Feature/Support/SystemLogTest.php
git commit -m "feat: api systemlog con esquema narrativo fijo y helper de aserción"
```

---

### Task 3: Configuración de canales

**Files:**
- Modify: `config/logging.php` (todos los canales; `json`; `telematics`)
- Modify: `.env.example:24-28`
- Test: `tests/Feature/Support/LoggingChannelsTest.php`

**Interfaces:**
- Consumes: `RedactLogChannel` (Task 1), `SystemLog` (Task 2).
- Produces: el canal `json` escribe en `storage/logs/system.json` (diario) o a stderr con `LOG_JSON_STDERR=true`; el canal `telematics` escribe JSON; todos llevan `tap => [RedactLogChannel::class]`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/LoggingChannelsTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Support\PipelineTrace;
use App\Support\RedactLogChannel;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class LoggingChannelsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/logging-'.bin2hex(random_bytes(4)).'.json');
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastLine(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) File::get($this->path))));

        return json_decode(end($lines), true);
    }

    public function test_every_configured_channel_is_tapped_with_redaction(): void
    {
        foreach (config('logging.channels') as $name => $channel) {
            if (in_array($channel['driver'] ?? null, ['stack'], true)) {
                continue;
            }

            $this->assertContains(RedactLogChannel::class, $channel['tap'] ?? [], "El canal [{$name}] no redacta.");
        }
    }

    public function test_the_default_stack_includes_json(): void
    {
        // El default del archivo, no el del .env local de quien corre el test.
        $this->assertStringContainsString("env('LOG_STACK', 'single,json')", (string) File::get(config_path('logging.php')));
        $this->assertSame(storage_path('logs/system.json'), config('logging.channels.json.path'));
    }

    public function test_json_line_carries_the_trace_and_is_redacted_after_the_context_is_added(): void
    {
        config(['logging.channels.json.path' => $this->path, 'logging.channels.json.driver' => 'single']);

        $trace = PipelineTrace::begin(42, 'samsara');
        Context::add('phone', '+525512345678');

        Log::channel('json')->warning('llamada a +525512345678', [
            'recipient' => ['email' => 'a@b.co', 'user_id' => 3],
            'exception' => new RuntimeException('GET https://x.s3/a.jpg?sig=secret'),
        ]);

        $line = $this->lastLine();
        $json = (string) json_encode($line);

        $this->assertSame($trace, $line['extra']['trace_id']);
        $this->assertSame(42, $line['extra']['team_id']);
        $this->assertSame('[redacted]', $line['extra']['phone']);
        $this->assertSame('llamada a [phone]', $line['message']);
        $this->assertSame(['email' => '[redacted]', 'user_id' => 3], $line['context']['recipient']);
        $this->assertSame(RuntimeException::class, $line['context']['exception']['class']);
        $this->assertStringNotContainsString('5512345678', $json);
        $this->assertStringNotContainsString('sig=secret', $json);
        $this->assertStringNotContainsString('a@b.co', $json);
    }

    public function test_telematics_channel_writes_redacted_json(): void
    {
        config(['logging.channels.telematics.path' => $this->path, 'logging.channels.telematics.driver' => 'single']);

        SystemLog::ok('telematics.cycle.completed', input: ['integration_id' => 1, 'note' => 'ana@x.com'], channel: 'telematics');

        $line = $this->lastLine();

        $this->assertSame('telematics.cycle.completed', $line['message']);
        $this->assertSame('[email]', $line['context']['input']['note']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Support/LoggingChannelsTest.php`
Expected: FAIL (`El canal [single] no redacta.` y el path `pipeline.json`).

- [ ] **Step 3: Implement**

En `config/logging.php`:
- Añade `use App\Support\RedactLogChannel;` arriba.
- Añade `'tap' => [RedactLogChannel::class],` a **cada** canal de `channels` (incluidos `stack`, `single`, `daily`, `json`, `telematics`, `slack`, `papertrail`, `stderr`, `syslog`, `errorlog`, `null` y `emergency`, si existe dentro de `channels`). `emergency` suele estar fuera de `channels` con solo `path`: déjalo así.
- Cambia el default del stack: `explode(',', (string) env('LOG_STACK', 'single,json'))`.
- Sustituye el bloque `json` por:

```php
        // Log narrativo del sistema (App\Support\SystemLog): una línea JSON por
        // decisión, con el Context en `extra` (trace_id, team_id, ids de etapa).
        // Seguir un evento: `jq 'select(.extra.trace_id == "<id>")' storage/logs/system-*.json`.
        // Catálogo de códigos: docs/SAM/logging.md. Con LOG_JSON_STDERR=true va a
        // stderr (para un agregador) en lugar de a archivo.
        'json' => env('LOG_JSON_STDERR', false) ? [
            'driver' => 'monolog',
            'level' => env('LOG_JSON_LEVEL', env('LOG_LEVEL', 'debug')),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ] : [
            'driver' => 'daily',
            'path' => storage_path('logs/system.json'),
            'level' => env('LOG_JSON_LEVEL', env('LOG_LEVEL', 'debug')),
            'days' => env('LOG_JSON_DAYS', 7),
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ],
```

- Sustituye el bloque `telematics` por:

```php
        // Telemática: alto volumen (resumen por ciclo cada 5 s por tenant), en
        // su propio archivo, en JSON con el mismo esquema de SystemLog.
        'telematics' => [
            'driver' => 'daily',
            'path' => storage_path('logs/telematics.json'),
            'level' => env('LOG_TELEMATICS_LEVEL', 'info'),
            'days' => env('LOG_TELEMATICS_DAYS', 7),
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ],
```

En `.env.example`, reemplaza las líneas 25–26 por:

```
LOG_STACK=single,json
# `json` = log narrativo del sistema (storage/logs/system-*.json, con trace_id). Catálogo: docs/SAM/logging.md
# LOG_JSON_STDERR=true lo manda a stderr para un agregador.
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/LoggingChannelsTest.php tests/Feature/Support/PipelineTraceTest.php`
Expected: PASS. `PipelineTraceTest` ya sobrescribe el path del canal `json`, así que no se rompe.

- [ ] **Step 5: Commit**

```bash
git add config/logging.php .env.example tests/Feature/Support/LoggingChannelsTest.php
git commit -m "feat: canal json por defecto, telemática en json y redacción en todos los canales"
```

---

### Task 4: Red automática — colas, HTTP saliente y auth

**Files:**
- Create: `app/Support/AutomaticSystemLog.php`
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`)
- Test: `tests/Feature/Support/AutomaticSystemLogTest.php`

**Interfaces:**
- Consumes: `SystemLog` (Task 2).
- Produces:
  - `AutomaticSystemLog::register(): void`, que emite:
    - `queue.job.finished` (ok): `input{job, queue, connection, attempt}`, `duration_ms`. Nivel `debug` si la cola es `telematics`;
    - `queue.job.released` (skipped, `released`);
    - `queue.job.attempt_failed` (degraded, `exception`, `input.max_tries`);
    - `queue.job.failed` (failed, `exception` \| `max_attempts_exceeded` \| `timeout`);
    - `http.client.request.completed` (ok si 2xx/3xx; degraded `http_error` si no): `input{provider, method, host, path, status}`, `duration_ms`;
    - `http.client.request.failed` (failed, `connection_failed`);
    - `auth.login.succeeded`, `auth.login.failed` (skipped, `invalid_credentials`), `auth.login.locked_out` (degraded, `too_many_attempts`), `auth.logout.succeeded`, `auth.password.reset`.
  - Campos de auth: `input{user_id, guard, login_fingerprint}`, donde `login_fingerprint` son los 12 primeros caracteres del sha256 del identificador en minúsculas. Nunca el email.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/AutomaticSystemLogTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AutomaticSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_a_finished_job_is_logged_with_duration_and_attempt(): void
    {
        AutomaticSystemLogOkJob::dispatch();

        $ctx = $this->assertSystemLogged('queue.job.finished', fn (array $c) => $c['input']['job'] === AutomaticSystemLogOkJob::class);

        $this->assertSame(1, $ctx['input']['attempt']);
        $this->assertArrayHasKey('duration_ms', $ctx);
    }

    public function test_a_failing_job_logs_the_attempt_and_the_failure_safely(): void
    {
        try {
            AutomaticSystemLogFailingJob::dispatch();
        } catch (RuntimeException) {
        }

        $this->assertSystemLogged('queue.job.attempt_failed', fn (array $c) => $c['error']['message'] === 'llamada a [phone] falló');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_outgoing_http_is_logged_without_query_headers_or_body(): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            'api.twilio.com/*' => Http::response('nope', 500),
        ]);

        Http::withToken('secret-token')->get('https://api.samsara.com/fleet/vehicles/stats?types=gps&after=abc');
        Http::post('https://api.twilio.com/2010-04-01/Calls.json', ['To' => '+525512345678']);

        $ok = $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['provider'] === 'samsara');
        $this->assertSame(['provider' => 'samsara', 'method' => 'GET', 'host' => 'api.samsara.com', 'path' => '/fleet/vehicles/stats', 'status' => 200], $ok['input']);
        $this->assertSame('ok', $ok['outcome']);

        $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['provider'] === 'twilio' && $c['outcome'] === 'degraded' && $c['reason'] === 'http_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_connection_failures_are_logged(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 10.0.0.1'));

        try {
            Http::get('https://api.openai.com/v1/responses');
        } catch (ConnectionException) {
        }

        $this->assertSystemLogged('http.client.request.failed', fn (array $c) => $c['reason'] === 'connection_failed' && $c['input']['provider'] === 'openai');
    }

    public function test_auth_events_never_log_the_email(): void
    {
        $user = User::factory()->create(['email' => 'ana@empresa.com']);

        event(new Failed('web', null, ['email' => 'Ana@Empresa.com', 'password' => 'x']));
        event(new Lockout(Request::create('/login', 'POST', ['email' => 'ana@empresa.com'])));
        event(new Login('web', $user, false));

        $failed = $this->assertSystemLogged('auth.login.failed');
        $this->assertSame('invalid_credentials', $failed['reason']);
        $this->assertSame(substr(hash('sha256', 'ana@empresa.com'), 0, 12), $failed['input']['login_fingerprint']);

        $this->assertSystemLogged('auth.login.locked_out', fn (array $c) => $c['input']['login_fingerprint'] === $failed['input']['login_fingerprint']);
        $this->assertSystemLogged('auth.login.succeeded', fn (array $c) => $c['input']['user_id'] === $user->id);
        $this->assertNoSensitiveDataLogged();
    }
}

class AutomaticSystemLogOkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void {}
}

class AutomaticSystemLogFailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('llamada a +525512345678 falló');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Support/AutomaticSystemLogTest.php`
Expected: FAIL (`No se registró [queue.job.finished]`).

- [ ] **Step 3: Implement**

`app/Support/AutomaticSystemLog.php`:

```php
<?php

namespace App\Support;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;

/**
 * Red de fondo del log narrativo: lo que se registra sin que cada módulo lo
 * pida. Ciclo de vida de cada job, cada llamada HTTP saliente (sin query,
 * headers ni body) y los eventos de autenticación (sin el email).
 */
final class AutomaticSystemLog
{
    /**
     * Sufijo de host → proveedor, para filtrar las llamadas por proveedor.
     *
     * @var array<string, string>
     */
    private const array PROVIDERS = [
        'samsara.com' => 'samsara',
        'twilio.com' => 'twilio',
        'openai.com' => 'openai',
        'anthropic.com' => 'anthropic',
        'slack.com' => 'slack',
        'amazonaws.com' => 's3',
    ];

    /**
     * @var array<int, int> spl_object_id(job) → hrtime de inicio
     */
    private static array $startedAt = [];

    public static function register(): void
    {
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            self::$startedAt[spl_object_id($event->job)] = hrtime(true);
        });

        Event::listen(JobProcessed::class, static function (JobProcessed $event): void {
            $input = self::jobInput($event->job);
            $duration = self::jobDuration($event->job);

            if ($event->job->isReleased()) {
                SystemLog::skipped('queue.job.released', reason: 'released', input: $input, debug: self::isHotQueue($event->job));

                return;
            }

            SystemLog::ok('queue.job.finished', input: $input, durationMs: $duration, debug: self::isHotQueue($event->job));
        });

        Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
            SystemLog::degraded('queue.job.attempt_failed', reason: 'exception', input: self::jobInput($event->job) + [
                'max_tries' => $event->job->maxTries(),
            ], error: $event->exception);
        });

        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            $reason = match (true) {
                $event->exception instanceof MaxAttemptsExceededException => 'max_attempts_exceeded',
                $event->exception instanceof TimeoutExceededException => 'timeout',
                default => 'exception',
            };

            SystemLog::failed('queue.job.failed', reason: $reason, input: self::jobInput($event->job), error: $event->exception);
            unset(self::$startedAt[spl_object_id($event->job)]);
        });

        Event::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            $input = self::httpInput($event->request->url(), $event->request->method()) + ['status' => $event->response->status()];
            $seconds = $event->response->handlerStats()['total_time'] ?? null;
            $duration = is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;

            if ($event->response->successful() || $event->response->redirect()) {
                SystemLog::ok('http.client.request.completed', input: $input, durationMs: $duration);

                return;
            }

            SystemLog::degraded('http.client.request.completed', reason: 'http_error', input: $input, durationMs: $duration);
        });

        Event::listen(ConnectionFailed::class, static function (ConnectionFailed $event): void {
            SystemLog::failed('http.client.request.failed', reason: 'connection_failed', input: self::httpInput($event->request->url(), $event->request->method()), error: $event->exception);
        });

        Event::listen(Login::class, static fn (Login $event) => SystemLog::ok('auth.login.succeeded', input: ['user_id' => $event->user->getAuthIdentifier(), 'guard' => $event->guard, 'remember' => $event->remember]));
        Event::listen(Logout::class, static fn (Logout $event) => SystemLog::ok('auth.logout.succeeded', input: ['user_id' => $event->user?->getAuthIdentifier(), 'guard' => $event->guard]));
        Event::listen(PasswordReset::class, static fn (PasswordReset $event) => SystemLog::ok('auth.password.reset', input: ['user_id' => $event->user->getAuthIdentifier()]));

        Event::listen(Failed::class, static fn (Failed $event) => SystemLog::skipped('auth.login.failed', reason: 'invalid_credentials', input: [
            'user_id' => $event->user?->getAuthIdentifier(),
            'guard' => $event->guard,
            'login_fingerprint' => self::fingerprint($event->credentials['email'] ?? null),
        ]));

        Event::listen(Lockout::class, static fn (Lockout $event) => SystemLog::degraded('auth.login.locked_out', reason: 'too_many_attempts', input: [
            'route_name' => $event->request->route()?->getName(),
            'login_fingerprint' => self::fingerprint($event->request->input('email')),
        ]));
    }

    /**
     * @return array{job: string, queue: ?string, connection: ?string, attempt: int}
     */
    private static function jobInput(Job $job): array
    {
        return [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'attempt' => $job->attempts(),
        ];
    }

    private static function jobDuration(Job $job): ?int
    {
        $started = self::$startedAt[spl_object_id($job)] ?? null;
        unset(self::$startedAt[spl_object_id($job)]);

        return $started === null ? null : SystemLog::elapsedMs($started);
    }

    private static function isHotQueue(Job $job): bool
    {
        return $job->getQueue() === 'telematics';
    }

    /**
     * @return array{provider: string, method: string, host: string, path: string}
     */
    private static function httpInput(string $url, string $method): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $provider = 'other';

        foreach (self::PROVIDERS as $suffix => $name) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $provider = $name;

                break;
            }
        }

        return [
            'provider' => $provider,
            'method' => strtoupper($method),
            'host' => $host,
            'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
        ];
    }

    private static function fingerprint(mixed $identifier): ?string
    {
        return is_string($identifier) && $identifier !== ''
            ? substr(hash('sha256', strtolower(trim($identifier))), 0, 12)
            : null;
    }
}
```

Nota: Twilio y el SDK de IA, si no usan el cliente `Http` de Laravel, no aparecen aquí; la fase 6 los envuelve con `SystemLog::measure`. En Samsara, `path` lleva ids de vehículo, que no son PII.

En `app/Providers/AppServiceProvider.php`, dentro de `boot()`, después de `$this->configurePipelineTrace();`:

```php
        AutomaticSystemLog::register();
```

Añade `use App\Support\AutomaticSystemLog;`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/AutomaticSystemLogTest.php`
Expected: PASS.

Notas si algo falla:
- Si la cola `sync` no dispara `JobProcessing`/`JobProcessed` en tests, comprueba `config('queue.default')` en `phpunit.xml`. Si es `sync`, Laravel 13 sí dispara esos eventos desde `SyncQueue`.
- Si `JobExceptionOccurred` no se dispara en `sync`, el test de fallo debe afirmar `queue.job.failed` en su lugar. Registra en el commit cuál se observó.

- [ ] **Step 5: Commit**

```bash
git add app/Support/AutomaticSystemLog.php app/Providers/AppServiceProvider.php tests/Feature/Support/AutomaticSystemLogTest.php
git commit -m "feat: log automático de jobs, http saliente y autenticación"
```

---

### Task 5: Peticiones denegadas y contexto de excepciones

**Files:**
- Create: `app/Support/DeniedRequestLog.php`
- Modify: `bootstrap/app.php:50-69` (`withExceptions`)
- Test: `tests/Feature/Support/DeniedRequestLogTest.php`

**Interfaces:**
- Consumes: `SystemLog`.
- Produces:
  - `DeniedRequestLog::record(Throwable $e, Request $request, int $status): void`, que emite:
    - `http.request.denied`: degraded, `reason` ∈ `unauthenticated` (401) \| `forbidden` (403) \| `csrf_mismatch` (419);
    - `http.request.throttled`: degraded, `rate_limited`, 429;
    - `http.request.not_found`: degraded, `unknown_endpoint`, solo si el path es `webhooks/*`.
  - `input{method, route_name, route_uri, status, exception, user_id, team_id}`, donde `route_uri` es la plantilla de la ruta (`webhooks/{endpoint}`), nunca el path real.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/DeniedRequestLogTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DeniedRequestLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403))->name('test.forbidden');
        Route::middleware('web')->get('/_test/throttled', fn () => abort(429))->name('test.throttled');
        Route::middleware('web')->get('/_test/ok', fn () => 'ok')->name('test.ok');
    }

    public function test_forbidden_requests_are_logged_with_the_user_and_route_template(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/forbidden')->assertForbidden();

        $ctx = $this->assertSystemLogged('http.request.denied');
        $this->assertSame('forbidden', $ctx['reason']);
        $this->assertSame('test.forbidden', $ctx['input']['route_name']);
        $this->assertSame($user->id, $ctx['input']['user_id']);
        $this->assertSame(403, $ctx['input']['status']);
    }

    public function test_throttled_requests_are_logged(): void
    {
        $this->get('/_test/throttled')->assertStatus(429);

        $this->assertSystemLogged('http.request.throttled', fn (array $c) => $c['reason'] === 'rate_limited');
    }

    public function test_unknown_webhook_endpoints_are_logged_without_the_real_path(): void
    {
        $this->postJson('/api/webhooks/00000000-0000-0000-0000-000000000000', [])->assertNotFound();

        $ctx = $this->assertSystemLogged('http.request.not_found');
        $this->assertSame('unknown_endpoint', $ctx['reason']);
        $this->assertStringNotContainsString('00000000-0000', (string) json_encode($ctx));
    }

    public function test_ordinary_404s_and_successes_are_not_logged_as_security_events(): void
    {
        $this->get('/_test/does-not-exist')->assertNotFound();
        $this->get('/_test/ok')->assertOk();

        $this->assertSystemNotLogged('http.request.not_found');
        $this->assertSystemNotLogged('http.request.denied');
    }
}
```

La ruta real es `POST api/webhooks/{endpoint_url}` (`webhooks.handle`); un `endpoint_url` inexistente da 404 por `firstOrFail`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Support/DeniedRequestLogTest.php`
Expected: FAIL (`No se registró [http.request.denied]`).

- [ ] **Step 3: Implement**

`app/Support/DeniedRequestLog.php`:

```php
<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Peticiones rechazadas que Laravel no reporta por defecto (401/403/419/429
 * y el 404 de un endpoint de webhook desconocido): son eventos de seguridad.
 * Sólo la plantilla de la ruta, nunca el path real (lleva ids de endpoint).
 */
final class DeniedRequestLog
{
    public static function record(Throwable $e, Request $request, int $status): void
    {
        if ($e instanceof AuthenticationException) {
            $status = 401;
        }

        [$code, $reason] = match (true) {
            $status === 401 => ['http.request.denied', 'unauthenticated'],
            $status === 403 => ['http.request.denied', 'forbidden'],
            $status === 419 => ['http.request.denied', 'csrf_mismatch'],
            $status === 429 => ['http.request.throttled', 'rate_limited'],
            $status === 404 && $request->is('api/webhooks/*') => ['http.request.not_found', 'unknown_endpoint'],
            default => [null, null],
        };

        if ($code === null) {
            return;
        }

        $user = $request->user();

        SystemLog::degraded($code, reason: $reason, input: [
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'status' => $status,
            'exception' => $e::class,
            'user_id' => $user?->getAuthIdentifier(),
            'team_id' => TenantContext::id() ?? $user?->current_team_id,
        ]);
    }
}
```

En `bootstrap/app.php`, dentro de `withExceptions`:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo reporte de excepción lleva quién y dónde (nunca el payload).
        $exceptions->context(fn (): array => array_filter([
            'user_id' => request()?->user()?->getAuthIdentifier(),
            'route_name' => request()?->route()?->getName(),
        ]));

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            DeniedRequestLog::record($exception, $request, $status);

            // ... (el resto del closure existente sin cambios)
```

Añade `use App\Support\DeniedRequestLog;` arriba.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/DeniedRequestLogTest.php`
Expected: PASS. Si `AuthenticationException` en web redirige (302) y no pasa por `respond` con 401, el caso `unauthenticated` queda para JSON/API; no lo fuerces.

- [ ] **Step 5: Commit**

```bash
git add app/Support/DeniedRequestLog.php bootstrap/app.php tests/Feature/Support/DeniedRequestLogTest.php
git commit -m "feat: registrar peticiones denegadas, limitadas y webhooks desconocidos"
```

---

### Task 6: `JobFailureReporter` y `ObjectStorageFailure` sobre `SystemLog`

**Files:**
- Modify: `app/Support/JobFailureReporter.php`
- Modify: `app/Support/ObjectStorageFailure.php`
- Modify: `tests/Feature/Support/JobFailureReporterTest.php`
- Modify: `tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php:114-140`
- Modify: `tests/Feature/Domains/Analytics/ReportDownloadStorageFailureTest.php` (el bloque con `Log::`)

**Interfaces:**
- Produces:
  - `JobFailureReporter::report(string $jobClass, Throwable $e, array $context = [])` (misma firma) emite `SystemLog::failed(JobFailureReporter::codeFor($jobClass), reason: 'exception', input: ['job' => $jobClass] + $context, error: $e)`;
  - `JobFailureReporter::codeFor(string $jobClass): string`: `App\Domains\Ingestion\Jobs\ProcessRawEventJob` → `ingestion.process_raw_event.failed`; una clase fuera de `App\Domains` → `app.{snake sin sufijo Job}.failed`;
  - `ObjectStorageFailure::report($operation, $e, $context)` (misma firma) emite `SystemLog::failed('storage.object.operation_failed', reason: 'storage_unavailable', input: ['operation' => $operation] + $context, error: $e)`.

- [ ] **Step 1: Rewrite the tests (they must fail against the old code)**

`tests/Feature/Support/JobFailureReporterTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Support\JobFailureReporter;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class JobFailureReporterTest extends TestCase
{
    use AssertsSystemLog;

    public function test_reports_at_error_level_with_context(): void
    {
        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('boom'), ['team_id' => 7]);

        $ctx = $this->assertSystemLogged('app.fake.failed');

        $this->assertSame('error', $this->systemLogEntries('app.fake.failed')[0]['level']);
        $this->assertSame('exception', $ctx['reason']);
        $this->assertSame('App\\Jobs\\Fake', $ctx['input']['job']);
        $this->assertSame(7, $ctx['input']['team_id']);
        $this->assertSame(RuntimeException::class, $ctx['error']['class']);
        $this->assertStringContainsString('boom', $ctx['error']['message']);
    }

    public function test_the_code_is_derived_from_the_domain_and_job_name(): void
    {
        $this->assertSame('ingestion.process_raw_event.failed', JobFailureReporter::codeFor('App\Domains\Ingestion\Jobs\ProcessRawEventJob'));
        $this->assertSame('ai.evaluate_event_media.failed', JobFailureReporter::codeFor('App\Domains\AI\Jobs\EvaluateEventMediaJob'));
        $this->assertSame('tenant_config.apply_defaults.failed', JobFailureReporter::codeFor('App\Domains\TenantConfig\Jobs\ApplyDefaultsJob'));
    }

    public function test_the_raw_message_never_reaches_the_log(): void
    {
        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('to +525512345678'));

        $this->assertSame('to [phone]', $this->assertSystemLogged('app.fake.failed')['error']['message']);
    }
}
```

En `InvoicePaymentLifecycleTest` y `ReportDownloadStorageFailureTest`:
- añade `use Tests\Concerns\AssertsSystemLog;` y `use AssertsSystemLog;`;
- sustituye `Log::spy();` (bórralo) y el `Log::shouldHaveReceived('error')->withArgs(...)` por `$this->assertSystemLogged('storage.object.operation_failed', fn (array $c) => $c['input']['operation'] === '<la operación que afirmaba el test>' && …)`, conservando **cada** condición que afirmaba el `withArgs` original (mismo operation, mismos ids de contexto, misma clase de excepción vía `$c['error']['class']`);
- quita el `use Illuminate\Support\Facades\Log;` si queda sin uso.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Support/JobFailureReporterTest.php tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php tests/Feature/Domains/Analytics/ReportDownloadStorageFailureTest.php`
Expected: FAIL (`No se registró [app.fake.failed]` / `[storage.object.operation_failed]`).

- [ ] **Step 3: Implement**

`app/Support/JobFailureReporter.php`:

```php
<?php

namespace App\Support;

use Throwable;

/**
 * Fallo definitivo de un job con su contexto de dominio (ids del recurso).
 * Además de la línea genérica `queue.job.failed` (AutomaticSystemLog), deja
 * `{dominio}.{job}.failed` para filtrar por módulo.
 */
final class JobFailureReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(string $jobClass, Throwable $e, array $context = []): void
    {
        SystemLog::failed(self::codeFor($jobClass), reason: 'exception', input: ['job' => $jobClass] + $context, error: $e);
    }

    public static function codeFor(string $jobClass): string
    {
        $job = self::snake((string) preg_replace('/Job$/', '', class_basename($jobClass)));
        $domain = preg_match('/^App\\\\Domains\\\\([^\\\\]+)\\\\/', $jobClass, $match) === 1
            ? self::snake($match[1])
            : 'app';

        return "{$domain}.{$job}.failed";
    }

    /**
     * `AI` → `ai`, `TenantConfig` → `tenant_config`, `ApplyAIProfile` →
     * `apply_ai_profile` (Str::snake daría `a_i`).
     */
    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
    }
}
```

`app/Support/ObjectStorageFailure.php`: sustituye el método `report` por:

```php
    /**
     * @param  array<string, int|string|null>  $context
     */
    public static function report(string $operation, Throwable $e, array $context = []): void
    {
        SystemLog::failed('storage.object.operation_failed', reason: 'storage_unavailable', input: ['operation' => $operation] + $context, error: $e);
    }
```

Quita el `use Illuminate\Support\Facades\Log;`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Support/JobFailureReporterTest.php tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php tests/Feature/Domains/Analytics/ReportDownloadStorageFailureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/JobFailureReporter.php app/Support/ObjectStorageFailure.php tests/Feature/Support/JobFailureReporterTest.php tests/Feature/Domains/Tenancy/InvoicePaymentLifecycleTest.php tests/Feature/Domains/Analytics/ReportDownloadStorageFailureTest.php
git commit -m "refactor: jobfailurereporter y objectstoragefailure escriben con systemlog"
```

---

### Task 7: Migrar los logs del pipeline y cerrar sus fugas

**Files (Modify):**
- `app/Domains/Ingestion/Actions/IngestSafetyEvent.php:157`
- `app/Domains/Ingestion/Jobs/PollSafetyEventsJob.php:167`
- `app/Domains/Context/Support/VideoFrameExtractor.php:86`
- `app/Domains/Context/Jobs/ExtractVideoFramesJob.php:94,126,241`
- `app/Domains/Context/Jobs/EnrichContextJob.php:67`
- `app/Domains/Context/Jobs/ExtractEventMediaJob.php:66,74`
- `app/Domains/Context/Jobs/FetchDeferredEventMediaJob.php:765,893,938`
- `app/Domains/Normalization/Jobs/NormalizeEventJob.php:73`
- `app/Domains/Integrations/Adapters/SamsaraAdapter.php:583,593,623,632,694,703`
- `app/Domains/AI/Actions/EvaluateEventWithAI.php:129`
- `app/Domains/AI/Actions/EvaluateEventMultimodally.php:126,145,156,170,187,198,327`
- `app/Domains/AI/Jobs/EvaluateEventJob.php:75`, `EvaluateEventMediaJob.php:80`, `ReevaluateEventJob.php:83`
- Test: `tests/Feature/Domains/Context/ExtractVideoFramesJobTest.php:177-184`, `tests/Feature/Support/PipelineLogMigrationTest.php` (nuevo)

**Interfaces:**
- Consumes: `SystemLog`, `JobFailureReporter::report`, `RedactSensitiveLogData::sanitize`.
- Produces: los códigos de la tabla, que entran en el catálogo del Task 9.

Tabla de migración. Aplícala literalmente; los ids que ya están en el `Context` de la traza (`raw_event_id`, `normalized_event_id`) se mantienen en `input` igual, porque el log debe entenderse sin traza.

| Sitio | Nueva llamada |
|---|---|
| IngestSafetyEvent:157 | `SystemLog::degraded('ingestion.media.inline_download_failed', reason: 'download_failed', input: ['raw_event_id' => $rawEvent->id, 'url_key' => $metadata['source_url_key'] ?? null], error: $e)` |
| PollSafetyEventsJob:167 | `SystemLog::degraded('ingestion.poll.cursor_rejected', reason: 'provider_rejected_cursor', input: ['integration_id' => $this->integration->id, 'http_status' => $e->status, 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $e->providerMessage), 200), 'restart_from' => $restartFrom])` |
| VideoFrameExtractor:86 | `SystemLog::skipped('media.frames.offset_missing', reason: 'no_frame_at_offset', input: ['offset_seconds' => $offset, 'exit_code' => $result->exitCode(), 'stderr_excerpt' => Str::limit(RedactSensitiveLogData::sanitize($result->errorOutput()), 200)])` |
| ExtractVideoFramesJob:94 | `SystemLog::degraded('media.frames.ffmpeg_unavailable', reason: 'ffmpeg_missing', input: ['media_context_id' => $clip->id, 'ffmpeg_binary' => $extractor->binary()])` |
| ExtractVideoFramesJob:126 | `SystemLog::ok('media.frames.extracted', input: ['media_context_id' => $clip->id], result: ['frames_extracted' => count($frames), 'frames_created' => $created])` |
| ExtractVideoFramesJob:241 | `JobFailureReporter::report(static::class, $exception, ['media_context_id' => $this->mediaContextId])` |
| EnrichContextJob:67 | `JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId])` |
| ExtractEventMediaJob:66 | `SystemLog::ok('media.event_media.extracted', input: ['normalized_event_id' => $this->normalizedEventId], result: ['media_created_count' => $created->count()])` |
| ExtractEventMediaJob:74 | `JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId])` |
| FetchDeferredEventMediaJob:765 | `SystemLog::degraded('media.deferred.download_failed', reason: 'download_failed', input: ['normalized_event_id' => $event->id, 'camera_input' => $item['input']], error: $e)` |
| FetchDeferredEventMediaJob:893 | `SystemLog::skipped('media.deferred.closed_without_media', reason: 'closed_without_media', input: ['event_media_request_id' => $request->id, 'status' => $status->value, 'detail' => $reason])`. `$reason` es hoy una frase fija escrita en el código (sin datos de usuario). La fase 2 la convierte en código. |
| FetchDeferredEventMediaJob:938 | `JobFailureReporter::report(static::class, $exception, ['event_media_request_id' => $this->eventMediaRequestId])` |
| NormalizeEventJob:73 | `JobFailureReporter::report(static::class, $exception, ['raw_event_id' => $this->rawEventId])` |
| SamsaraAdapter:583 | `SystemLog::degraded('samsara.media_retrieval.request_failed', reason: 'connection_failed', input: ['vehicle_id' => $externalAssetId, 'media_type' => $mediaType], error: $e)` |
| SamsaraAdapter:593 | `SystemLog::degraded('samsara.media_retrieval.request_failed', reason: 'provider_rejected', input: ['vehicle_id' => $externalAssetId, 'media_type' => $mediaType, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')])` |
| SamsaraAdapter:623 | `SystemLog::degraded('samsara.media_retrieval.poll_failed', reason: 'connection_failed', input: ['retrieval_id' => $retrievalId], error: $e)` |
| SamsaraAdapter:632 | `SystemLog::degraded('samsara.media_retrieval.poll_failed', reason: 'provider_rejected', input: ['retrieval_id' => $retrievalId, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')])` |
| SamsaraAdapter:694 | `SystemLog::degraded('samsara.uploaded_media.listing_failed', reason: 'connection_failed', input: ['vehicle_id' => $externalAssetId], error: $e)` |
| SamsaraAdapter:703 | `SystemLog::degraded('samsara.uploaded_media.listing_failed', reason: 'provider_rejected', input: ['vehicle_id' => $externalAssetId, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')])` |
| EvaluateEventWithAI:129 | `SystemLog::degraded('ai.evaluation.rules_only', reason: 'agent_error', input: ['normalized_event_id' => $event->id], error: $exception)` |
| EvaluateEventMultimodally:126 | `SystemLog::skipped('ai.media.assessment_skipped', reason: 'in_progress', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id])` |
| EvaluateEventMultimodally:145 | `SystemLog::skipped('ai.media.assessment_skipped', reason: 'image_cap_reached', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], calc: ['max_images_per_event' => $this->maxImagesPerEvent()])` |
| EvaluateEventMultimodally:156 | `SystemLog::skipped('ai.media.assessment_skipped', reason: 'quota_exceeded', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id])` |
| EvaluateEventMultimodally:170 | `SystemLog::skipped('ai.media.assessment_skipped', reason: 'file_missing', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], result: ['error_class' => $exception::class])` |
| EvaluateEventMultimodally:187 | `SystemLog::degraded('ai.media.assessment_retry', reason: 'transient_failure', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], error: $exception)` |
| EvaluateEventMultimodally:198 | `SystemLog::degraded('ai.media.assessment_unavailable', reason: 'agent_error', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], error: $exception)` |
| EvaluateEventMultimodally:327 | `SystemLog::skipped('ai.media.assessment_rejected', reason: 'rejected_before_model', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id, 'rejection' => (string) $exception->reason])` |
| EvaluateEventJob:75 | `JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId])` |
| EvaluateEventMediaJob:80 | `JobFailureReporter::report(static::class, $exception, ['evaluation_id' => $this->evaluationId, 'media_context_ids' => $this->mediaContextIds])` |
| ReevaluateEventJob:83 | `JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId, 'trigger_type' => $this->triggerType])` |

En cada archivo:
- añade `use App\Support\SystemLog;` (o `JobFailureReporter` o `RedactSensitiveLogData`, según corresponda) y `use Illuminate\Support\Str;` si se usa `Str::limit`;
- quita `use Illuminate\Support\Facades\Log;` cuando quede sin uso;
- si `$exception->reason` en `EvaluateEventMultimodally:327` es un enum, usa `->value` en lugar del cast a string.

- [ ] **Step 1: Write the failing tests**

En `tests/Feature/Domains/Context/ExtractVideoFramesJobTest.php`:
- añade `use Tests\Concerns\AssertsSystemLog;` y `use AssertsSystemLog;`;
- borra `Log::spy();` (línea 177);
- sustituye la línea 184 por:

```php
        $this->assertSystemLogged('media.frames.ffmpeg_unavailable', fn (array $c) => $c['reason'] === 'ffmpeg_missing' && $c['input']['media_context_id'] === $clip->id);
```

La variable del clip en ese test puede llamarse distinto: usa la que el test ya crea.

Crea `tests/Feature/Support/PipelineLogMigrationTest.php`. Fija, sobre el camino real del adaptador con `Http::fake`, que el rechazo de Samsara se registra con código y con el texto del proveedor saneado. El builder de la integración copia el de `SamsaraAdapterLiveLocationTest`:

```php
<?php

namespace Tests\Feature\Support;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PipelineLogMigrationTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function makeIntegration(): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    public function test_a_rejected_samsara_media_request_is_logged_with_codes_and_sanitized_provider_text(): void
    {
        Http::fake(['api.samsara.com/cameras/media/retrieval*' => Http::response(['message' => 'bad vehicle, contact ops@samsara.com', 'requestId' => 'req-1'], 400)]);

        $retrievalId = app(SamsaraAdapter::class)->requestMedia($this->makeIntegration(), '100', now()->subMinutes(2), now()->subMinute());

        $this->assertNull($retrievalId);
        $ctx = $this->assertSystemLogged('samsara.media_retrieval.request_failed', fn (array $c) => $c['reason'] === 'provider_rejected');
        $this->assertSame(400, $ctx['input']['http_status']);
        $this->assertSame('bad vehicle, contact [email]', $ctx['input']['provider_message']);
        $this->assertSame('req-1', $ctx['input']['provider_request_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_connection_failure_is_logged_with_a_safe_error_instead_of_the_raw_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.samsara.com/cameras/media/retrieval?token=abc'));

        app(SamsaraAdapter::class)->requestMedia($this->makeIntegration(), '100', now()->subMinutes(2), now()->subMinute());

        $ctx = $this->assertSystemLogged('samsara.media_retrieval.request_failed', fn (array $c) => $c['reason'] === 'connection_failed');
        $this->assertSame(ConnectionException::class, $ctx['error']['class']);
        $this->assertStringNotContainsString('token=abc', $ctx['error']['message']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Domains/Context/ExtractVideoFramesJobTest.php tests/Feature/Support/PipelineLogMigrationTest.php`
Expected: FAIL (`No se registró [media.frames.ffmpeg_unavailable]` / `[samsara.media_retrieval.request_failed]`).

- [ ] **Step 3: Apply the migration table**

Sustituye cada llamada según la tabla.

- [ ] **Step 4: Run the affected domains**

Run: `php artisan test --compact tests/Feature/Domains/Ingestion tests/Feature/Domains/Context tests/Feature/Domains/Normalization tests/Feature/Domains/AI tests/Feature/Domains/Integrations tests/Feature/Support`
Expected: PASS. Si un test existente fallaba por afirmar el mensaje viejo de un log, actualiza la aserción al código nuevo **conservando lo que afirmaba** (nunca la borres).

- [ ] **Step 5: Commit**

```bash
git add app/Domains/Ingestion app/Domains/Context app/Domains/Normalization app/Domains/Integrations app/Domains/AI tests/Feature/Domains/Context/ExtractVideoFramesJobTest.php tests/Feature/Support/PipelineLogMigrationTest.php
git commit -m "refactor: logs del pipeline con códigos systemlog y sin mensajes crudos"
```

---

### Task 8: Migrar el resto de logs y cerrar las fugas de teléfonos y tokens

**Files (Modify):**
- `app/Domains/Drivers/Exceptions/DriverExternalReferenceConflictException.php:36`
- `app/Domains/Audit/Jobs/WriteAuditLogJob.php:95`
- `app/Domains/Audit/Listeners/AuditAnyDomainEvent.php:36-80`
- `app/Domains/Automation/Actions/ExecuteAction.php:188,348`
- `app/Domains/Automation/Jobs/{RunAutomationWorkflowJob.php:57,RetryActionExecutionJob.php:46,ExecuteActionJob.php:151}`
- `app/Domains/Assets/Jobs/BackfillVehicleStatsJob.php:89`
- `app/Domains/Assets/Jobs/FollowVehicleStatsFeedJob.php:151`
- `app/Domains/Notifications/Support/CancelBlockedNotification.php:37`
- `app/Domains/Notifications/Actions/{RecordMessagingUsage.php:39,RecordMessagingCharge.php:59,DispatchNotification.php:151,ProcessInboundReply.php:42,59,68}`
- `app/Domains/Notifications/Jobs/ReconcileMessagingChargesJob.php:97`
- `app/Domains/Incidents/Actions/StartIncidentCallVerification.php:98`
- `app/Domains/Incidents/Jobs/PlaceVerificationCallJob.php:112,131,159`
- `app/Infrastructure/AI/Agents/SdkCopilotNarrator.php:38`
- Test: `tests/Feature/Domains/Drivers/DriverSyncHandlerServiceTest.php:60-84`, `tests/Feature/Support/ProcessInboundReplyLogTest.php` (nuevo)

**Interfaces:**
- Consumes: `SystemLog`, `JobFailureReporter::report`.
- Produces: los códigos de la tabla.

| Sitio | Nueva llamada |
|---|---|
| DriverExternalReferenceConflictException:36 | `SystemLog::skipped('drivers.sync.external_id_conflict', reason: 'owned_by_other_tenant', input: ['team_id' => $this->teamId, 'provider_id' => $this->providerId, 'external_id' => $this->externalId])` |
| WriteAuditLogJob:95 | `JobFailureReporter::report(static::class, $exception, ['event_name' => $this->eventName, 'team_id' => $this->teamId])`. La `signature` sale del log. |
| AuditAnyDomainEvent `logQuietly` | La firma pasa a `logQuietly(string $reason, string $eventName, Throwable $exception)`. El cuerpo, dentro del `try` existente: `SystemLog::degraded('audit.domain_event.record_failed', reason: $reason, input: ['event_name' => $eventName], error: $exception);`. Las llamadas de las líneas 39 y 66 pasan `'classifier_failed'` y `'dispatch_failed'`. |
| ExecuteAction:188 | `SystemLog::degraded('automation.webhook.connection_failed', reason: 'connection_failed', input: ['action_execution_id' => $execution->id], error: $exception)` |
| ExecuteAction:348 | `SystemLog::skipped('automation.recipients.non_member_skipped', reason: 'not_team_member', input: ['team_id' => $teamId, 'skipped_user_ids' => $skipped])` |
| RunAutomationWorkflowJob:57 | `JobFailureReporter::report(static::class, $exception, ['automation_workflow_id' => $this->automationWorkflowId])` |
| RetryActionExecutionJob:46 | `JobFailureReporter::report(static::class, $exception)` |
| ExecuteActionJob:151 | `JobFailureReporter::report(static::class, $exception, ['action_execution_id' => $this->actionExecutionId])` |
| BackfillVehicleStatsJob:89 | `SystemLog::ok('telematics.backfill.completed', input: ['integration_id' => $this->integration->id, 'feed' => $this->feed->value, 'from' => $from->toIso8601ZuluString(), 'until' => $this->until->toIso8601ZuluString()], result: ['pages' => $pages, 'stored' => $stored], channel: 'telematics')` |
| FollowVehicleStatsFeedJob:151 | Si `$failure === null`: `SystemLog::ok('telematics.cycle.completed', input: ['integration_id' => $this->integration->id, 'feed' => $this->feed->value], result: ['pages' => $pages, 'locations' => $result->locationsStored, 'readings' => $result->readingsStored, 'moved_assets' => count($result->positions), 'lag_s' => $cursor->lagSeconds()], durationMs: $cursor->last_cycle_json['duration_ms'], channel: 'telematics')`. Si no: `SystemLog::degraded('telematics.cycle.failed', reason: 'provider_error', input: [...los mismos...], result: [...los mismos...], error: $failure, durationMs: $cursor->last_cycle_json['duration_ms'], channel: 'telematics')`. |
| CancelBlockedNotification:37 | `SystemLog::skipped('notifications.notification.cancelled', reason: 'tenant_cannot_send', input: ['notification_id' => $notification->id, 'team_id' => $notification->team_id, 'blocked_reason' => $reason])` |
| RecordMessagingUsage:39 | `SystemLog::degraded('billing.messaging_usage.not_metered', reason: 'record_failed', input: ['team_id' => $teamId, 'meter_code' => $meterCode, 'event_key' => $eventKey], error: $e)` |
| RecordMessagingCharge:59 | `SystemLog::degraded('billing.messaging_charge.record_failed', reason: 'record_failed', input: ['team_id' => $teamId, 'provider_sid' => $providerSid, 'source_type' => $sourceType->value, 'source_id' => $sourceId], error: $e)` |
| DispatchNotification:151 | `SystemLog::degraded('notifications.delivery.skip_record_failed', reason: 'record_failed', input: ['notification_id' => $notification->id, 'recipient_id' => $recipient->id, 'channel_id' => $channel->id, 'skip_reason' => $reason], error: $e)` |
| ProcessInboundReply:42 | `SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'no_keyword')`. **Sin el teléfono.** |
| ProcessInboundReply:59 | `SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'unknown_token')`. **Sin el teléfono ni el token.** |
| ProcessInboundReply:68 | `SystemLog::degraded('notifications.inbound_reply.rejected', reason: 'unexpected_sender', input: ['token_id' => $token->id])` |
| ReconcileMessagingChargesJob:97 | `SystemLog::degraded('billing.messaging_charge.reconcile_failed', reason: 'provider_error', input: ['charge_id' => $charge->id, 'provider_sid' => $charge->provider_sid], error: $e)` |
| StartIncidentCallVerification:98 | `SystemLog::skipped('incidents.call_verification.skipped', reason: 'no_phone_contact', input: ['incident_id' => $incident->id, 'team_id' => $incident->team_id])` |
| PlaceVerificationCallJob:112 | `SystemLog::degraded('incidents.call_verification.emergency_override', reason: 'tenant_blocked', input: ['verification_id' => $verification->id, 'team_id' => $verification->team_id, 'blocked_reason' => $blocked])` |
| PlaceVerificationCallJob:131 | `SystemLog::skipped('incidents.call_verification.skipped', reason: 'no_voice_channel', input: ['verification_id' => $verification->id, 'team_id' => $verification->team_id])` |
| PlaceVerificationCallJob:159 | `SystemLog::degraded('incidents.call_verification.placement_failed', reason: 'provider_error', input: ['verification_id' => $verification->id], error: $e)` |
| SdkCopilotNarrator:38 | `SystemLog::degraded('copilot.narration.fallback', reason: 'agent_error', error: $exception)` |

Notas:
- `blocked_reason` es un código de `TenantCanSend` (constantes `REASON_*`), no texto libre.
- `provider_sid` es el SID de Twilio (no secreto).
- `external_id` es un id de Samsara (no PII).

- [ ] **Step 1: Write the failing tests**

En `DriverSyncHandlerServiceTest`:
- añade el trait `AssertsSystemLog`;
- borra `Log::spy()`;
- sustituye `Log::shouldHaveReceived('warning')->once();` por:

```php
        $this->assertCount(1, $this->systemLogEntries('drivers.sync.external_id_conflict'));
        $this->assertSystemLogged('drivers.sync.external_id_conflict', fn (array $c) => $c['reason'] === 'owned_by_other_tenant');
```

Crea `tests/Feature/Support/ProcessInboundReplyLogTest.php`. `ProcessInboundReply::execute(string $fromAddress, string $body): ?string` reconoce `KEYWORD_PATTERN = /\b(SI|NO|ESC)\s*-?\s*([2-9A-HJKMNP-Z]{4})\b/iu`:

```php
<?php

namespace Tests\Feature\Support;

use App\Domains\Notifications\Actions\ProcessInboundReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ProcessInboundReplyLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_an_unrecognizable_reply_is_logged_without_the_sender_phone(): void
    {
        $this->assertNull(app(ProcessInboundReply::class)->execute('+525512345678', 'hola que tal'));

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'no_keyword');
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('5512345678', (string) json_encode($this->systemLogEntries()));
    }

    public function test_an_unknown_token_is_logged_without_the_token_or_the_phone(): void
    {
        $this->assertNull(app(ProcessInboundReply::class)->execute('+525512345678', 'SI ZZ99'));

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'unknown_token');
        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('ZZ99', $json);
        $this->assertStringNotContainsString('5512345678', $json);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Domains/Drivers/DriverSyncHandlerServiceTest.php tests/Feature/Support/ProcessInboundReplyLogTest.php`
Expected: FAIL.

- [ ] **Step 3: Apply the migration table**

Sustituye cada llamada según la tabla. En cada archivo añade o quita los `use` necesarios.

- [ ] **Step 4: Run the affected domains**

Run: `php artisan test --compact tests/Feature/Domains/Drivers tests/Feature/Domains/Audit tests/Feature/Domains/Automation tests/Feature/Domains/Assets tests/Feature/Domains/Notifications tests/Feature/Domains/Incidents tests/Feature/Domains/Copilot tests/Feature/Support`
Expected: PASS (misma regla: actualizar aserciones, nunca borrarlas).

- [ ] **Step 5: Commit**

```bash
git add app/Domains app/Infrastructure tests/Feature/Domains/Drivers/DriverSyncHandlerServiceTest.php tests/Feature/Support/ProcessInboundReplyLogTest.php
git commit -m "fix: logs sin teléfonos ni tokens y migración del resto a systemlog"
```

---

### Task 9: Guardias — test arquitectural, regla y catálogo

**Files:**
- Create: `tests/Feature/Architecture/LoggingConventionsTest.php`
- Modify: `app/CLAUDE.md` (sección nueva "Logging")
- Create: `docs/SAM/logging.md`

**Interfaces:**
- Consumes: `SystemLog::CODE_PATTERN`.

- [ ] **Step 1: Write the guard test**

```php
<?php

namespace Tests\Feature\Architecture;

use App\Support\SystemLog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * La exigencia del log narrativo no se pierde con código nuevo: todo pasa por
 * SystemLog, ningún getMessage() crudo llega a un log y cada código literal
 * cumple dominio.etapa.resultado. Ver docs/SAM/logging.md.
 */
class LoggingConventionsTest extends TestCase
{
    /**
     * Archivos que pueden usar la fachada Log directamente.
     *
     * @var list<string>
     */
    private const array ALLOWED_DIRECT_LOG = [
        'app/Support/SystemLog.php',
    ];

    /**
     * @return array<string, string> ruta relativa → contenido
     */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'routes', 'bootstrap/app.php'] as $root) {
            $path = base_path($root);
            $list = is_dir($path) ? File::allFiles($path) : [new \SplFileInfo($path)];

            foreach ($list as $file) {
                if (str_ends_with($file->getPathname(), '.php')) {
                    $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    public function test_nothing_logs_outside_system_log(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            if (in_array($path, self::ALLOWED_DIRECT_LOG, true)) {
                continue;
            }

            if (preg_match('/\bLog::|(?<![\w>:$])logger\(|(?<![\w>:$])info\(/', $code) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, "Usa App\\Support\\SystemLog en lugar de Log::/logger()/info():\n".implode("\n", $offenders));
    }

    public function test_no_raw_exception_message_reaches_a_log(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            foreach (preg_split('/;\s*\n/', $code) ?: [] as $statement) {
                if (str_contains($statement, 'SystemLog::') && str_contains($statement, 'getMessage()')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), "Pasa la excepción como `error:` (SafeException), nunca getMessage():\n".implode("\n", $offenders));
    }

    public function test_every_literal_code_follows_the_schema(): void
    {
        $invalid = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all('/SystemLog::(?:ok|skipped|degraded|failed|measure)\(\s*\'([^\']+)\'/', $code, $matches);

            foreach ($matches[1] as $literal) {
                if (preg_match(SystemLog::CODE_PATTERN, $literal) !== 1) {
                    $invalid[] = "{$path}: {$literal}";
                }
            }
        }

        $this->assertSame([], $invalid, "Códigos fuera de dominio.etapa.resultado:\n".implode("\n", $invalid));
    }

    public function test_every_literal_code_is_in_the_catalog(): void
    {
        $catalog = (string) file_get_contents(base_path('docs/SAM/logging.md'));
        $missing = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all('/SystemLog::(?:ok|skipped|degraded|failed|measure)\(\s*\'([^\']+)\'/', $code, $matches);

            foreach ($matches[1] as $literal) {
                if (! str_contains($catalog, '`'.$literal.'`')) {
                    $missing[] = "{$path}: {$literal}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "Añade estos códigos a docs/SAM/logging.md:\n".implode("\n", array_unique($missing)));
    }
}
```

- [ ] **Step 2: Run it to see what is left**

Run: `php artisan test --compact tests/Feature/Architecture/LoggingConventionsTest.php`
Expected: FAIL en `test_every_literal_code_is_in_the_catalog`, porque el doc no existe. Los otros tres tests deben estar ya en verde tras los Tasks 6–8. Si `test_nothing_logs_outside_system_log` lista archivos, son logs que quedaron sin migrar: migrarlos con el mismo criterio de las tablas antes de seguir. Los falsos positivos de `info(` en métodos propios (p. ej. `$this->info(` en comandos) ya están excluidos por el lookbehind `>`. Si aparece otro falso positivo, ajusta el regex, no la allowlist.

- [ ] **Step 3: Write the catalog and the rule**

Crea `docs/SAM/logging.md` con este contenido:
- el esquema de la línea (tabla de §3 del spec);
- cómo seguir un evento (`jq 'select(.extra.trace_id == "<id>")' storage/logs/system-*.json` y por código: `jq 'select(.message == "ai.gate.skipped")'`);
- lo prohibido (Global Constraints);
- una tabla por dominio con `código | outcome | reason posibles | campos clave`, con **todos** los códigos que emiten los Tasks 4–8.

Los códigos son estos (cada uno entre backticks en el doc):
- `queue.job.finished`, `queue.job.released`, `queue.job.attempt_failed`, `queue.job.failed`;
- `http.client.request.completed`, `http.client.request.failed`;
- `http.request.denied`, `http.request.throttled`, `http.request.not_found`;
- `auth.login.succeeded`, `auth.login.failed`, `auth.login.locked_out`, `auth.logout.succeeded`, `auth.password.reset`;
- `storage.object.operation_failed`;
- `{dominio}.{job}.failed` (patrón de `JobFailureReporter`);
- todos los códigos de las tablas de los Tasks 7 y 8.

El test busca cada literal: `JobFailureReporter` no emite literales, así que el patrón no necesita cada instancia.

En `app/CLAUDE.md`, añade al final:

```markdown
## Logging (narrativo y seguro)

- Todo log pasa por `App\Support\SystemLog` (`ok`/`skipped`/`degraded`/`failed`/`measure`); nunca `Log::`, `logger()` ni `info()` (lo impide `LoggingConventionsTest`).
- **Toda rama de decisión** (return temprano, skip, gate, fallback, dedupe, umbral) y **todo cálculo** registra su código `dominio.etapa.resultado` con `reason` (si no es `ok`), `input` y, en cálculos, `calc` con cada término y umbral — debe poder rehacerse a mano.
- Excepciones como `error: $e` (SafeException), nunca `getMessage()`. Nunca teléfonos, emails, nombres, tokens, secretos, payloads, texto libre ni prompts.
- Cada código nuevo: entrada en `docs/SAM/logging.md` y un test con `Tests\Concerns\AssertsSystemLog` (`assertSystemLogged` + `assertNoSensitiveDataLogged`).
```

- [ ] **Step 4: Run guard and full gate**

Run: `php artisan test --compact tests/Feature/Architecture/LoggingConventionsTest.php`
Expected: PASS.

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: suite completa en verde.

Run: `npm run types:check && npm run lint:check && npm run format:check`
Expected: PASS (no se tocó frontend; es parte del gate).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Architecture/LoggingConventionsTest.php app/CLAUDE.md docs/SAM/logging.md
git commit -m "test: guardia arquitectural del logging, regla y catálogo de códigos"
```

---

## Cierre de la fase

- [ ] Revisión de aislamiento con el subagente `tenant-isolation-reviewer`. Revisa que ningún log mezcle tenants y que `team_id` venga del registro.
- [ ] `git push -u origin feat/system-logging`, y abrir el PR "feat: fundación del logging narrativo y seguro (fase 1)" con el resumen de códigos y fugas cerradas.
- [ ] `gh pr checks --watch`; lo rojo se arregla con un commit nuevo.
- [ ] Merge según la autorización amplia vigente (mergear lo correcto a main tras validar contra main).
- [ ] Siguiente: plan de la fase 2 (pipeline de entrada) sobre `main` actualizado.
