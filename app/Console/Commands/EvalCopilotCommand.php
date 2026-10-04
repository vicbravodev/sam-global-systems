<?php

namespace App\Console\Commands;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Actions\RunCopilotAgentTurn;
use App\Domains\Copilot\Actions\SendCopilotMessage;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotAnswerChecks;
use App\Models\Team;
use App\Models\User;
use App\Support\SafeErrorMessage;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

/**
 * Arnés de conversación del Copilot: corre conversaciones guionadas de
 * varios turnos (tests/Fixtures/copilot-eval/*.json) por el camino real del
 * turno —historial, herramientas, sugerencias— contra el proveedor
 * configurado, y califica cada respuesta con CopilotAnswerChecks más las
 * expectativas del guion (tools llamadas, unidad resuelta, regex). Pensado
 * para correr antes y después de tocar el prompt, las tools o el modelo.
 *
 * Cada conversación corre dentro de una transacción que se revierte: no
 * deja mensajes, auditoría ni uso facturable en la base.
 */
class EvalCopilotCommand extends Command
{
    protected $signature = 'copilot:eval
        {--cases= : Directorio con los guiones *.json (por defecto tests/Fixtures/copilot-eval)}
        {--filter= : Sólo guiones cuyo nombre o archivo contenga este texto}
        {--team= : Team id a usar (por defecto el primero con unidades)}
        {--user= : Usuario que pregunta (por defecto el dueño del team, o su primer miembro)}
        {--asset= : Código de unidad para {asset} (por defecto la que más reporta GPS)}
        {--asset2= : Código de unidad para {asset2} (por defecto la segunda que más reporta)}
        {--show : Imprime cada respuesta y sus pills}
        {--fail-under= : Sale con error si el % de turnos aprobados queda por debajo (0-1)}';

    protected $description = 'Evalúa conversaciones guionadas del Copilot (consistencia, repetición, pills, horas locales)';

    public function handle(SendCopilotMessage $send, AuthorizeAction $authorize): int
    {
        $failUnder = $this->option('fail-under');

        if ($failUnder !== null && (! is_numeric($failUnder) || (float) $failUnder < 0 || (float) $failUnder > 1)) {
            $this->error('--fail-under debe ser un número entre 0 y 1.');

            return self::INVALID;
        }

        $scenarios = $this->loadScenarios();

        if (is_string($scenarios)) {
            $this->error($scenarios);

            return self::INVALID;
        }

        if ($scenarios === []) {
            $this->error('No se encontraron guiones de evaluación.');

            return self::FAILURE;
        }

        $team = $this->team();

        if ($team === null) {
            $this->error('No hay un team con unidades para evaluar. Usa --team.');

            return self::FAILURE;
        }

        $user = $this->option('user') !== null
            ? User::query()->find((int) $this->option('user'))
            : ($team->owner() ?? $team->members()->first());

        if ($user === null) {
            $this->error('No encontré al usuario que pregunta. Usa --user.');

            return self::FAILURE;
        }

        $assets = $this->assets($team);

        if (! RunCopilotAgentTurn::available()) {
            $this->warn('Sin key del proveedor: se evalúa el camino determinista, no el agente.');
        }

        $this->info(sprintf('Team %s · unidades: %s · %d guiones', $team->slug, implode(', ', array_filter($assets, fn (?string $code): bool => $code !== null)), count($scenarios)));

        $permissions = array_values($authorize->resolvePermissions($user, $team));
        $rows = [];
        $turnsPassed = 0;
        $turnsTotal = 0;
        $failedChecks = [];
        $latencies = [];
        $tokens = 0;
        $cost = 0.0;

        foreach ($scenarios as $scenario) {
            foreach ($this->runScenario($send, $team, $user, $permissions, $scenario, $assets) as $turn) {
                $turnsTotal++;
                $failures = array_filter($turn['checks'], fn (?string $why): bool => $why !== null);

                if ($failures === []) {
                    $turnsPassed++;
                }

                foreach (array_keys($failures) as $check) {
                    $failedChecks[$check] = ($failedChecks[$check] ?? 0) + 1;
                }

                $latencies[] = $turn['latency_ms'];
                $tokens += $turn['tokens'];
                $cost += $turn['cost'];

                $rows[] = [
                    mb_strimwidth($scenario['file'], 0, 22, '…'),
                    $turn['number'],
                    mb_strimwidth($turn['question'], 0, 48, '…'),
                    mb_strimwidth($turn['tools'] !== [] ? implode(',', $turn['tools']) : '—', 0, 40, '…'),
                    $turn['latency_ms'],
                    $failures === []
                        ? '<fg=green>OK</>'
                        : '<fg=red>'.mb_strimwidth(implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($failures), $failures)), 0, 90, '…').'</>',
                ];

                if ($this->option('show')) {
                    $this->newLine();
                    $this->line("<fg=cyan>[{$scenario['file']} #{$turn['number']}] {$turn['question']}</>");
                    $this->line($turn['answer']);
                    $this->line('<fg=gray>pills: '.($turn['followups'] !== [] ? implode(' | ', $turn['followups']) : '—').'</>');
                }
            }
        }

        $this->newLine();
        $this->table(['Guion', '#', 'Pregunta', 'Tools', 'ms', 'Resultado'], $rows);

        $rate = $turnsTotal > 0 ? $turnsPassed / $turnsTotal : 0.0;
        arsort($failedChecks);

        $this->line(sprintf('Turnos aprobados: %.1f%% (%d/%d)', $rate * 100, $turnsPassed, $turnsTotal));
        $this->line('Chequeos que más fallan:'.($failedChecks === [] ? ' ninguno' : ''));

        foreach ($failedChecks as $check => $count) {
            $this->line("  - {$check} ({$count})");
        }

        $this->line(sprintf('Latencia promedio: %d ms', (int) round(array_sum($latencies) / max(count($latencies), 1))));
        $this->line(sprintf('Tokens totales: %d · Costo estimado: $%.4f USD', $tokens, $cost));

        if ($failUnder !== null && $rate < (float) $failUnder) {
            $this->error(sprintf('Turnos aprobados %.3f por debajo del umbral %.3f.', $rate, (float) $failUnder));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Plays one scenario turn by turn on a single conversation, inside a
     * transaction that is always rolled back.
     *
     * @param  list<string>  $permissions
     * @param  array{name: string, file: string, turns: list<array<string, mixed>>}  $scenario
     * @param  array{asset: string|null, asset2: string|null}  $assets
     * @return list<array{number: int, question: string, answer: string, followups: list<string>, tools: list<string>, checks: array<string, string|null>, latency_ms: int, tokens: int, cost: float}>
     */
    private function runScenario(SendCopilotMessage $send, Team $team, User $user, array $permissions, array $scenario, array $assets): array
    {
        $results = [];
        $conversation = null;
        $previous = null;
        $asked = [];

        DB::beginTransaction();

        try {
            foreach ($scenario['turns'] as $index => $step) {
                $question = isset($step['ask_followup'])
                    ? ($previous?->context_json['followups'][(int) $step['ask_followup']] ?? null)
                    : $this->fill((string) $step['ask'], $assets);

                if (! is_string($question) || $question === '') {
                    $results[] = $this->skippedTurn($index + 1, 'sin pill que tocar en el turno anterior');

                    break;
                }

                if (preg_match('/\{asset2?\}/', $question) === 1) {
                    $results[] = $this->skippedTurn($index + 1, 'el team no tiene unidad para '.$question, $question);

                    break;
                }

                $startedAt = hrtime(true);

                try {
                    ['conversation' => $conversation, 'answer' => $answer] = $send->execute($team, $user, $permissions, $question, $conversation, channel: 'eval');
                } catch (Throwable $e) {
                    $results[] = $this->skippedTurn($index + 1, 'error: '.SafeErrorMessage::from($e, 200), $question);

                    break;
                }

                $asked[] = $question;
                $results[] = $this->grade($index + 1, $question, $answer, $previous, $asked, $step, $assets, $team, intdiv(hrtime(true) - $startedAt, 1_000_000));
                $previous = $answer;
            }
        } finally {
            DB::rollBack();
        }

        return $results;
    }

    /**
     * @param  list<string>  $asked
     * @param  array<string, mixed>  $step
     * @param  array{asset: string|null, asset2: string|null}  $assets
     * @return array{number: int, question: string, answer: string, followups: list<string>, tools: list<string>, checks: array<string, string|null>, latency_ms: int, tokens: int, cost: float}
     */
    private function grade(int $number, string $question, CopilotMessage $answer, ?CopilotMessage $previous, array $asked, array $step, array $assets, Team $team, int $latencyMs): array
    {
        $followups = array_values((array) ($answer->context_json['followups'] ?? []));
        $tools = array_column((array) $answer->tools_json, 'tool');

        $checks = CopilotAnswerChecks::run(
            $answer->content,
            $previous?->content,
            $followups,
            $asked,
            (int) ($step['max_chars'] ?? CopilotAnswerChecks::DEFAULT_MAX_CHARS),
        );

        $checks['agente'] = ($answer->context_json['mode'] ?? null) === 'agent' && ! ($answer->context_json['partial'] ?? false)
            ? null
            : 'respondió '.($answer->context_json['mode'] ?? '?').(($answer->context_json['partial'] ?? false) ? ' parcial' : '');

        $missing = array_diff((array) ($step['expect_tools'] ?? []), $tools);
        $checks['tools_esperadas'] = $missing !== [] ? 'faltó '.implode(',', $missing) : null;

        $any = (array) ($step['expect_tools_any'] ?? []);
        $checks['tools_alguna'] = $any !== [] && array_intersect($any, $tools) === [] ? 'ninguna de '.implode('|', $any) : null;

        $forbidden = array_intersect((array) ($step['forbid_tools'] ?? []), $tools);
        $checks['tools_prohibidas'] = $forbidden !== [] ? 'llamó '.implode(',', $forbidden) : null;

        $checks['unidad_en_contexto'] = $this->assetCheck($step, $assets, $answer, $team);

        foreach ((array) ($step['expect_regex'] ?? []) as $pattern) {
            $pattern = $this->pattern($pattern, $assets);

            if (preg_match('/'.$pattern.'/iu', $answer->content) !== 1) {
                $checks['regex_esperada'] = "no coincide /{$pattern}/";
            }
        }

        foreach ((array) ($step['forbid_regex'] ?? []) as $pattern) {
            $pattern = $this->pattern($pattern, $assets);

            if (preg_match('/'.$pattern.'/iu', $answer->content) === 1) {
                $checks['regex_prohibida'] = "coincide /{$pattern}/";
            }
        }

        foreach ((array) ($step['skip_checks'] ?? []) as $skipped) {
            unset($checks[$skipped]);
        }

        return [
            'number' => $number,
            'question' => $question,
            'answer' => $answer->content,
            'followups' => $followups,
            'tools' => $tools,
            'checks' => $checks,
            'latency_ms' => $latencyMs,
            'tokens' => $answer->input_tokens + $answer->output_tokens,
            'cost' => (float) $answer->cost_estimate,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array{asset: string|null, asset2: string|null}  $assets
     */
    private function assetCheck(array $step, array $assets, CopilotMessage $answer, Team $team): ?string
    {
        if (! isset($step['expect_asset'])) {
            return null;
        }

        $code = $this->fill((string) $step['expect_asset'], $assets);
        $resolvedId = $answer->context_json['resolved']['asset_id'] ?? null;
        $resolved = $resolvedId !== null
            ? TenantContext::for($team->id, fn () => Asset::query()->where('team_id', $team->id)->whereKey((int) $resolvedId)->value('code'))
            : null;

        return $resolved === $code ? null : 'esperaba '.$code.', resolvió '.($resolved ?? 'ninguna');
    }

    /**
     * @return array{number: int, question: string, answer: string, followups: list<string>, tools: list<string>, checks: array<string, string|null>, latency_ms: int, tokens: int, cost: float}
     */
    private function skippedTurn(int $number, string $why, string $question = '—'): array
    {
        return ['number' => $number, 'question' => $question, 'answer' => '', 'followups' => [], 'tools' => [], 'checks' => ['turno' => $why], 'latency_ms' => 0, 'tokens' => 0, 'cost' => 0.0];
    }

    /**
     * @param  array{asset: string|null, asset2: string|null}  $assets
     */
    private function pattern(string $pattern, array $assets): string
    {
        return strtr($pattern, [
            '{asset2}' => preg_quote($assets['asset2'] ?? '{asset2}', '/'),
            '{asset}' => preg_quote($assets['asset'] ?? '{asset}', '/'),
        ]);
    }

    /**
     * @param  array{asset: string|null, asset2: string|null}  $assets
     */
    private function fill(string $text, array $assets): string
    {
        return strtr($text, ['{asset2}' => $assets['asset2'] ?? '{asset2}', '{asset}' => $assets['asset'] ?? '{asset}']);
    }

    private function team(): ?Team
    {
        return TenantContext::withoutTenant(function (): ?Team {
            if ($this->option('team') !== null) {
                return Team::query()->find((int) $this->option('team'));
            }

            return Team::query()
                ->whereIn('id', Asset::query()->select('team_id'))
                ->orderBy('id')
                ->first();
        });
    }

    /**
     * The units `{asset}`/`{asset2}` stand for: the ones that reported the
     * most GPS fixes in the last week, so their answers carry real data.
     *
     * @return array{asset: string|null, asset2: string|null}
     */
    private function assets(Team $team): array
    {
        $codes = TenantContext::for($team->id, fn () => Asset::query()
            ->where('team_id', $team->id)
            ->whereNotNull('code')
            ->withCount(['locationSnapshots as fixes' => fn ($q) => $q->where('recorded_at', '>=', now()->subDays(7))])
            ->orderByDesc('fixes')
            ->orderBy('code')
            ->limit(2)
            ->pluck('code')
            ->all());

        return [
            'asset' => $this->option('asset') ?? ($codes[0] ?? null),
            'asset2' => $this->option('asset2') ?? ($codes[1] ?? null),
        ];
    }

    /**
     * @return list<array{name: string, file: string, turns: list<array<string, mixed>>}>|string the scenarios, or why they are invalid
     */
    private function loadScenarios(): array|string
    {
        $directory = $this->option('cases') ?? base_path('tests/Fixtures/copilot-eval');

        if (! File::isDirectory($directory)) {
            return "No existe el directorio de guiones: {$directory}";
        }

        $filter = $this->option('filter');
        $scenarios = [];

        foreach (collect(File::glob(rtrim($directory, '/').'/*.json'))->sort()->values() as $file) {
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'JSON inválido en '.basename($file).': '.json_last_error_msg();
            }

            $turns = $data['turns'] ?? null;

            if (! is_string($data['name'] ?? null) || ! is_array($turns) || $turns === []) {
                return 'Guion '.basename($file).': requiere name y turns.';
            }

            foreach ($turns as $i => $turn) {
                if (! is_array($turn) || (! is_string($turn['ask'] ?? null) && ! is_int($turn['ask_followup'] ?? null))) {
                    return 'Guion '.basename($file).', turno '.($i + 1).': requiere ask o ask_followup.';
                }
            }

            $scenario = ['name' => $data['name'], 'file' => basename($file, '.json'), 'turns' => array_values($turns)];

            if ($filter !== null && ! str_contains($scenario['name'], $filter) && ! str_contains($scenario['file'], $filter)) {
                continue;
            }

            $scenarios[] = $scenario;
        }

        return $scenarios;
    }
}
