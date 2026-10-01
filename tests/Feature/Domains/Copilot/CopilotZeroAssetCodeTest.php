<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Support\CopilotPresenter;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * Un código de unidad "0" es un código válido: ni la etiqueta de la unidad
 * ni la línea de contexto que recibe el agente pueden tratarlo como vacío.
 */
class CopilotZeroAssetCodeTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config(['ai.providers.openai.key' => 'test-key']);
    }

    public function test_asset_label_keeps_a_zero_code(): void
    {
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, '0');

        $this->assertSame('0 · Kenworth 0', CopilotPresenter::assetLabel($asset));
    }

    public function test_asset_label_without_code_is_the_name(): void
    {
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, 'T1');
        $asset->forceFill(['code' => null])->save();

        $this->assertSame('Kenworth T1', CopilotPresenter::assetLabel($asset));
    }

    public function test_agent_tool_resolves_the_unit_with_zero_code(): void
    {
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, '0');

        $this->callTool($team, ['assets.view'], 'asset_location', ['asset_code' => '0']);

        $block = $this->toolBlock('location');
        $this->assertNotNull($block);
        $this->assertSame($asset->id, $block['assetId']);
        $this->assertSame('0 · Kenworth 0', $block['assetLabel']);
    }

    public function test_agent_prompt_names_a_pinned_unit_with_zero_code(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, '0');

        CopilotAgent::fake(['Listo.']);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => '¿Cómo va?',
                'asset_id' => $asset->id,
            ])
            ->assertCreated();

        CopilotAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Unidad en contexto: 0.'),
        );
    }
}
