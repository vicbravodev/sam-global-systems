<?php

namespace App\Support;

/**
 * Guard for values that reach a log from unauthenticated input (a webhook's
 * `event_type` from the query string, a provider code): the value is logged
 * only when it looks like a catalog code, otherwise `null`. Never logs the
 * rejected value itself.
 */
final class LoggableCode
{
    public const string PATTERN = '/^[A-Za-z0-9_.]{1,64}$/D';

    public static function guard(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
