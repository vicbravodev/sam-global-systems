<?php

namespace App\Domains\Copilot\Tools\Sdk;

use RuntimeException;

/**
 * The unit the model named is not in the tenant's fleet.
 */
final class CopilotAssetNotFound extends RuntimeException {}
