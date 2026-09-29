<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
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
        // Antes del callback: un código inválido nunca enmascara su excepción.
        self::assertSchema(self::violation(self::OK, $code, null), $code);

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
        self::assertSchema($violation, $code);

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

        // Loguear nunca rompe al llamador (jobs, catch de rutas de fallo,
        // respuestas HTTP): un sink caído se traga sin registrar, porque el
        // log es justo lo que falló. La violación de esquema ya lanzó arriba.
        try {
            foreach (self::$listeners as $listener) {
                $listener(['level' => $level, 'code' => $code, 'context' => $context, 'channel' => $channel]);
            }

            if ($channel === null) {
                Log::log($level, $code, $context);
            } else {
                Log::channel($channel)->log($level, $code, $context);
            }
        } catch (Throwable) {
            // Se traga sin registrar.
        }
    }

    private static function assertSchema(?string $violation, string $code): void
    {
        if ($violation !== null && ! app()->environment('production')) {
            throw new SystemLogSchemaViolation("SystemLog: {$violation} [{$code}]");
        }
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
