<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotMessage;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * Memoria entre turnos: el historial del asistente viaja con el digest de
 * los datos que consultó, así el segundo turno no vuelve a adivinar.
 */
class CopilotMemoryTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config(['ai.providers.openai.key' => 'test-key']);
    }

    public function test_second_turn_sees_the_facts_of_the_first(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake([
            new ToolCall('call_1', 'asset_fuel', ['asset_code' => 'T555']),
            new TextResponse('T555 consumió 40 %.', new TextUsage(inputTokens: 300, outputTokens: 20), new Meta('openai', 'gpt-test')),
        ]);

        $first = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cuánto combustible gastó T555?'])
            ->assertCreated();

        $firstAnswer = CopilotMessage::query()->findOrFail($first->json('answer.id'));
        $this->assertNotEmpty($firstAnswer->context_json['facts_digest'] ?? null);
        $this->assertStringContainsString('asset_fuel', $firstAnswer->context_json['facts_digest']);
        $this->assertSame($asset->id, $firstAnswer->context_json['resolved']['asset_id']);

        CopilotAgent::fake(['Sigue en **T555**.']);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => '¿Y cuánto le queda?',
                'conversation_id' => $first->json('conversation.id'),
            ])
            ->assertCreated()
            ->assertJsonPath('answer.content', 'Sigue en **T555**.');

        CopilotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $history = collect($prompt->agent->messages());

            return $prompt->prompt !== '' && str_contains($prompt->prompt, 'Unidad en contexto: T555.')
                && $history->contains(fn (Message $m) => $m->role->value === 'assistant'
                    && str_contains((string) $m->content, '[datos consultados:')
                    && str_contains((string) $m->content, 'asset_fuel'));
        });
    }
}
