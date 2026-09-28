<?php

namespace App\Domains\Integrations\Enums;

/**
 * Turns the raw `last_error_message` an integration carries (written by the
 * adapter, the pollers or the sync jobs, often in English and with HTTP
 * codes) into a small vocabulary the integrations page can explain to a
 * non-technical operator: what went wrong and what to do about it.
 *
 * The raw message is never lost: the page still shows it under "Detalles
 * técnicos" for support.
 */
enum IntegrationProblem: string
{
    /** The provider rejected the access key (revoked, expired, missing scope). */
    case Credentials = 'credentials';

    /** Too many requests: the provider is throttling us for a while. */
    case RateLimited = 'rate_limited';

    /** We could not reach the provider (network, DNS, timeout, outage). */
    case Unreachable = 'unreachable';

    /** The provider answered with an error of its own. */
    case Provider = 'provider';

    /** Anything else: shown generically, raw message in the details. */
    case Unknown = 'unknown';

    public static function classify(?string $message): ?self
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $text = mb_strtolower($message);

        return match (true) {
            self::matches($text, ['401', '403', 'unauthori', 'forbidden', 'token', 'credential', 'revocad', 'api key', 'clave']) => self::Credentials,
            self::matches($text, ['429', 'rate limit', 'rate-limit', 'too many']) => self::RateLimited,
            self::matches($text, ['could not reach', 'timed out', 'timeout', 'connection refused', 'could not resolve', 'curl error', 'unavailable', '502', '503', '504']) => self::Unreachable,
            self::matches($text, ['http 5', 'returned http', 'server error']) => self::Provider,
            default => self::Unknown,
        };
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function matches(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
