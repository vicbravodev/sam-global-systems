<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotMessage;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * How a conversation feels, on the real turn path: follow-up chips in the
 * user's voice, local times to the model and memory beyond the last turn.
 */
class CopilotConversationQualityTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config(['ai.providers.openai.key' => 'test-key']);
    }

    private function answer(string $text): TextResponse
    {
        return new TextResponse($text, new TextUsage(inputTokens: 300, outputTokens: 20), new Meta('openai', 'gpt-test'));
    }

    public function test_chips_keep_only_questions_in_the_user_voice(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake([
            new ToolCall('call_1', 'asset_location', ['asset_code' => 'T555']),
            new ToolCall('call_2', 'suggest_followups', ['questions' => [
                '¿Quieres que revise su combustible?',
                '¿Dónde está la T555?',
                'Muéstrame los eventos de hoy de la T555',
                '¿Te comparo la T555 con la flota?',
            ]]),
            $this->answer('La **T555** va en ruta.'),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está la T555?'])
            ->assertCreated();

        // Offers dropped, and the question just asked is never suggested back.
        $this->assertSame(['Muéstrame los eventos de hoy de la T555'], $response->json('answer.followups'));
        $this->assertSame(
            ['Muéstrame los eventos de hoy de la T555'],
            CopilotMessage::query()->findOrFail($response->json('answer.id'))->context_json['followups'],
        );
        $this->assertSystemLogged('copilot.followups.filtered', fn ($c) => $c['reason'] === 'not_user_voice_or_repeated'
            && $c['input']['team_id'] === $team->id
            && $c['calc'] === ['kept' => 1, 'dropped' => 3]);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_model_is_asked_to_rewrite_when_every_chip_was_an_offer(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        CopilotAgent::fake([
            new ToolCall('call_1', 'suggest_followups', ['questions' => ['¿Quieres que revise algo más?', '¿Te muestro la flota?']]),
            $this->answer('Listo.'),
        ]);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'Hola'])
            ->assertCreated()
            ->assertJsonPath('answer.followups', []);

        $this->assertSystemLogged('copilot.followups.filtered', fn ($c) => $c['calc'] === ['kept' => 0, 'dropped' => 2]);
    }

    public function test_the_model_reads_times_in_the_tenant_local_zone(): void
    {
        [, $team] = $this->memberWithRole('supervisor');
        $team->forceFill(['timezone' => 'America/Mexico_City'])->save();
        $this->truckWithTelemetry($team, 'T555');

        $out = $this->callTool($team, ['assets.view'], 'asset_location', ['asset_code' => 'T555']);

        $recordedAt = $out['facts']['recorded_at'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $recordedAt);
        $this->assertStringNotContainsString('+00:00', (string) json_encode($out));

        // The card keeps the ISO instant for the browser.
        $block = $this->toolBlock('location');
        $this->assertNotNull($block);
        $this->assertSame(
            $recordedAt,
            CarbonImmutable::parse($block['recordedAt'])->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
        );
    }

    public function test_the_third_turn_still_remembers_the_first_one(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake([
            new ToolCall('call_1', 'asset_fuel', ['asset_code' => 'T555']),
            $this->answer('La **T555** consumió **40 %** de tanque. '.str_repeat('Detalle del consumo por día. ', 70)),
        ]);
        $first = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cuánto combustible gastó la T555?'])
            ->assertCreated();
        $conversationId = $first->json('conversation.id');

        CopilotAgent::fake([
            new ToolCall('call_2', 'asset_location', ['asset_code' => 'T555']),
            $this->answer('Va en ruta por Apodaca. '.str_repeat('Contexto de la ruta reciente. ', 70)),
        ]);
        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Y dónde está?', 'conversation_id' => $conversationId])
            ->assertCreated();

        CopilotAgent::fake([$this->answer('Gastó **40 %** y sigue en ruta.')]);
        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'Resúmeme las dos cosas', 'conversation_id' => $conversationId])
            ->assertCreated();

        CopilotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $history = collect($prompt->agent->messages());

            return $prompt->prompt === "Resúmeme las dos cosas\n\nUnidad en contexto: T555."
                && $history->contains(fn (Message $m) => $m->content === '¿Cuánto combustible gastó la T555?')
                && $history->contains(fn (Message $m) => $m->role->value === 'assistant' && str_contains((string) $m->content, '**40 %**'))
                && $history->contains(fn (Message $m) => $m->content === '¿Y dónde está?');
        });
    }
}
