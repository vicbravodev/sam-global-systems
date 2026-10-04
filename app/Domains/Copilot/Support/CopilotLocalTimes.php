<?php

namespace App\Domains\Copilot\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Rewrites every ISO-8601 instant in the facts a tool hands the model to
 * the tenant's local time ("2026-09-30 12:39"). The data is stored in UTC
 * and the model used to quote it raw ("18:39:11+00:00", "02:44 UTC") to a
 * manager in Mexico; the prompt tells it these are already local. Only the
 * model's copy changes: the cards keep their ISO values for the browser.
 */
final class CopilotLocalTimes
{
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    public const FORMAT = 'Y-m-d H:i';

    /**
     * @param  array<array-key, mixed>  $facts
     * @return array<array-key, mixed>
     */
    public static function localize(array $facts, string $timezone): array
    {
        foreach ($facts as $key => $value) {
            if (is_array($value)) {
                $facts[$key] = self::localize($value, $timezone);
            } elseif (is_string($value) && preg_match(self::ISO, $value) === 1) {
                $facts[$key] = self::format($value, $timezone);
            }
        }

        return $facts;
    }

    private static function format(string $iso, string $timezone): string
    {
        try {
            return CarbonImmutable::parse($iso)->setTimezone($timezone)->format(self::FORMAT);
        } catch (Throwable) {
            return $iso;
        }
    }
}
