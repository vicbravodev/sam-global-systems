<?php

namespace App\Domains\Integrations\Exceptions;

/**
 * HTTP 401/403: the tenant's token is invalid, revoked or lacks the scope.
 * Retrying cannot fix it; only new credentials can.
 */
class ProviderUnauthorized extends ProviderRequestFailed {}
