<?php

namespace App\Domains\Access\Data;

class OtpResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = '',
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }
}
