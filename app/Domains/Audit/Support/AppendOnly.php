<?php

namespace App\Domains\Audit\Support;

use RuntimeException;

/**
 * Enforces append-only semantics on Eloquent models.
 *
 * Audit data must never be updated or deleted (Spec 14 §10.1).
 * The `updating` and `deleting` model events throw a RuntimeException
 * when triggered, and `UPDATED_AT` is nulled so $timestamps still works
 * with only `created_at`.
 */
trait AppendOnly
{
    public const UPDATED_AT = null;

    public static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            throw new RuntimeException(sprintf(
                'Audit records are append-only; updating %s is not allowed.',
                static::class,
            ));
        });

        static::deleting(function ($model): void {
            throw new RuntimeException(sprintf(
                'Audit records are append-only; deleting %s is not allowed.',
                static::class,
            ));
        });
    }
}
