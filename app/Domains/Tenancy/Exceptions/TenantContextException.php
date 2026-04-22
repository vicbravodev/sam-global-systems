<?php

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

class TenantContextException extends RuntimeException
{
    public static function noTeamResolved(): self
    {
        return new self('No tenant context could be resolved for the current user.');
    }
}
