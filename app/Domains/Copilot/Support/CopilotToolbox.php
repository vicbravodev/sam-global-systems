<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Tools\Sdk\CopilotToolDefinition;
use App\Domains\Copilot\Tools\Sdk\DelegatingCopilotTool;
use App\Domains\Copilot\Tools\Sdk\SdkCopilotTool;
use App\Domains\Copilot\Tools\Sdk\SuggestFollowupsTool;
use Illuminate\Contracts\Container\Container;

/**
 * The tools offered to the agent in one turn: only those the user's role
 * may read, all bound to the turn's tenant scope and collector.
 */
final class CopilotToolbox
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<SdkCopilotTool|SuggestFollowupsTool>
     */
    public function for(CopilotTurnScope $scope, CopilotTurnCollector $collector): array
    {
        $tools = array_map(
            fn (CopilotToolDefinition $definition): SdkCopilotTool => $this->container->make(
                $definition->sdkClass ?? DelegatingCopilotTool::class,
                ['scope' => $scope, 'collector' => $collector, 'definition' => $definition],
            ),
            array_values(CopilotToolDefinition::all()),
        );

        $allowed = array_values(array_filter($tools, fn (SdkCopilotTool $tool) => $scope->can($tool->permission())));

        return [...$allowed, new SuggestFollowupsTool($collector)];
    }
}
