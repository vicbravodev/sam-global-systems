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
