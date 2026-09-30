<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Copilot\Tools\Sdk\SdkCopilotTool;
use App\Models\Team;
use Laravel\Ai\Tools\Request;

/**
 * Calls an agent tool the way the SDK does: through the turn's toolbox and
 * `handle()`, returning the JSON the model would read.
 */
trait RunsCopilotTools
{
    protected CopilotTurnCollector $toolCollector;

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function callTool(Team $team, array $permissions, string $name, array $args = [], bool $isSuperAdmin = false): array
    {
        $scope = CopilotTurnScope::fromTeam($team, $permissions, $isSuperAdmin);
        $this->toolCollector = new CopilotTurnCollector;

        $tool = collect(app(CopilotToolbox::class)->for($scope, $this->toolCollector))
            ->first(fn (SdkCopilotTool $t) => $t->name() === $name);

        $this->assertInstanceOf(SdkCopilotTool::class, $tool, "La tool {$name} no está en la caja.");

        return json_decode((string) $tool->handle(new Request($args, 'call_t')), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function toolBlock(string $type): ?array
    {
        return collect($this->toolCollector->blocks())->firstWhere('type', $type);
    }
}
