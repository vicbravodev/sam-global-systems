<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Support\CopilotLocalTimes;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Base of every tool the Copilot agent can call. It owns what the model must
 * never control: the tenant (always the turn's scope, inside TenantContext),
 * the permission gate, argument validation, the 90-day window, the local
 * time of every instant and the 6 KB cap on what goes back to the model, the collector and the log narrative.
 *
 * Errors never escape to the SDK: bad arguments, unknown units and tool
 * exceptions all come back to the model as `{"error": ...}` JSON.
 */
abstract class SdkCopilotTool implements Tool
{
    private const MAX_OUTPUT_BYTES = 6144;

    private const MAX_PERIOD_DAYS = 90;

    private const DEFAULT_PERIOD_DAYS = 7;

    public function __construct(
        protected readonly CopilotTurnScope $scope,
        protected readonly CopilotTurnCollector $collector,
    ) {}

    abstract public function name(): string;

    abstract public function description(): Stringable|string;

    abstract public function permission(): ?string;

    abstract public function intent(): CopilotIntent;

    /**
     * @return array<string, Type>
     */
    abstract public function schema(JsonSchema $schema): array;

    /**
     * Laravel validation rules for the model's own arguments (the period
     * rules are added by the base).
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * Runs inside `TenantContext::for($scope->teamId)`. `$args` holds the
     * validated arguments plus `__period` (CopilotPeriod); implementations
     * set `__asset_id` and `__period_days` so they reach the log and the
     * collector.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws CopilotAssetNotFound
     */
    abstract protected function execute(array &$args, string $toolCallId): CopilotToolResult;

    public function handle(Request $request): Stringable|string
    {
        $callId = $request->toolCallId() ?? 'call';
        $log = ['team_id' => $this->scope->teamId, 'tool' => $this->name(), 'tool_call_id' => $callId];

        try {
            // Only declared keys survive validation: an argument the tool does not take is dropped, never read.
            $args = $request->validate($this->rules() + ($this->acceptsPeriod() ? $this->periodRules() : []));
            $args['__period'] = $this->period($args);
        } catch (ValidationException $e) {
            SystemLog::skipped('copilot.tool.invalid_args', 'validation_failed', [...$log, 'fields' => array_keys($e->errors())]);

            return $this->json(['error' => 'argumentos inválidos', 'detalle' => $e->errors()]);
        }

        $started = hrtime(true);

        try {
            $result = TenantContext::for($this->scope->teamId, function () use (&$args, $callId): CopilotToolResult {
                return $this->execute($args, $callId);
            });
        } catch (CopilotAssetNotFound) {
            SystemLog::skipped('copilot.tool.invalid_args', 'asset_not_found', [...$log, 'fields' => ['asset_code']]);

            return $this->json(['error' => 'unidad no encontrada', 'sugerencia' => 'usa find_assets para buscarla']);
        } catch (Throwable $e) {
            $durationMs = SystemLog::elapsedMs($started);
            $label = $this->label();
            $this->collector->record($callId, $this->name(), new CopilotToolResult($this->name(), $label), 'error', $durationMs, null);
            SystemLog::degraded('copilot.tool.failed', 'tool_exception', $log, error: $e, durationMs: $durationMs);

            return $this->json(['error' => "no pude consultar {$label}"]);
        }

        $durationMs = SystemLog::elapsedMs($started);
        $assetId = isset($args['__asset_id']) ? (int) $args['__asset_id'] : null;

        $this->collector->record($callId, $this->name(), $result, $result->denied ? 'denied' : 'ok', $durationMs, $assetId);

        [$payload, $truncated] = $this->compact([
            'facts' => CopilotLocalTimes::localize($result->facts, $this->scope->timezone),
            'highlights' => $result->highlights,
        ]);

        if ($result->denied) {
            SystemLog::skipped('copilot.tool.denied', 'missing_permission', [...$log, 'permission' => $this->permission()]);

            return $payload;
        }

        SystemLog::ok(
            'copilot.tool.ran',
            [...$log, 'asset_id' => $assetId, 'period_days' => $args['__period_days'] ?? null],
            result: ['rows' => count($result->blocks), 'truncated' => $truncated],
            durationMs: $durationMs,
        );

        return $payload;
    }

    /**
     * Human name of the tool, used when telling the model it failed.
     */
    public function label(): string
    {
        return (CopilotToolDefinition::all()[$this->name()] ?? null)?->displayLabel() ?? $this->name();
    }

    /**
     * Whether the tool reads a time window (`from`/`to`). Tools that do not
     * (e.g. a unit search) neither declare nor validate them and run on the
     * default window.
     */
    protected function acceptsPeriod(): bool
    {
        return true;
    }

    /**
     * `from`/`to` are ISO-8601 and shared by every tool; ordering and the
     * 90-day cap are checked in `period()`.
     *
     * @return array<string, list<string>>
     */
    private function periodRules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'before_or_equal:'.$this->scope->now->addMinute()->toIso8601String()],
        ];
    }

    /**
     * Window the tool reads: the one the model asked for, or the last 7 days.
     *
     * @param  array<string, mixed>  $args  validated
     *
     * @throws ValidationException
     */
    protected function period(array $args): CopilotPeriod
    {
        $from = isset($args['from']) ? CarbonImmutable::parse($args['from'], $this->scope->timezone) : null;
        $to = isset($args['to']) ? CarbonImmutable::parse($args['to'], $this->scope->timezone) : null;

        if ($from === null && $to === null) {
            $now = $this->scope->now;

            return new CopilotPeriod($now->subDays(self::DEFAULT_PERIOD_DAYS), $now, 'últimos '.self::DEFAULT_PERIOD_DAYS.' días', self::DEFAULT_PERIOD_DAYS);
        }

        $to ??= $this->scope->now;
        $from ??= $to->subDays(self::DEFAULT_PERIOD_DAYS);

        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages(['from' => 'El inicio debe ser anterior al fin.']);
        }

        if ($from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['from' => 'El rango máximo es de '.self::MAX_PERIOD_DAYS.' días.']);
        }

        return CopilotPeriod::between($from, $to);
    }

    /**
     * Keeps what goes back to the model under 6 KB: oversized facts are
     * collapsed into a cut JSON summary flagged `truncado`.
     *
     * @param  array{facts: array<string, mixed>, highlights: list<string>}  $payload
     * @return array{0: string, 1: bool}
     */
    private function compact(array $payload): array
    {
        $json = $this->json($payload);

        if (strlen($json) <= self::MAX_OUTPUT_BYTES) {
            return [$json, false];
        }

        $facts = $this->json($payload['facts']);
        $payload['highlights'] = array_map(fn (string $h) => mb_strcut($h, 0, 300), array_slice($payload['highlights'], 0, 8));
        $budget = 4000;

        do {
            $json = $this->json([
                'facts' => ['resumen' => mb_strcut($facts, 0, $budget)],
                'highlights' => $payload['highlights'],
                'truncado' => true,
            ]);
            $budget = (int) floor($budget * 0.8);
        } while (strlen($json) > self::MAX_OUTPUT_BYTES && $budget > 0);

        return [$json, true];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
