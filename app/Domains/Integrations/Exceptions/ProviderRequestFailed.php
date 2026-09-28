<?php

namespace App\Domains\Integrations\Exceptions;

use RuntimeException;

/**
 * Base of the typed failures a provider adapter raises for its streaming
 * reads, so a caller can react per kind (pause, back off, resync) instead of
 * parsing HTTP statuses it should not know about.
 */
abstract class ProviderRequestFailed extends RuntimeException {}
