<?php

namespace App\Domains\Integrations\Exceptions;

/**
 * The provider refused a feed cursor (expired after 30 days, or corrupted).
 * The feed must restart without one and refill the gap from history.
 */
class ProviderCursorRejected extends ProviderRequestFailed {}
