<?php

namespace App\Domains\Integrations\Exceptions;

/**
 * The provider refused to resume a feed from the given pagination cursor:
 * the cursor is invalid or expired, or the request parameters no longer match
 * the request that produced it. The cursor is unusable and the caller must
 * restart the feed from a fresh start time.
 */
class ProviderCursorRejectedException extends ProviderRequestFailedException {}
