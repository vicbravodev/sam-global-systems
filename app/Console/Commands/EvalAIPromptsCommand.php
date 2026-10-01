<?php

namespace App\Console\Commands;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Arnés de evaluación de prompts: corre casos etiquetados (fixtures JSON)
 * contra el `EventEvaluationAgent` bindeado —el proveedor real si está
 * configurado— y reporta aciertos, falsos negativos en casos críticos,
 * latencia y costo. Pensado para correr antes y después de tocar el prompt
 * o el modelo.
 *
 * Cada llamada corre dentro de una transacción que se revierte: el arnés no
 * deja rastro en la base (links de conversación, etc.).
 */
class EvalAIPromptsCommand extends Command
{
    protected $signature = 'ai:eval-prompts
        {--cases= : Directorio con los casos *.json (por defecto tests/Fixtures/ai-eval/cases)}
        {--filter= : Sólo casos cuyo nombre o archivo contenga este texto}
        {--fail-under= : Sale con error si la precisión queda por debajo (0-1), p.ej. 0.9}
        {--team= : Team id a usar como contexto de las llamadas (por defecto el primero)}
        {--event= : Evento normalizado "portador" del team (por defecto el más reciente); el agente del SDK enlaza ahí su conversación, dentro de una transacción que se revierte}';

    protected $description = 'Evalúa el prompt de clasificación de eventos contra casos etiquetados';

    /**
     * Clasificaciones que "descartan" el evento: en un caso crítico son un
     * falso negativo, el error caro.
     */
    private const DOWNGRADES = ['false_positive', 'noise', 'duplicate'];

    public function handle(EventEvaluationAgent $agent): int
    {
        $failUnder = $this->option('fail-under');

        if ($failUnder !== null && (! is_numeric($failUnder) || (float) $failUnder < 0 || (float) $failUnder > 1)) {
            $this->error('--fail-under debe ser un número entre 0 y 1.');

            return self::INVALID;
        }

        try {
            $cases = $this->loadCases();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($cases === []) {
            $this->error('No se encontraron casos de evaluación.');

            return self::FAILURE;
        }

        $teamId = $this->option('team') !== null
            ? (int) $this->option('team')
            : (int) (TenantContext::withoutTenant(fn () => Team::query()->orderBy('id')->value('id')) ?? 0);

        $carrierEventId = $this->option('event') !== null
            ? (int) $this->option('event')
            : (int) (TenantContext::for($teamId !== 0 ? $teamId : null, fn () => NormalizedEvent::query()
                ->where('team_id', $teamId)
                ->orderByDesc('id')
                ->value('id')) ?? 0);

        if ($carrierEventId === 0) {
            $this->warn('Sin evento normalizado en el team: el agente del SDK puede fallar al enlazar su conversación. Usa --team/--event.');
        }

        $this->info(sprintf('Agente: %s · %d casos', $agent::class, count($cases)));

        $rows = [];
        $passed = 0;
        $criticalFalseNegatives = 0;
        $latencies = [];
        $tokens = 0;
        $cost = 0.0;

        foreach ($cases as $case) {
            $outcome = $this->runCase($agent, $case, $teamId, $carrierEventId);

            $result = $outcome['result'];
            $classification = $result?->classification->value;
            $ok = $result !== null
                && in_array($classification, $case['expected'], true)
                && ! in_array($classification, $case['forbidden'], true);

            $isCriticalFalseNegative = $case['critical'] && $classification !== null && in_array($classification, self::DOWNGRADES, true);

            if ($ok) {
                $passed++;
            }
            if ($isCriticalFalseNegative) {
                $criticalFalseNegatives++;
            }

            $latencies[] = $outcome['latency_ms'];

            if ($result !== null) {
                $tokens += $result->totalTokens();
                $cost += $result->costEstimate;
            }

            $rows[] = [
                $case['name'],
                implode(', ', $case['expected']),
                $classification ?? 'ERROR',
                $result !== null ? number_format($result->confidenceScore, 2) : '—',
                $outcome['latency_ms'],
                match (true) {
                    $isCriticalFalseNegative => '<fg=red>FALLA (FN crítico)</>',
                    $ok => '<fg=green>OK</>',
                    $result === null => '<fg=red>ERROR: '.mb_strimwidth((string) $outcome['error'], 0, 60, '…').'</>',
                    default => '<fg=red>FALLA</>',
                },
            ];
        }

        $this->table(['Caso', 'Esperado', 'Obtenido', 'Conf.', 'ms', 'Resultado'], $rows);

        $total = count($cases);
        $accuracy = $passed / $total;

        $this->line(sprintf('Precisión: %.1f%% (%d/%d)', $accuracy * 100, $passed, $total));
        $this->line(sprintf('Falsos negativos en casos críticos: %d', $criticalFalseNegatives));
        $this->line(sprintf('Latencia promedio: %d ms', (int) round(array_sum($latencies) / max(count($latencies), 1))));
        $this->line(sprintf('Tokens totales: %d · Costo estimado: $%.4f USD', $tokens, $cost));

        if ($failUnder !== null && $accuracy < (float) $failUnder) {
            $this->error(sprintf('Precisión %.3f por debajo del umbral %.3f.', $accuracy, (float) $failUnder));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, file: string, critical: bool, expected: list<string>, forbidden: list<string>, input: array<string, mixed>}  $case
     * @return array{result: AIEvaluationResult|null, latency_ms: int, error: string|null}
     */
    private function runCase(EventEvaluationAgent $agent, array $case, int $teamId, int $carrierEventId): array
    {
        $context = $this->contextFor($case['input'], $teamId, $carrierEventId);
        $startedAt = hrtime(true);

        DB::beginTransaction();

        try {
            $result = TenantContext::for($teamId !== 0 ? $teamId : null, fn () => $agent->evaluate($context));
            $measured = intdiv(hrtime(true) - $startedAt, 1_000_000);

            return [
                'result' => $result,
                'latency_ms' => $result->latencyMs > 0 ? $result->latencyMs : $measured,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'result' => null,
                'latency_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                'error' => $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function contextFor(array $input, int $teamId, int $carrierEventId): AIInputContext
    {
        return new AIInputContext(
            teamId: $teamId,
            normalizedEventId: $carrierEventId,
            normalizedEvent: (array) ($input['normalized_event'] ?? []),
            contextSignals: (array) ($input['context_signals'] ?? []),
            operationalProfile: (array) ($input['operational_profile'] ?? []),
            recentHistory: (array) ($input['recent_history'] ?? ['event_count' => 0]),
            tenantProfile: (array) ($input['tenant_profile'] ?? []),
            mediaAssessments: array_values((array) ($input['media_assessments'] ?? [])),
        );
    }

    /**
     * @return list<array{name: string, file: string, critical: bool, expected: list<string>, forbidden: list<string>, input: array<string, mixed>}>
     */
    private function loadCases(): array
    {
        $directory = $this->option('cases') ?? base_path('tests/Fixtures/ai-eval/cases');

        if (! File::isDirectory($directory)) {
            throw new InvalidArgumentException("No existe el directorio de casos: {$directory}");
        }

        $filter = $this->option('filter');
        $files = collect(File::glob(rtrim($directory, '/').'/*.json'))->sort()->values();

        $cases = [];

        foreach ($files as $file) {
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('JSON inválido en '.basename($file).': '.$exception->getMessage());
            }

            $case = $this->validateCase($data, basename($file));

            if ($filter !== null && ! str_contains($case['name'], $filter) && ! str_contains($case['file'], $filter)) {
                continue;
            }

            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, file: string, critical: bool, expected: list<string>, forbidden: list<string>, input: array<string, mixed>}
     */
    private function validateCase(array $data, string $file): array
    {
        $valid = array_map(fn (EventClassification $c) => $c->value, EventClassification::cases());

        $expected = array_values((array) ($data['expected_classifications'] ?? []));
        $forbidden = array_values((array) ($data['forbidden_classifications'] ?? []));

        if (! is_string($data['name'] ?? null) || $expected === [] || ! is_array($data['input'] ?? null)) {
            throw new InvalidArgumentException("Caso {$file}: requiere name, expected_classifications e input.");
        }

        foreach ([...$expected, ...$forbidden] as $classification) {
            if (! in_array($classification, $valid, true)) {
                throw new InvalidArgumentException("Caso {$file}: clasificación desconocida '{$classification}'.");
            }
        }

        if (array_intersect($expected, $forbidden) !== []) {
            throw new InvalidArgumentException("Caso {$file}: una clasificación no puede ser esperada y prohibida a la vez.");
        }

        if (isset($data['expected_media_result']) && MediaAssessmentResult::tryFrom((string) $data['expected_media_result']) === null) {
            throw new InvalidArgumentException("Caso {$file}: expected_media_result desconocido.");
        }

        return [
            'name' => $data['name'],
            'file' => $file,
            // Crítico por defecto si el falso positivo está prohibido: descartar
            // ese evento es exactamente el error que no se puede cometer.
            'critical' => (bool) ($data['critical'] ?? in_array('false_positive', $forbidden, true)),
            'expected' => $expected,
            'forbidden' => $forbidden,
            'input' => $data['input'],
        ];
    }
}
