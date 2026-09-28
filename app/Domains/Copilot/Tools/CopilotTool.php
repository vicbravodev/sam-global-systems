<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;

/**
 * A read-only data lookup the Copilot can run. Tools only ever read the
 * tenant in `$context->teamId` and check the user's module permission first.
 */
interface CopilotTool
{
    public function run(CopilotToolContext $context): CopilotToolResult;
}
