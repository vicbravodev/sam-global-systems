<?php

namespace Tests\Feature\Console;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\Feature\Domains\Copilot\CopilotFixtures;
use Tests\TestCase;

/**
 * Arnés `copilot:eval`: conversaciones guionadas por el camino real del
 * turno, con el agente del SDK en modo fake (sin red).
 */
class EvalCopilotCommandTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config(['ai.providers.openai.key' => 'test-key']);
        $this->dir = storage_path('framework/testing/copilot-eval-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function scenario(string $file, array $scenario): void
    {
        File::put("{$this->dir}/{$file}.json", (string) json_encode($scenario, JSON_UNESCAPED_UNICODE));
    }

    private function text(string $text): TextResponse
    {
        return new TextResponse($text, new TextUsage(inputTokens: 100, outputTokens: 10), new Meta('openai', 'gpt-test'));
    }

    public function test_grades_each_turn_follows_a_chip_and_leaves_nothing_behind(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        $this->scenario('01-unidad', ['name' => 'Unidad', 'turns' => [
            ['ask' => '¿Dónde está la {asset}?', 'expect_tools_any' => ['asset_location'], 'expect_asset' => '{asset}'],
            ['ask_followup' => 0, 'forbid_regex' => ['^\\s*(sí|si)\\b[.,:]']],
        ]]);

        CopilotAgent::fake([
            new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']),
            new ToolCall('c2', 'suggest_followups', ['questions' => ['¿Cuánto combustible le queda a la T555?', '¿Qué eventos tuvo hoy la T555?']]),
            $this->text('La **T555** va en ruta por Apodaca a **72 km/h**.'),
            new ToolCall('c3', 'asset_fuel', ['asset_code' => 'T555']),
            new ToolCall('c4', 'suggest_followups', ['questions' => ['Compara la T555 con la flota', '¿Dónde cargó combustible la T555?']]),
            $this->text('Su tanque está en **58 %** y cargó dos veces esta semana.'),
        ]);

        $this->artisan('copilot:eval', ['--cases' => $this->dir, '--team' => $team->id, '--user' => $user->id, '--fail-under' => '1'])
            ->expectsOutputToContain('T555')
            ->expectsOutputToContain('Turnos aprobados: 100.0% (2/2)')
            ->assertSuccessful();

        // The second question was the first chip, sent as the user's own words.
        CopilotAgent::assertPrompted(fn ($prompt) => str_starts_with($prompt->prompt, '¿Cuánto combustible le queda a la T555?'));

        // Everything ran inside a rolled-back transaction.
        $this->assertSame(0, CopilotConversation::query()->count());
        $this->assertSame(0, CopilotMessage::query()->count());
    }

    public function test_reports_clumsy_answers_and_fails_under_the_threshold(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        $this->scenario('02-torpe', ['name' => 'Torpe', 'turns' => [
            ['ask' => '¿Dónde está la {asset}?', 'expect_tools' => ['asset_location'], 'max_chars' => 200],
        ]]);

        CopilotAgent::fake([
            new ToolCall('c1', 'suggest_followups', ['questions' => ['¿Quieres que revise su combustible?']]),
            $this->text('Claro. La T555 reportó a las 02:44:56 UTC. '.str_repeat('Más detalle. ', 20).'Siguiente paso recomendado: revisar.'),
        ]);

        $this->artisan('copilot:eval', ['--cases' => $this->dir, '--team' => $team->id, '--user' => $user->id, '--fail-under' => '0.5', '--show' => true])
            ->expectsOutputToContain('Turnos aprobados: 0.0% (0/1)')
            ->expectsOutputToContain('hora_local (1)')
            ->expectsOutputToContain('sin_preambulo (1)')
            ->expectsOutputToContain('sin_formula (1)')
            ->expectsOutputToContain('largo (1)')
            ->expectsOutputToContain('pills_presentes (1)')
            ->expectsOutputToContain('tools_esperadas (1)')
            ->assertFailed();
    }

    public function test_rejects_malformed_scenarios(): void
    {
        $this->scenario('mal', ['name' => 'Sin turnos', 'turns' => []]);

        $this->artisan('copilot:eval', ['--cases' => $this->dir])
            ->expectsOutputToContain('requiere name y turns')
            ->assertExitCode(2);
    }

    public function test_the_repo_scenarios_are_valid(): void
    {
        [, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake(fn () => $this->text('Listo.'));

        $this->artisan('copilot:eval', ['--team' => $team->id])
            ->expectsOutputToContain('7 guiones')
            ->assertSuccessful();
    }
}
