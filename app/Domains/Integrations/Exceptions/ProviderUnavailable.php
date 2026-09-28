<?php

namespace App\Domains\Integrations\Exceptions;

/**
 * HTTP 5xx, a timeout or a connection failure: transient, worth retrying
 * with backoff.
 */
class ProviderUnavailable extends ProviderRequestFailed {}
