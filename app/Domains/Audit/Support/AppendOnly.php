<?php

namespace App\Domains\Audit\Support;

use RuntimeException;

/**
 * Enforces append-only semantics on Eloquent models.
 *
 * Audit data must never be updated or deleted (Spec 14 §10.1).
 * The `updating` and `deleting` model events throw a RuntimeException
 * when triggered, and the updated-at column is nulled so $timestamps
 * still works with only `created_at`. The column is nulled by overriding
 * getUpdatedAtColumn() instead of redefining the UPDATED_AT constant:
 * a trait constant that differs from the inherited Model::UPDATED_AT is
 * a fatal error on PHP < 8.5.
 */
trait AppendOnly
{
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

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
