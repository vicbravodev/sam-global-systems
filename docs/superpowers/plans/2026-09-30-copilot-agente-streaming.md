# SAM Copilot agente + streaming — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que SAM Copilot decida qué consultar con tools del SDK (multi-paso), compare flota (incluido ralentí), recuerde datos entre turnos y responda en streaming con tarjetas en vivo, con respaldo determinista.

**Architecture:** `CopilotAgent` (laravel/ai 1.0, `HasTools` + `HasMiddleware`) recibe tools SDK que envuelven las `CopilotTool` de dominio; un `CopilotTurnCollector` por turno junta tarjetas/fuentes/followups. `SendCopilotMessage` (JSON) y `StreamCopilotTurn` (SSE, `CopilotStreamProtocol extends VercelDataProtocol`) comparten preparar/terminar turno. Sin key o con fallo previo a la salida → camino determinista actual (`AnswerCopilotQuestion` + plantilla) emitido por el mismo protocolo.

**Tech Stack:** Laravel 13 · PHP 8.5 · laravel/ai 1.0.1 · PHPUnit 13 (SQLite en memoria) · React 19 · TypeScript · Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-09-30-copilot-agente-streaming-design.md`

## Global Constraints

- Tenant: toda query con `where('team_id', $scope->teamId)` explícito **y** dentro de `TenantContext::for($teamId, ...)`. El modelo nunca pasa `team_id`, `user_id` ni permisos.
- Logging sólo vía `App\Support\SystemLog`; nunca `Log::`. Nunca loguear texto de pregunta/respuesta ni `query` libre: sólo largos, códigos, conteos, ids. `reason` en snake_case cuando outcome ≠ ok.
- Cada código de log nuevo: fila en `docs/SAM/logging.md` (sección "IA (`ai`) y copiloto (`copilot`)") + test con `assertSystemLogged` y `assertNoSensitiveDataLogged`.
- Tests PHPUnit (no Pest), factories, `RefreshDatabase`, DB real (SQLite). No mockear la DB. No borrar ni debilitar tests existentes (migrar los del narrador SDK al agente sí).
- Rango temporal de tools: por defecto últimos 7 días; máximo 90 días (retención de telemetría).
- Tope de salida de tool al modelo: 6 KB JSON (truncado con `"truncado": true`).
- `#[MaxSteps(6)]`, `#[Timeout(45)]`, `copilot.max_turn_tokens` = 60000 por defecto.
- Sin dependencias nuevas en `composer.json` ni `package.json`.
- Idioma de UI y de instrucciones del agente: español de México.
- Commits: `type: subject en minúsculas`, sin `Co-Authored-By`. Pint corre solo por hook; manual: `vendor/bin/pint --dirty --format agent`.
- Correr tests en este worktree: `APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit --no-coverage --filter=<Nombre>` (el worktree no tiene `.env`; `vendor/` es real, no symlink).

## Review Focus

1. **Pregunta sin unidad ni periodo claro ("¿cómo vamos?")** → el agente debe responder con `fleet_overview`, nunca error. Test: Task 6 `test_open_question_uses_fleet_overview`.
2. **Modelo que pide la misma tool en bucle** → el guard fuerza respuesta al paso 6; el turno termina con texto. Test: Task 5 `test_final_step_forces_answer_without_tools`.
3. **Unidad `Idle` sin señal hace horas** → no infla ralentí. Test: Task 2 `test_open_idle_segment_is_cut_at_last_seen_when_asset_is_silent`.
4. **Usuario cierra el chat a mitad del stream** → se guarda parcial, no se cobra doble, log `client_disconnected`. Test: Task 7 `test_client_disconnect_persists_partial_answer`.
5. **Tenant sin telemetría de combustible/odómetro** → `rank_assets` devuelve ranking vacío con aviso, sin excepción. Test: Task 4 `test_rank_without_telemetry_returns_notice`.

---

## File Structure

**Create**
- `app/Domains/Copilot/Data/CopilotTurnScope.php` — quién pregunta y cuándo (server-side).
- `app/Domains/Copilot/Data/CopilotTurnOutcome.php` — resultado de correr un turno (texto, uso, modo).
- `app/Domains/Copilot/Data/IdleSummary.php` — horas, fuente, tramos.
- `app/Domains/Copilot/Support/CopilotTurnCollector.php` — acumulador por turno.
- `app/Domains/Copilot/Support/IdleTimeCalculator.php` — ralentí por unidad(es).
- `app/Domains/Copilot/Support/FuelConsumption.php` — consumo % extraído de `AssetFuelTool`.
- `app/Domains/Copilot/Support/CopilotToolbox.php` — tools SDK permitidas para un scope.
- `app/Domains/Copilot/Support/CopilotHistory.php` — historial + digest de datos.
- `app/Domains/Copilot/Tools/FindAssetsTool.php`, `RankAssetsTool.php`, `SearchEventsTool.php`, `AssetTimelineTool.php`.
- `app/Domains/Copilot/Tools/Sdk/SdkCopilotTool.php` — base abstracta (validación, scope, log, collector).
- `app/Domains/Copilot/Tools/Sdk/DelegatingCopilotTool.php` — adapta una `CopilotTool` de dominio.
- `app/Domains/Copilot/Tools/Sdk/CopilotToolDefinition.php` — nombre/descr/permiso/args de cada tool.
- `app/Domains/Copilot/Tools/Sdk/SuggestFollowupsTool.php`.
- `app/Domains/Copilot/Actions/PrepareCopilotTurn.php`, `FinishCopilotTurn.php`, `RunCopilotAgentTurn.php`, `RunDeterministicCopilotTurn.php`, `StreamCopilotTurn.php`.
- `app/Domains/Copilot/Streaming/CopilotStreamProtocol.php`, `CopilotStreamState.php`, `DeterministicCopilotParts.php`.
- `app/Infrastructure/AI/Middleware/CopilotStepGuard.php`.
- `app/Http/Controllers/Copilot/CopilotStreamController.php`.
- `resources/js/components/sam/copilot/copilot-stream.ts`.
- Tests: `tests/Feature/Domains/Copilot/{IdleTimeCalculatorTest,FuelConsumptionTest,SdkCopilotToolTest,CopilotAgentTenantLeakTest,CopilotToolPermissionsTest,FindAssetsToolTest,RankAssetsToolTest,SearchEventsToolTest,AssetTimelineToolTest,CopilotStepGuardTest,CopilotAgentTurnTest,CopilotMemoryTest,CopilotStreamEndpointTest,CopilotFallbackTest}.php`.

**Modify**
- `app/Domains/AI/Support/ModelPricing.php`, `config/ai.php`, `app/Infrastructure/AI/Agents/{SdkEventEvaluationAgent,SdkMediaAssessmentAgent}.php`.
- `app/Domains/Copilot/Data/CopilotToolContext.php` (+`arguments`), `CopilotPeriod.php` (+`between`), `Enums/CopilotIntent.php` (+3 casos).
- `app/Domains/Copilot/Support/AssetResolver.php` (+`resolveByCode`), `Tools/AssetFuelTool.php` (usa `FuelConsumption`), `Tools/AssetEngineTool.php` (+ralentí).
- `app/Infrastructure/AI/Agents/CopilotAgent.php` (reescrito).
- `app/Domains/Copilot/Actions/SendCopilotMessage.php` (orquesta prepare/run/finish).
- `app/Domains/Copilot/CopilotServiceProvider.php` (quita binding del narrador SDK).
- `routes/web.php`, `resources/js/components/sam/copilot/{use-copilot-chat.ts,copilot-message.tsx}`, `resources/js/lib/sam-fetch.ts`, `resources/js/types/copilot.ts`.
- `docs/SAM/logging.md`.

**Delete**
- `app/Infrastructure/AI/Agents/SdkCopilotNarrator.php` (Task 6; su test se migra).

---

### Task 0: Verificar que llegan estados `Idle` en dev (sin código)

- [ ] **Step 1: Consultar la DB de dev** (en el checkout principal, con Sail arriba):

```bash
./vendor/bin/sail artisan tinker --execute 'dump(\App\Domains\Assets\Models\AssetTelemetrySnapshot::withoutGlobalScopes()->where("telemetry_type","ignition")->selectRaw("data_json->>\"value\" as v, count(*) c")->groupBy("v")->get()->toArray());'
```

Expected: filas con `v` ∈ {`Off`,`On`,`Idle`}. Si **no** aparece `Idle`, anótalo en el PR: el cálculo usará la fuente de respaldo (`ignition_speed`) y el Task 2 sigue igual (cubre ambas).

- [ ] **Step 2:** Si Sail no está arriba, pedir al usuario que corra el comando; no bloquear el resto del plan.

---

### Task 1: Costo de IA con tokens cacheados

**Files:**
- Modify: `app/Domains/AI/Support/ModelPricing.php`
- Modify: `config/ai.php` (bloque `pricing`)
- Modify: `app/Infrastructure/AI/Agents/SdkEventEvaluationAgent.php:60-64`, `app/Infrastructure/AI/Agents/SdkMediaAssessmentAgent.php` (llamada análoga)
- Test: `tests/Feature/Domains/AI/ModelPricingTest.php`

**Interfaces:**
- Produces: `ModelPricing::estimateUsageCost(?string $model, \Laravel\Ai\Responses\Data\TextUsage $usage): float` (usada en Task 6).

- [ ] **Step 1: Tests que fallan** (agregar a `ModelPricingTest`):

```php
public function test_cached_input_tokens_are_billed_at_the_cached_rate(): void
{
    config(['ai.pricing' => ['gpt-5.4' => ['input' => 2.0, 'cached_input' => 0.5, 'output' => 8.0]]]);

    $usage = new \Laravel\Ai\Responses\Data\TextUsage(inputTokens: 1_000_000, outputTokens: 100_000, cacheReadInputTokens: 400_000);

    // 600k sin caché × 2.0 + 400k caché × 0.5 + 100k × 8.0
    $this->assertSame(1.2 + 0.2 + 0.8, app(ModelPricing::class)->estimateUsageCost('gpt-5.4', $usage));
}

public function test_cached_rate_defaults_to_a_tenth_of_input(): void
{
    config(['ai.pricing' => ['gpt-5.4' => ['input' => 2.0, 'output' => 0.0]]]);

    $usage = new \Laravel\Ai\Responses\Data\TextUsage(inputTokens: 1_000_000, outputTokens: 0, cacheReadInputTokens: 1_000_000);

    $this->assertSame(0.2, app(ModelPricing::class)->estimateUsageCost('gpt-5.4', $usage));
}

public function test_usage_cost_of_unknown_model_is_zero(): void
{
    $usage = new \Laravel\Ai\Responses\Data\TextUsage(inputTokens: 10, outputTokens: 10);

    $this->assertSame(0.0, app(ModelPricing::class)->estimateUsageCost('nope', $usage));
}
```

- [ ] **Step 2: Correr y ver fallar**

Run: `APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit --no-coverage --filter=ModelPricingTest`
Expected: FAIL `Call to undefined method ...estimateUsageCost()`.

- [ ] **Step 3: Implementar** en `ModelPricing` (debajo de `estimateCost`):

```php
/**
 * Cost of a full text usage: since laravel/ai 1.0 `inputTokens` includes
 * cached tokens, so those are split out and billed at `cached_input`
 * (default: a tenth of the input rate, OpenAI's cached discount).
 */
public function estimateUsageCost(?string $model, TextUsage $usage): float
{
    $entry = $this->resolveEntry($model);

    if ($entry === null) {
        return 0.0;
    }

    $input = (float) ($entry['input'] ?? 0.0);
    $cached = (float) ($entry['cached_input'] ?? $input / 10);
    $cachedTokens = (int) ($usage->cacheReadInputTokens ?? 0);

    return round(
        ($usage->uncachedInputTokens() / self::TOKENS_PER_PRICE_UNIT) * $input
            + ($cachedTokens / self::TOKENS_PER_PRICE_UNIT) * $cached
            + ($usage->outputTokens / self::TOKENS_PER_PRICE_UNIT) * (float) ($entry['output'] ?? 0.0),
        6,
    );
}
```

Añadir `use Laravel\Ai\Responses\Data\TextUsage;` y en el docblock de `resolveEntry` el tipo `cached_input?: float|int`. En `config/ai.php` → `pricing`, agregar `'cached_input' => 0.25` a `gpt-5.4`, `0.075` a `gpt-5.4-mini`, `0.02` a `gpt-5.4-nano` (10 % del input).

- [ ] **Step 4: Usar en evaluadores.** En `SdkEventEvaluationAgent` y `SdkMediaAssessmentAgent` reemplazar `estimateCost($response->meta?->model, (int) ...inputTokens, (int) ...outputTokens)` por `estimateUsageCost($response->meta?->model, $response->usage)`.

- [ ] **Step 5: Correr**

Run: `... vendor/bin/phpunit --no-coverage --filter='ModelPricingTest|EvaluateEventViaSdkTest|EvaluateMediaViaSdkTest'`
Expected: PASS (los fakes no traen caché → mismo costo que antes).

- [ ] **Step 6: Commit**

```bash
git add app/Domains/AI/Support/ModelPricing.php config/ai.php app/Infrastructure/AI/Agents/SdkEventEvaluationAgent.php app/Infrastructure/AI/Agents/SdkMediaAssessmentAgent.php tests/Feature/Domains/AI/ModelPricingTest.php
git commit -m "fix: el costo de ia cobra los tokens cacheados a su tarifa"
```

---

### Task 2: Consumo de combustible y ralentí (helpers de datos)

**Files:**
- Create: `app/Domains/Copilot/Support/FuelConsumption.php`, `app/Domains/Copilot/Support/IdleTimeCalculator.php`, `app/Domains/Copilot/Data/IdleSummary.php`
- Modify: `app/Domains/Copilot/Tools/AssetFuelTool.php` (usar `FuelConsumption`), `app/Domains/Copilot/Tools/AssetEngineTool.php` (agregar ralentí)
- Test: `tests/Feature/Domains/Copilot/FuelConsumptionTest.php`, `tests/Feature/Domains/Copilot/IdleTimeCalculatorTest.php`

**Interfaces:**
- Produces:
  - `FuelConsumption::fromSeries(iterable<AssetTelemetrySnapshot> $series): array{consumed: float, refuels: list<array{at:string,from:float,to:float}>, drops: list<array{at:string,from:float,to:float}>}`; consts `REFUEL_JUMP = 10.0`, `SUDDEN_DROP = 15.0`.
  - `IdleTimeCalculator::forAssets(int $teamId, list<int> $assetIds, CarbonImmutable $from, CarbonImmutable $to): array<int, IdleSummary>`
  - `IdleTimeCalculator::forAsset(Asset $asset, CarbonImmutable $from, CarbonImmutable $to): IdleSummary`
  - `IdleSummary { float $hours; string $source ('engine_state'|'ignition_speed'|'none'); list<array{from:string,to:string,minutes:int}> $segments }`

- [ ] **Step 1: Test de combustible que falla** (`FuelConsumptionTest`, `extends TestCase`, sin DB):

```php
public function test_sums_drops_and_ignores_refuels(): void
{
    $series = collect([[80, 0], [70, 60], [95, 120], [60, 180]])->map(fn ($p) => new AssetTelemetrySnapshot([
        'data_json' => ['value' => $p[0]],
        'recorded_at' => now()->subHours(4)->addMinutes($p[1]),
    ]));

    $result = FuelConsumption::fromSeries($series);

    $this->assertSame(45.0, $result['consumed']);          // 10 + 35
    $this->assertCount(1, $result['refuels']);             // 70 → 95
    $this->assertCount(1, $result['drops']);               // 95 → 60 (≥ 15)
}
```

- [ ] **Step 2: Implementar `FuelConsumption`** moviendo el bucle de `AssetFuelTool` (líneas 67-92) a:

```php
final class FuelConsumption
{
    public const REFUEL_JUMP = 10.0;

    public const SUDDEN_DROP = 15.0;

    /**
     * Percentage points of tank consumed in an ordered series: drops add up,
     * a jump of REFUEL_JUMP or more is a refuel, a drop of SUDDEN_DROP or more
     * is flagged (possible theft or sensor glitch).
     *
     * @param  iterable<AssetTelemetrySnapshot>  $series  ordered by recorded_at
     * @return array{consumed: float, refuels: list<array{at: string, from: float, to: float}>, drops: list<array{at: string, from: float, to: float}>}
     */
    public static function fromSeries(iterable $series): array
    {
        $points = collect($series)->values();
        $consumed = 0.0;
        $refuels = [];
        $drops = [];

        for ($i = 1; $i < $points->count(); $i++) {
            $previous = (float) $points[$i - 1]->data_json['value'];
            $current = (float) $points[$i]->data_json['value'];
            $delta = $current - $previous;
            $mark = ['at' => $points[$i]->recorded_at->toIso8601String(), 'from' => round($previous, 1), 'to' => round($current, 1)];

            if ($delta >= self::REFUEL_JUMP) {
                $refuels[] = $mark;
            } elseif ($delta < 0) {
                $consumed += -$delta;

                if (-$delta >= self::SUDDEN_DROP) {
                    $drops[] = $mark;
                }
            }
        }

        return ['consumed' => round($consumed, 1), 'refuels' => $refuels, 'drops' => $drops];
    }
}
```

En `AssetFuelTool`: borrar `REFUEL_JUMP`/`SUDDEN_DROP` y el bucle; usar `['consumed' => $consumed, 'refuels' => $refuels, 'drops' => $drops] = FuelConsumption::fromSeries($series);`. Actualizar el docblock de la clase (referencia a `FuelConsumption::REFUEL_JUMP`).

- [ ] **Step 3:** Run `--filter='FuelConsumptionTest|SendCopilotMessageTest'` → PASS (el reporte de combustible existente no cambia).

- [ ] **Step 4: Tests de ralentí que fallan** (`IdleTimeCalculatorTest`, `RefreshDatabase`, `CopilotFixtures`). Helper local:

```php
private function engine(Asset $asset, string $state, CarbonImmutable $at): void
{
    AssetTelemetrySnapshot::factory()->create([
        'asset_id' => $asset->id,
        'telemetry_type' => TelemetryType::Ignition,
        'data_json' => ['value' => $state],
        'recorded_at' => $at,
    ]);
}
```

Tests (con `$now = CarbonImmutable::parse('2026-09-30 12:00'); $this->travelTo($now);`, asset con `last_seen_at = $now`):

```php
public function test_sums_idle_segments_clipped_to_the_window(): void
{
    [, $team] = $this->memberWithRole('supervisor');
    $asset = $this->truckWithTelemetry($team);
    $this->engine($asset, 'Idle', $now->subHours(10));   // empieza antes de from
    $this->engine($asset, 'On', $now->subHours(7));
    $this->engine($asset, 'Idle', $now->subHours(3));
    $this->engine($asset, 'Off', $now->subHours(1));

    $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(8), $now);

    $this->assertSame('engine_state', $summary->source);
    $this->assertSame(3.0, $summary->hours);             // 1 h (8→7) + 2 h (3→1)
    $this->assertCount(2, $summary->segments);
}

public function test_open_idle_segment_is_cut_at_last_seen_when_asset_is_silent(): void
{
    // asset last_seen_at = now - 5h; última lectura Idle a now - 6h
    // → tramo 6h→5h = 1.0 h, no 6 h.
}

public function test_falls_back_to_ignition_on_with_speed_below_three_kph(): void
{
    // sólo 'On'/'Off' (sin 'Idle'); On a now-2h, Off a now-1h;
    // AssetLocationSnapshot speed 0 de now-2h a now-1h40 (cada 2 min) y 50 km/h después
    // → source 'ignition_speed', hours = round(20/60, 2) = 0.33
}

public function test_short_stops_under_three_minutes_do_not_count(): void
{
    // On + dos puntos speed 0 separados 1 min → hours 0.0
}

public function test_batch_never_reads_other_tenants(): void
{
    // asset de teamB con Idle; forAssets($teamA->id, [$assetB->id], ...) → [] (vacío)
}
```

Escribir el cuerpo completo de los 4 últimos siguiendo el primero (mismo helper `engine()`; `AssetLocationSnapshot::factory()->create(['asset_id' => ..., 'speed' => 0, 'recorded_at' => ...])`).

- [ ] **Step 5: Implementar `IdleSummary`** (readonly, 3 props) y **`IdleTimeCalculator`**:

```php
final class IdleTimeCalculator
{
    public const STOPPED_SPEED_KPH = 3.0;

    public const MIN_STOP_MINUTES = 3;

    public const SILENT_AFTER_MINUTES = 30;

    /**
     * @param  list<int>  $assetIds
     * @return array<int, IdleSummary> keyed by asset id (only this team's assets)
     */
    public function forAssets(int $teamId, array $assetIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assets = Asset::query()->where('team_id', $teamId)->whereIn('id', $assetIds)->get(['id', 'last_seen_at'])->keyBy('id');

        if ($assets->isEmpty()) {
            return [];
        }

        $ids = $assets->keys()->all();

        // State in force at `from`: newest reading before it, per asset.
        $before = AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->whereIn('id', AssetTelemetrySnapshot::query()
                ->selectRaw('max(id)')
                ->whereIn('asset_id', $ids)
                ->where('telemetry_type', TelemetryType::Ignition)
                ->where('recorded_at', '<', $from)
                ->groupBy('asset_id'))
            ->get(['asset_id', 'data_json', 'recorded_at']);

        $inside = AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['asset_id', 'data_json', 'recorded_at']);

        $readings = $before->concat($inside)->groupBy('asset_id');
        $summaries = [];

        foreach ($assets as $id => $asset) {
            $series = $readings->get($id, collect())->sortBy('recorded_at')->values();
            $end = $this->effectiveEnd($asset->last_seen_at, $to);
            $summaries[$id] = $series->contains(fn ($r) => $this->state($r) === 'idle')
                ? $this->fromEngineStates($series, $from, $end)
                : $this->fromIgnitionAndSpeed((int) $id, $series, $from, $end);
        }

        return $summaries;
    }

    public function forAsset(Asset $asset, CarbonImmutable $from, CarbonImmutable $to): IdleSummary
    {
        return $this->forAssets((int) $asset->team_id, [(int) $asset->id], $from, $to)[(int) $asset->id]
            ?? new IdleSummary(0.0, 'none', []);
    }

    private function state(AssetTelemetrySnapshot $reading): string
    {
        return strtolower((string) ($reading->data_json['value'] ?? ''));
    }

    /** An asset silent for > SILENT_AFTER_MINUTES has no data after last_seen_at. */
    private function effectiveEnd(?CarbonInterface $lastSeen, CarbonImmutable $to): CarbonImmutable
    {
        if ($lastSeen === null) {
            return $to;
        }

        $lastSeen = CarbonImmutable::parse($lastSeen);

        return $lastSeen->lt($to->subMinutes(self::SILENT_AFTER_MINUTES)) ? $lastSeen : $to;
    }

    /**
     * Readings are stored only on change: each one lasts until the next.
     *
     * @param  Collection<int, AssetTelemetrySnapshot>  $series
     */
    private function fromEngineStates(Collection $series, CarbonImmutable $from, CarbonImmutable $end): IdleSummary
    {
        $segments = [];

        foreach ($series as $i => $reading) {
            if ($this->state($reading) !== 'idle') {
                continue;
            }

            $start = CarbonImmutable::parse($reading->recorded_at)->max($from);
            $next = $series->get($i + 1);
            $stop = ($next ? CarbonImmutable::parse($next->recorded_at) : $end)->min($end);

            if ($stop->gt($start)) {
                $segments[] = $this->segment($start, $stop);
            }
        }

        return $this->summary($segments, 'engine_state');
    }

    /**
     * Providers without an Idle state: ignition On while GPS says stopped
     * (< STOPPED_SPEED_KPH) for at least MIN_STOP_MINUTES.
     *
     * @param  Collection<int, AssetTelemetrySnapshot>  $series
     */
    private function fromIgnitionAndSpeed(int $assetId, Collection $series, CarbonImmutable $from, CarbonImmutable $end): IdleSummary
    {
        $onWindows = [];

        foreach ($series as $i => $reading) {
            if ($this->state($reading) !== 'on') {
                continue;
            }

            $next = $series->get($i + 1);
            $onWindows[] = [
                CarbonImmutable::parse($reading->recorded_at)->max($from),
                ($next ? CarbonImmutable::parse($next->recorded_at) : $end)->min($end),
            ];
        }

        if ($onWindows === []) {
            return new IdleSummary(0.0, 'none', []);
        }

        $points = AssetLocationSnapshot::query()
            ->where('asset_id', $assetId)
            ->whereBetween('recorded_at', [$from, $end])
            ->orderBy('recorded_at')
            ->get(['speed', 'recorded_at']);

        $segments = [];

        foreach ($onWindows as [$windowStart, $windowEnd]) {
            $runStart = null;
            $runEnd = null;

            foreach ($points as $point) {
                $at = CarbonImmutable::parse($point->recorded_at);

                if ($at->lt($windowStart) || $at->gt($windowEnd)) {
                    continue;
                }

                if ((float) $point->speed < self::STOPPED_SPEED_KPH) {
                    $runStart ??= $at;
                    $runEnd = $at;

                    continue;
                }

                $this->closeRun($segments, $runStart, $runEnd);
                $runStart = $runEnd = null;
            }

            $this->closeRun($segments, $runStart, $runEnd);
        }

        return $this->summary($segments, 'ignition_speed');
    }

    /** @param list<array{from: string, to: string, minutes: int}> $segments */
    private function closeRun(array &$segments, ?CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        if ($start !== null && $end !== null && $start->diffInMinutes($end) >= self::MIN_STOP_MINUTES) {
            $segments[] = $this->segment($start, $end);
        }
    }

    /** @return array{from: string, to: string, minutes: int} */
    private function segment(CarbonImmutable $start, CarbonImmutable $stop): array
    {
        return ['from' => $start->toIso8601String(), 'to' => $stop->toIso8601String(), 'minutes' => (int) round($start->diffInMinutes($stop))];
    }

    /** @param list<array{from: string, to: string, minutes: int}> $segments */
    private function summary(array $segments, string $source): IdleSummary
    {
        $minutes = array_sum(array_column($segments, 'minutes'));

        return new IdleSummary(round($minutes / 60, 2), $segments === [] ? 'none' : $source, $segments);
    }
}
```

Nota: `AssetTelemetrySnapshot`/`AssetLocationSnapshot` no llevan `team_id`; el aislamiento lo da filtrar `asset_id` sólo sobre assets ya filtrados por `team_id` (primer query). Mantener ese orden.

- [ ] **Step 6: Ralentí en `AssetEngineTool`.** Inyectar `IdleTimeCalculator` por constructor; tras armar `$readings`, calcular `$idle = $this->idle->forAsset($asset, $context->period->from, $context->period->to)`; si `$idle->hours > 0` agregar `$facts['idle_hours'] = $idle->hours; $facts['idle_source'] = $idle->source;` y el highlight `"Ralentí: {$idle->hours} h en {$period->label}."`. Agregar a `IdleTimeCalculatorTest` un `test_engine_tool_reports_idle_hours` que corre `app(AssetEngineTool::class)->run($context)` y verifica `facts['idle_hours']`.

- [ ] **Step 7:** Run `--filter='IdleTimeCalculatorTest|FuelConsumptionTest|SendCopilotMessageTest'` → PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Domains/Copilot/Support/FuelConsumption.php app/Domains/Copilot/Support/IdleTimeCalculator.php app/Domains/Copilot/Data/IdleSummary.php app/Domains/Copilot/Tools/AssetFuelTool.php app/Domains/Copilot/Tools/AssetEngineTool.php tests/Feature/Domains/Copilot/FuelConsumptionTest.php tests/Feature/Domains/Copilot/IdleTimeCalculatorTest.php
git commit -m "feat: ralentí desde engineStates y consumo de combustible reutilizable"
```

---

### Task 3: Tools del SDK sobre las tools existentes (scope, permisos, log)

**Files:**
- Create: `app/Domains/Copilot/Data/CopilotTurnScope.php`, `app/Domains/Copilot/Support/CopilotTurnCollector.php`, `app/Domains/Copilot/Tools/Sdk/{SdkCopilotTool,DelegatingCopilotTool,CopilotToolDefinition}.php`, `app/Domains/Copilot/Support/CopilotToolbox.php`
- Modify: `app/Domains/Copilot/Data/CopilotToolContext.php` (+`array $arguments = []`), `app/Domains/Copilot/Data/CopilotPeriod.php` (+`between`), `app/Domains/Copilot/Support/AssetResolver.php` (+`resolveByCode`), `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Copilot/SdkCopilotToolTest.php`, `CopilotToolPermissionsTest.php`, `CopilotAgentTenantLeakTest.php`

**Interfaces:**
- Produces:
  - `CopilotTurnScope(int $teamId, string $teamSlug, array $permissions, bool $isSuperAdmin, string $timezone, CarbonImmutable $now)` + `can(string): bool`, `static fromTeam(Team, array $permissions, bool $isSuperAdmin): self`.
  - `CopilotTurnCollector`: `record(string $toolCallId, string $tool, CopilotToolResult $result, string $status, int $durationMs, ?int $assetId): void` · `followups(list<string>): void` · `onBlocks(Closure(array{toolCallId:string,tool:string,label:string,blocks:list<array>}): void): void` · `blocks(): list<array>` · `sources(): list<array>` · `tools(): list<array{tool,label,status,durationMs}>` · `factsDigest(): string` (≤ 1500 chars) · `followupList(): list<string>` · `primaryIntent(): CopilotIntent` · `lastAssetId(): ?int` · `hasResults(): bool`.
  - `SdkCopilotTool` (abstract, `implements Laravel\Ai\Contracts\Tool`): ctor `(CopilotTurnScope $scope, CopilotTurnCollector $collector)`; abstract `name(): string`, `description(): string`, `permission(): ?string`, `intent(): CopilotIntent`, `rules(): array`, `schema(JsonSchema $schema): array`, `protected execute(array $args): CopilotToolResult`.
  - `CopilotToolbox::for(CopilotTurnScope $scope, CopilotTurnCollector $collector): list<SdkCopilotTool>` (filtra por permiso).
  - `CopilotPeriod::between(CarbonImmutable $from, CarbonImmutable $to): self`.
  - `AssetResolver::resolveByCode(int $teamId, string $code): ?Asset`.

- [ ] **Step 1: Tests que fallan — `SdkCopilotToolTest`** (`RefreshDatabase`, `CopilotFixtures`, `AssertsSystemLog`):

```php
private function tool(Team $team, User $user, string $name): SdkCopilotTool
{
    $scope = CopilotTurnScope::fromTeam($team, app(AuthorizeAction::class)->resolvePermissions($user, $team), false);
    $this->collector = new CopilotTurnCollector;

    return collect(app(CopilotToolbox::class)->for($scope, $this->collector))
        ->first(fn (SdkCopilotTool $t) => $t->name() === $name);
}

public function test_runs_the_domain_tool_and_feeds_the_collector(): void
{
    [$user, $team] = $this->memberWithRole('supervisor');
    $this->truckWithTelemetry($team);

    $out = json_decode((string) $this->tool($team, $user, 'asset_location')
        ->handle(new Request(['asset_code' => 'T555'], 'call_1')), true);

    $this->assertArrayHasKey('facts', $out);
    $this->assertNotEmpty($this->collector->blocks());
    $this->assertSame('asset_location', $this->collector->tools()[0]['tool']);
    $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'asset_location' && $c['input']['tool_call_id'] === 'call_1');
    $this->assertNoSensitiveDataLogged();
}

public function test_unknown_unit_returns_error_to_the_model(): void
{
    [$user, $team] = $this->memberWithRole('supervisor');

    $out = json_decode((string) $this->tool($team, $user, 'asset_location')->handle(new Request(['asset_code' => 'ZZ999'], 'c')), true);

    $this->assertSame('unidad no encontrada', $out['error']);
    $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'asset_not_found');
}

public function test_invalid_dates_are_rejected_without_touching_data(): void
{
    [$user, $team] = $this->memberWithRole('supervisor');

    $out = json_decode((string) $this->tool($team, $user, 'fleet_overview')
        ->handle(new Request(['from' => 'ayer', 'to' => '2026-01-01T00:00:00Z'], 'c')), true);

    $this->assertSame('argumentos inválidos', $out['error']);
    $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed' && in_array('from', $c['input']['fields'], true));
}

public function test_range_over_ninety_days_is_rejected(): void { /* from = now-120d, to = now → error + validation_failed */ }

public function test_tool_exception_becomes_an_error_for_the_model(): void
{
    // bind un CopilotTool de dominio que lanza RuntimeException para 'asset_summary':
    $this->app->bind(AssetSummaryTool::class, fn () => new class implements CopilotTool {
        public function run(CopilotToolContext $c): CopilotToolResult { throw new \RuntimeException('boom'); }
    });
    // ...handle(['asset_code' => 'T555']) → ['error' => 'no pude consultar Resumen de unidad']
    // assertSystemLogged('copilot.tool.failed', reason tool_exception)
    // collector->tools()[0]['status'] === 'error'
}

public function test_output_over_six_kilobytes_is_truncated(): void { /* fleet_overview con 120 assets → strlen(output) <= 6144 y 'truncado' => true */ }
```

**`CopilotToolPermissionsTest`:**

```php
public function test_viewer_without_incidents_permission_never_sees_incident_tools(): void
{
    [$user, $team] = $this->memberWithRole('viewer');   // confirmar en AccessSeeder qué rol no tiene incidents.view; si viewer lo tiene, usar un rol custom con sólo assets.view
    $names = array_map(fn ($t) => $t->name(), app(CopilotToolbox::class)->for(CopilotTurnScope::fromTeam($team, ['assets.view'], false), new CopilotTurnCollector));

    $this->assertContains('fleet_overview', $names);
    $this->assertNotContains('open_incidents', $names);
    $this->assertNotContains('panic_kpis', $names);
    $this->assertNotContains('asset_activity', $names);
}

public function test_super_admin_gets_every_tool(): void { /* isSuperAdmin true, permisos [] → 15 tools (10 + 4 nuevas + suggest_followups; en este task: 10 + suggest? no — suggest llega en Task 5; aquí 10) */ }
```

**`CopilotAgentTenantLeakTest`** (usa `AssertsTenantIsolation`):

```php
public function test_no_tool_reads_another_tenants_unit(): void
{
    [$user, $team] = $this->memberWithRole('supervisor');
    [, $other] = $this->memberWithRole('supervisor');
    $this->truckWithTelemetry($other, 'T777');

    $scope = CopilotTurnScope::fromTeam($team, app(AuthorizeAction::class)->resolvePermissions($user, $team), false);
    $tools = app(CopilotToolbox::class)->for($scope, new CopilotTurnCollector);

    $this->assertNoTenantLeak($other, function () use ($tools) {
        foreach ($tools as $tool) {
            $out = json_decode((string) $tool->handle(new Request(['asset_code' => 'T777', 'query' => 'T777'], 'c')), true);
            $this->assertStringNotContainsString('T777', json_encode($out['facts'] ?? []));
        }
    });
}
```

(En Task 4 se amplía este test con las tools nuevas; ver allí.)

- [ ] **Step 2: Correr** `--filter='SdkCopilotToolTest|CopilotToolPermissionsTest|CopilotAgentTenantLeakTest'` → FAIL (clases no existen).

- [ ] **Step 3: `CopilotTurnScope`:**

```php
final readonly class CopilotTurnScope
{
    /** @param list<string> $permissions */
    public function __construct(
        public int $teamId,
        public string $teamSlug,
        public array $permissions,
        public bool $isSuperAdmin,
        public string $timezone,
        public CarbonImmutable $now,
    ) {}

    /** @param list<string> $permissions */
    public static function fromTeam(Team $team, array $permissions, bool $isSuperAdmin): self
    {
        return new self((int) $team->id, (string) $team->slug, $permissions, $isSuperAdmin, $team->timezone ?: config('app.timezone'), CarbonImmutable::now());
    }

    public function can(?string $permission): bool
    {
        return $permission === null || $this->isSuperAdmin || in_array($permission, $this->permissions, true);
    }
}
```

- [ ] **Step 4: `CopilotPeriod::between`** (usa `days = max(1, (int) ceil(from->diffInDays(to)))`, label `"del {from d/m} al {to d/m}"`), `CopilotToolContext` + `public array $arguments = []` (último param), `AssetResolver::resolveByCode`:

```php
public function resolveByCode(int $teamId, string $code): ?Asset
{
    $key = CopilotText::key($code);

    return $key === '' ? null : Asset::query()->where('team_id', $teamId)->get(['id', 'team_id', 'code', 'name', 'asset_type_id', 'last_seen_at', 'status'])
        ->first(fn (Asset $a) => CopilotText::key($a->code) === $key || CopilotText::key($a->name) === $key)
        ?->fresh();
}
```

(Si el tenant puede tener >5000 assets, filtrar primero con `whereRaw('lower(code) = ?', [mb_strtolower($code)])` y caer al barrido sólo si no hay match exacto.)

- [ ] **Step 5: `CopilotTurnCollector`:**

```php
final class CopilotTurnCollector
{
    /** @var list<array{toolCallId: string, tool: string, result: CopilotToolResult, status: string, durationMs: int, assetId: int|null}> */
    private array $entries = [];

    /** @var list<string> */
    private array $followups = [];

    private ?Closure $onBlocks = null;

    public function onBlocks(Closure $listener): void
    {
        $this->onBlocks = $listener;
    }

    public function record(string $toolCallId, string $tool, CopilotToolResult $result, string $status, int $durationMs, ?int $assetId): void
    {
        $this->entries[] = compact('toolCallId', 'tool', 'result', 'status', 'durationMs', 'assetId');

        if ($result->blocks !== [] && $this->onBlocks !== null) {
            ($this->onBlocks)(['toolCallId' => $toolCallId, 'tool' => $tool, 'label' => $result->label, 'blocks' => $result->blocks]);
        }
    }

    /** @param list<string> $questions */
    public function followups(array $questions): void
    {
        $this->followups = array_values(array_slice($questions, 0, 3));
    }

    public function hasResults(): bool { return $this->entries !== []; }

    /** @return list<array<string, mixed>> */
    public function blocks(): array { return array_merge([], ...array_map(fn ($e) => $e['result']->blocks, $this->entries)); }

    /** @return list<array{kind: string, id: int, label: string, href: string}> */
    public function sources(): array
    {
        $sources = [];
        foreach ($this->entries as $e) {
            foreach ($e['result']->sources as $s) {
                $sources[$s['kind'].':'.$s['id']] = $s;
            }
        }

        return array_values(array_slice($sources, 0, 12));
    }

    /** @return list<array{tool: string, label: string, status: string, durationMs: int}> */
    public function tools(): array
    {
        return array_map(fn ($e) => ['tool' => $e['tool'], 'label' => $e['result']->label, 'status' => $e['status'], 'durationMs' => $e['durationMs']], $this->entries);
    }

    /** Compact memory of what was looked up, fed back as history next turn. */
    public function factsDigest(): string
    {
        $parts = array_map(fn ($e) => $e['tool'].': '.implode(' ', $e['result']->highlights), array_filter($this->entries, fn ($e) => $e['status'] === 'ok'));

        return mb_substr(implode(' | ', $parts), 0, 1500);
    }

    /** @return list<string> */
    public function followupList(): array { return $this->followups; }

    public function primaryIntent(): CopilotIntent
    {
        foreach ($this->entries as $e) {
            if ($intent = CopilotToolDefinition::intentFor($e['tool'])) {
                return $intent;
            }
        }

        return CopilotIntent::General;
    }

    public function lastAssetId(): ?int
    {
        return collect($this->entries)->pluck('assetId')->filter()->last();
    }
}
```

- [ ] **Step 6: `CopilotToolDefinition`** — registro estático de las 10 tools existentes (y, en Task 4, las nuevas):

```php
final readonly class CopilotToolDefinition
{
    /** @param class-string<CopilotTool> $toolClass */
    public function __construct(
        public string $name,
        public string $description,
        public string $toolClass,
        public ?string $permission,
        public CopilotIntent $intent,
        public bool $needsAsset,
    ) {}

    /** @return array<string, self> */
    public static function all(): array
    {
        return collect([
            new self('asset_summary', 'Ficha de una unidad: estado, conductor asignado, última señal. Requiere asset_code.', AssetSummaryTool::class, 'assets.view', CopilotIntent::AssetReport, true),
            new self('asset_location', 'Ubicación actual, velocidad y recorrido reciente de una unidad. Requiere asset_code.', AssetLocationTool::class, 'assets.view', CopilotIntent::AssetLocation, true),
            new self('asset_engine', 'Motor de una unidad: ignición, odómetro, km recorridos, batería, temperatura y horas de ralentí en el periodo. Requiere asset_code.', AssetEngineTool::class, 'assets.view', CopilotIntent::EngineStats, true),
            new self('asset_fuel', 'Combustible de una unidad (% de tanque): nivel actual, consumo, recargas y caídas bruscas en el periodo. Requiere asset_code.', AssetFuelTool::class, 'assets.view', CopilotIntent::FuelReport, true),
            new self('asset_media', 'Últimas fotos y videos de cámara de una unidad. Requiere asset_code.', AssetMediaTool::class, 'context.view', CopilotIntent::AssetMedia, true),
            new self('asset_activity', 'Eventos por día e incidentes de una unidad en el periodo. Requiere asset_code.', AssetActivityTool::class, 'incidents.view', CopilotIntent::AssetReport, true),
            new self('panic_kpis', 'KPIs de botón de pánico en el periodo: cantidad, tiempos de atención, unidades. asset_code opcional.', PanicKpisTool::class, 'incidents.view', CopilotIntent::PanicKpis, false),
            new self('open_incidents', 'Incidentes abiertos ahora, por severidad y SLA. asset_code opcional.', OpenIncidentsTool::class, 'incidents.view', CopilotIntent::OpenIncidents, false),
            new self('driver_ranking', 'Ranking de conductores por riesgo y fatiga.', DriverRankingTool::class, 'drivers.view', CopilotIntent::DriverRanking, false),
            new self('fleet_overview', 'Estado de toda la flota o una categoría: en ruta, detenidas, sin señal, en alerta, con mapa.', FleetOverviewTool::class, 'assets.view', CopilotIntent::FleetOverview, false),
        ])->keyBy('name')->all();
    }

    public static function intentFor(string $tool): ?CopilotIntent
    {
        return self::all()[$tool]->intent ?? null;
    }
}
```

**Verificar** el permiso real de cada tool leyendo su `run()` (`asset_summary`/`asset_location`/`asset_engine`/`asset_fuel`/`fleet_overview` → `assets.view`; `asset_media` → `context.view`; `asset_activity`/`panic_kpis`/`open_incidents` → `incidents.view`; `driver_ranking` → `drivers.view`) y ajustar si difiere.

- [ ] **Step 7: `SdkCopilotTool`:**

```php
abstract class SdkCopilotTool implements Tool
{
    private const MAX_OUTPUT_BYTES = 6144;

    public function __construct(
        protected readonly CopilotTurnScope $scope,
        protected readonly CopilotTurnCollector $collector,
    ) {}

    abstract public function name(): string;

    abstract public function permission(): ?string;

    abstract public function intent(): CopilotIntent;

    /** @return array<string, mixed> Laravel validation rules for the model's arguments. */
    abstract protected function rules(): array;

    /** @param array<string, mixed> $args validated */
    abstract protected function execute(array $args, string $toolCallId): CopilotToolResult;

    public function handle(Request $request): Stringable|string
    {
        $callId = (string) ($request->toolCallId() ?? 'call');
        $log = ['team_id' => $this->scope->teamId, 'tool' => $this->name(), 'tool_call_id' => $callId];

        try {
            $args = $request->validate($this->rules() + $this->periodRules());
        } catch (ValidationException $e) {
            SystemLog::skipped('copilot.tool.invalid_args', 'validation_failed', [...$log, 'fields' => array_keys($e->errors())]);

            return $this->json(['error' => 'argumentos inválidos', 'detalle' => $e->errors()]);
        }

        $started = hrtime(true);

        try {
            $result = TenantContext::for($this->scope->teamId, fn () => $this->execute($args, $callId));
        } catch (CopilotAssetNotFound) {
            SystemLog::skipped('copilot.tool.invalid_args', 'asset_not_found', [...$log, 'fields' => ['asset_code']]);

            return $this->json(['error' => 'unidad no encontrada', 'sugerencia' => 'usa find_assets para buscarla']);
        } catch (Throwable $e) {
            $label = $this->label();
            $this->collector->record($callId, $this->name(), new CopilotToolResult($this->name(), $label), 'error', SystemLog::elapsedMs($started), null);
            SystemLog::degraded('copilot.tool.failed', 'tool_exception', $log, error: $e);

            return $this->json(['error' => "no pude consultar {$label}"]);
        }

        $durationMs = SystemLog::elapsedMs($started);
        $denied = $result->facts === [] && str_starts_with($result->highlights[0] ?? '', 'No tienes permiso');
        $this->collector->record($callId, $this->name(), $result, $denied ? 'denied' : 'ok', $durationMs, $args['__asset_id'] ?? null);

        if ($denied) {
            SystemLog::skipped('copilot.tool.denied', 'missing_permission', [...$log, 'permission' => $this->permission()]);
        }

        [$payload, $truncated] = $this->compact(['facts' => $result->facts, 'highlights' => $result->highlights]);

        SystemLog::ok('copilot.tool.ran', [...$log, 'asset_id' => $args['__asset_id'] ?? null, 'period_days' => $args['__period_days'] ?? null], result: ['rows' => count($result->blocks), 'truncated' => $truncated], durationMs: $durationMs);

        return $payload;
    }

    public function label(): string
    {
        return CopilotToolDefinition::all()[$this->name()]->intent->label() ?? $this->name();
    }

    /** from/to ISO-8601, to > from, at most 90 days: every tool shares them. */
    private function periodRules(): array
    {
        return [
            'from' => ['nullable', 'date', 'before:to'],
            'to' => ['nullable', 'date', 'after:from', 'before_or_equal:'.$this->scope->now->addMinute()->toIso8601String()],
        ];
    }

    /** @param array<string, mixed> $args */
    protected function period(array $args): CopilotPeriod
    {
        $to = isset($args['to']) ? CarbonImmutable::parse($args['to']) : $this->scope->now;
        $from = isset($args['from']) ? CarbonImmutable::parse($args['from']) : $to->subDays(7);

        if ($from->diffInDays($to) > 90) {
            throw ValidationException::withMessages(['from' => 'El rango máximo es de 90 días.']);
        }

        return CopilotPeriod::between($from, $to);
    }

    /** @return array{0: string, 1: bool} */
    private function compact(array $payload): array
    {
        $json = $this->json($payload);

        if (strlen($json) <= self::MAX_OUTPUT_BYTES) {
            return [$json, false];
        }

        $payload['facts'] = ['resumen' => mb_substr(json_encode($payload['facts'], JSON_UNESCAPED_UNICODE), 0, 4000)];
        $payload['truncado'] = true;

        return [$this->json($payload), true];
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
```

Nota: el `ValidationException` que lanza `period()` dentro de `execute` debe capturarse igual que el de `validate()`: mover la llamada a `period()` a antes del `try` de `execute` (llamarla justo después de `validate()`, guardando `$args['__period']`). Crear `app/Domains/Copilot/Tools/Sdk/CopilotAssetNotFound.php` (`final class ... extends RuntimeException {}`).

- [ ] **Step 8: `DelegatingCopilotTool`:**

```php
final class DelegatingCopilotTool extends SdkCopilotTool
{
    public function __construct(
        CopilotTurnScope $scope,
        CopilotTurnCollector $collector,
        private readonly CopilotToolDefinition $definition,
        private readonly AssetResolver $assets,
        private readonly Container $container,
    ) {
        parent::__construct($scope, $collector);
    }

    public function name(): string { return $this->definition->name; }

    public function description(): Stringable|string { return $this->definition->description; }

    public function permission(): ?string { return $this->definition->permission; }

    public function intent(): CopilotIntent { return $this->definition->intent; }

    public function schema(JsonSchema $schema): array
    {
        return array_filter([
            'asset_code' => $this->definition->needsAsset
                ? $schema->string()->description('Número económico o nombre de la unidad, p. ej. T555.')->required()
                : $schema->string()->description('Opcional: limitar a una unidad.'),
            'from' => $schema->string()->description('Inicio ISO-8601 con zona horaria. Omite para últimos 7 días.'),
            'to' => $schema->string()->description('Fin ISO-8601 con zona horaria. Omite para ahora.'),
            'category' => $schema->string()->enum(array_column(AssetCategory::cases(), 'value'))->description('Opcional: categoría de unidad.'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'asset_code' => [$this->definition->needsAsset ? 'required' : 'nullable', 'string', 'max:40'],
            'category' => ['nullable', Rule::enum(AssetCategory::class)],
        ];
    }

    protected function execute(array $args, string $toolCallId): CopilotToolResult
    {
        $asset = null;

        if (! empty($args['asset_code'])) {
            $asset = $this->assets->resolveByCode($this->scope->teamId, $args['asset_code']) ?? throw new CopilotAssetNotFound;
        }

        $context = new CopilotToolContext(
            teamId: $this->scope->teamId,
            teamSlug: $this->scope->teamSlug,
            permissions: $this->scope->permissions,
            period: $args['__period'],
            asset: $asset,
            category: isset($args['category']) ? AssetCategory::from($args['category']) : null,
            isSuperAdmin: $this->scope->isSuperAdmin,
            arguments: $args,
        );

        return $this->container->make($this->definition->toolClass)->run($context);
    }
}
```

Para que `__asset_id` llegue al log/collector: en `execute`, asignar `$args['__asset_id']` no se propaga (array por valor). Cambiar la firma abstracta a `execute(array &$args, string $toolCallId)` y setear `$args['__asset_id'] = $asset?->id;` y `$args['__period_days'] = $args['__period']->days;`.

- [ ] **Step 9: `CopilotToolbox`:**

```php
final class CopilotToolbox
{
    public function __construct(private readonly Container $container) {}

    /** @return list<SdkCopilotTool> */
    public function for(CopilotTurnScope $scope, CopilotTurnCollector $collector): array
    {
        $tools = array_map(
            fn (CopilotToolDefinition $d) => $this->container->make(DelegatingCopilotTool::class, ['scope' => $scope, 'collector' => $collector, 'definition' => $d]),
            array_values(CopilotToolDefinition::all()),
        );

        return array_values(array_filter($tools, fn (SdkCopilotTool $t) => $scope->can($t->permission())));
    }
}
```

- [ ] **Step 10: Logging doc.** En `docs/SAM/logging.md`, sección "IA (`ai`) y copiloto (`copilot`)", agregar filas de `copilot.tool.ran`, `copilot.tool.denied`, `copilot.tool.failed`, `copilot.tool.invalid_args` con los campos del spec (sección Logging).

- [ ] **Step 11:** Run `--filter='SdkCopilotToolTest|CopilotToolPermissionsTest|CopilotAgentTenantLeakTest|Copilot'` → PASS. Además `--filter=LoggingConventionsTest` → PASS.

- [ ] **Step 12: Commit**

```bash
git add app/Domains/Copilot tests/Feature/Domains/Copilot docs/SAM/logging.md
git commit -m "feat: tools del sdk para copilot con scope de tenant y permisos"
```

---

### Task 4: Tools nuevas — find_assets, rank_assets, search_events, asset_timeline

**Files:**
- Create: `app/Domains/Copilot/Tools/{FindAssetsTool,RankAssetsTool,SearchEventsTool,AssetTimelineTool}.php`, `app/Domains/Copilot/Tools/Sdk/ArgumentedCopilotTool.php`
- Modify: `app/Domains/Copilot/Enums/CopilotIntent.php` (+`AssetRanking='asset_ranking'`, `EventSearch='event_search'`, `AssetTimeline='asset_timeline'` con labels "Ranking de unidades", "Búsqueda de eventos", "Línea de tiempo de unidad"), `CopilotToolDefinition` (4 entradas), `resources/js/types/copilot.ts` (union `CopilotIntent`), `CopilotAgentTenantLeakTest`
- Test: `tests/Feature/Domains/Copilot/{FindAssetsToolTest,RankAssetsToolTest,SearchEventsToolTest,AssetTimelineToolTest}.php`

**Interfaces:**
- Consumes: `CopilotToolContext::$arguments`, `IdleTimeCalculator::forAssets`, `FuelConsumption::fromSeries`, `CopilotToolDefinition`.
- Produces: `CopilotToolDefinition` gana `public array $extraSchema` resuelto por closure: para no complicar el registro, las 4 tools nuevas son subclases de `ArgumentedCopilotTool extends DelegatingCopilotTool` que sobreescriben `schema()` y `rules()`. Blocks nuevos: `{type:'ranking', metric, unit, items:[{assetId, code, name, value, outlier, href}], average}` y `{type:'timeline', items:[{at, kind:'event'|'incident'|'idle', label, severity?, href?, minutes?}]}`.

- [ ] **Step 1: Tests que fallan.**

`RankAssetsToolTest` (vía `CopilotToolbox` y `handle()`, como Task 3):

```php
public function test_ranks_idle_hours_and_flags_the_outlier(): void
{
    // 6 camiones del tenant; 5 con 1 h de Idle y T555 con 9 h dentro de los últimos 7 días
    $out = $this->run('rank_assets', ['metric' => 'idle_hours', 'order' => 'desc', 'limit' => 5]);

    $this->assertSame('T555', $out['facts']['items'][0]['code']);
    $this->assertTrue($out['facts']['items'][0]['outlier']);
    $this->assertEqualsWithDelta(2.33, $out['facts']['average'], 0.01);
    $block = collect($this->collector->blocks())->firstWhere('type', 'ranking');
    $this->assertSame('h', $block['unit']);
}

public function test_ranks_fuel_used_excluding_refuels(): void { /* 2 camiones: A baja 80→40 (40), B baja 90→70, recarga 70→95, baja 95→85 (30) → A primero con 40.0 */ }

public function test_ranks_distance_from_odometer_delta(): void { /* odómetro 1000→1250 vs 500→520 → 250.0 primero, unidad 'km' */ }

public function test_ranks_incidents_requires_incident_permission(): void { /* scope con sólo assets.view: metric=incidents → error 'argumentos inválidos' o denied; confirmar denied y copilot.tool.denied */ }

public function test_rank_without_telemetry_returns_notice(): void { /* sin snapshots → items [] y block notice "No hay datos de ralentí..." */ }

public function test_unknown_metric_is_rejected(): void { /* metric=velocidad → invalid_args */ }
```

`FindAssetsToolTest`: busca por código parcial (`t5` → T555, T512), por nombre (`kenworth`), respeta `limit`, filtra categoría, **no devuelve assets de otro tenant**.

`SearchEventsToolTest`: `NormalizedEvent` en rango/fuera de rango; filtra `event_type` (código de `EventType`) y `severity` (código de `EventSeverity`); `facts.by_type` con conteos; `limit` 25; `asset_code` opcional (unidad inexistente → error).

`AssetTimelineToolTest`: une eventos, incidentes y tramos de ralentí ≥ 10 min en orden cronológico; tramo de 5 min no aparece; asset de otro tenant → "unidad no encontrada".

Ampliar `CopilotAgentTenantLeakTest::test_no_tool_reads_another_tenants_unit` para que el tenant B tenga eventos, incidentes, telemetría de ralentí/combustible/odómetro, y que `rank_assets` (cada métrica) y `search_events` sobre el tenant A no devuelvan nada de B (`assertNoTenantLeak`).

- [ ] **Step 2:** Run `--filter='RankAssetsToolTest|FindAssetsToolTest|SearchEventsToolTest|AssetTimelineToolTest'` → FAIL.

- [ ] **Step 3: `ArgumentedCopilotTool`** — clase abstracta que extiende `DelegatingCopilotTool` y expone:

```php
abstract class ArgumentedCopilotTool extends DelegatingCopilotTool
{
    /** @return array<string, Type> extra JSON-schema args */
    abstract protected function extraSchema(JsonSchema $schema): array;

    /** @return array<string, mixed> extra validation rules */
    abstract protected function extraRules(): array;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + $this->extraSchema($schema);
    }

    protected function rules(): array
    {
        return parent::rules() + $this->extraRules();
    }
}
```

Cambiar `DelegatingCopilotTool` de `final` a no-final. Registrar en `CopilotToolDefinition` un campo `?class-string<SdkCopilotTool> $sdkClass = null` y en `CopilotToolbox` usar `$d->sdkClass ?? DelegatingCopilotTool::class`.

- [ ] **Step 4: `RankAssetsTool`** (dominio, `implements CopilotTool`):

```php
final class RankAssetsTool implements CopilotTool
{
    public const METRICS = [
        'fuel_used_pct' => ['label' => 'Combustible consumido', 'unit' => '% tanque', 'permission' => 'assets.view'],
        'distance_km' => ['label' => 'Distancia recorrida', 'unit' => 'km', 'permission' => 'assets.view'],
        'idle_hours' => ['label' => 'Ralentí', 'unit' => 'h', 'permission' => 'assets.view'],
        'incidents' => ['label' => 'Incidentes', 'unit' => 'incidentes', 'permission' => 'incidents.view'],
        'events' => ['label' => 'Eventos', 'unit' => 'eventos', 'permission' => 'incidents.view'],
        'panics' => ['label' => 'Pánicos', 'unit' => 'pánicos', 'permission' => 'incidents.view'],
    ];

    public function __construct(private readonly IdleTimeCalculator $idle) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        $metric = $context->arguments['metric'];
        $meta = self::METRICS[$metric];

        if (! $context->can($meta['permission'])) {
            return CopilotToolResult::denied('rank_assets', 'Ranking de unidades', $meta['permission'] === 'incidents.view' ? 'incidentes' : 'activos');
        }

        $assets = Asset::query()->where('team_id', $context->teamId)
            ->when($context->category, fn ($q, $c) => $q->whereHas('assetType', fn ($t) => $t->where('category', $c->value)))
            ->limit(2000)->get(['id', 'code', 'name', 'team_id', 'last_seen_at']);

        $values = match ($metric) {
            'idle_hours' => collect($this->idle->forAssets($context->teamId, $assets->pluck('id')->all(), $context->period->from, $context->period->to))->map->hours,
            'fuel_used_pct' => $this->fuel($assets->pluck('id'), $context->period),
            'distance_km' => $this->distance($assets->pluck('id'), $context->period),
            'incidents' => $this->countIncidents($context),
            'events' => $this->countEvents($context, $context->arguments['event_type'] ?? null),
            'panics' => $this->countEvents($context, 'panic_button'),
        };

        $values = $values->only($assets->pluck('id')->all())->filter(fn ($v) => $v > 0);

        if ($values->isEmpty()) {
            return new CopilotToolResult('rank_assets', 'Ranking de unidades',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "No hay datos de {$meta['label']} en {$context->period->label}."]],
                facts: ['metric' => $metric, 'items' => [], 'average' => null],
                highlights: ["No hay datos de {$meta['label']} en {$context->period->label}."]);
        }

        $avg = $values->avg();
        $std = sqrt($values->map(fn ($v) => ($v - $avg) ** 2)->avg());
        $limit = (int) ($context->arguments['limit'] ?? 5);
        $sorted = ($context->arguments['order'] ?? 'desc') === 'asc' ? $values->sort() : $values->sortDesc();

        $items = $sorted->take($limit)->map(function ($value, $id) use ($assets, $avg, $std, $context) {
            $asset = $assets->firstWhere('id', $id);

            return [
                'assetId' => (int) $id,
                'code' => $asset->code,
                'name' => (string) $asset->name,
                'value' => round((float) $value, 2),
                'outlier' => $std > 0 && $value > $avg + 1.5 * $std,
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $id),
            ];
        })->values()->all();

        $top = $items[0];

        return new CopilotToolResult(
            tool: 'rank_assets',
            label: 'Ranking de unidades',
            blocks: [['type' => 'ranking', 'metric' => $metric, 'label' => $meta['label'], 'unit' => $meta['unit'], 'items' => $items, 'average' => round($avg, 2)]],
            sources: array_map(fn ($i) => ['kind' => 'asset', 'id' => $i['assetId'], 'label' => $i['code'] ?? $i['name'], 'href' => $i['href']], $items),
            facts: ['metric' => $metric, 'unit' => $meta['unit'], 'period' => $context->period->label, 'items' => $items, 'average' => round($avg, 2), 'units_with_data' => $values->count()],
            highlights: ["{$top['code']} encabeza {$meta['label']} con {$top['value']} {$meta['unit']} (promedio de flota {$avg} {$meta['unit']})."],
        );
    }

    /** @return Collection<int, float> asset_id → % consumed */
    private function fuel(Collection $ids, CopilotPeriod $period): Collection
    {
        return AssetTelemetrySnapshot::query()->whereIn('asset_id', $ids)->where('telemetry_type', TelemetryType::Fuel)
            ->whereBetween('recorded_at', [$period->from, $period->to])->orderBy('recorded_at')
            ->get(['asset_id', 'data_json', 'recorded_at'])->groupBy('asset_id')
            ->map(fn ($series) => FuelConsumption::fromSeries($series)['consumed']);
    }

    /** @return Collection<int, float> asset_id → km */
    private function distance(Collection $ids, CopilotPeriod $period): Collection
    {
        return AssetTelemetrySnapshot::query()->whereIn('asset_id', $ids)->where('telemetry_type', TelemetryType::Odometer)
            ->whereBetween('recorded_at', [$period->from, $period->to])
            ->get(['asset_id', 'data_json'])->groupBy('asset_id')
            ->map(fn ($s) => max(0.0, (float) $s->max(fn ($r) => (float) $r->data_json['value']) - (float) $s->min(fn ($r) => (float) $r->data_json['value'])));
    }

    private function countIncidents(CopilotToolContext $context): Collection
    {
        return Incident::query()->where('team_id', $context->teamId)->whereNotNull('asset_id')
            ->whereBetween('created_at', [$context->period->from, $context->period->to])
            ->selectRaw('asset_id, count(*) as total')->groupBy('asset_id')->pluck('total', 'asset_id')->map(fn ($v) => (int) $v);
    }

    private function countEvents(CopilotToolContext $context, ?string $typeCode): Collection
    {
        return NormalizedEvent::query()->where('team_id', $context->teamId)->whereNotNull('asset_id')
            ->whereBetween('occurred_at', [$context->period->from, $context->period->to])
            ->when($typeCode, fn ($q) => $q->whereIn('event_type_id', EventType::query()->where('code', $typeCode)->select('id')))
            ->selectRaw('asset_id, count(*) as total')->groupBy('asset_id')->pluck('total', 'asset_id')->map(fn ($v) => (int) $v);
    }
}
```

Confirmar al implementar: columna de fecha de `Incident` (`created_at` vs `opened_at`) y que `Incident` tiene `asset_id` (si no, unir vía `normalized_event_id`); ajustar en consecuencia y dejar el test `test_ranks_incidents...` cubriéndolo.

SDK wrapper `RankAssetsSdkTool extends ArgumentedCopilotTool`:

```php
protected function extraSchema(JsonSchema $schema): array
{
    return [
        'metric' => $schema->string()->enum(array_keys(RankAssetsTool::METRICS))->description('fuel_used_pct = % de tanque consumido; distance_km; idle_hours = horas de ralentí (motor encendido detenido); incidents; events; panics.')->required(),
        'order' => $schema->string()->enum(['desc', 'asc'])->description('desc = los que más; asc = los que menos.'),
        'limit' => $schema->integer()->min(1)->max(10),
        'event_type' => $schema->string()->description('Código de tipo de evento cuando metric=events (p. ej. harsh_brake).'),
    ];
}

protected function extraRules(): array
{
    return [
        'metric' => ['required', Rule::in(array_keys(RankAssetsTool::METRICS))],
        'order' => ['nullable', Rule::in(['desc', 'asc'])],
        'limit' => ['nullable', 'integer', 'between:1,10'],
        'event_type' => ['nullable', 'string', 'max:60'],
    ];
}
```

Definición: `new self('rank_assets', 'Compara TODAS las unidades por una métrica en un periodo y marca las atípicas. Úsala para "cuál gastó más", "top", "peores", "comparar flota".', RankAssetsTool::class, 'assets.view', CopilotIntent::AssetRanking, false, RankAssetsSdkTool::class)`.

- [ ] **Step 5: `FindAssetsTool`** — `query` (req, 1..40), `limit` (≤10): tokens normalizados con `CopilotText::key`, match `str_contains` sobre `key(code)`/`key(name)` dentro de `Asset::where('team_id', ...)->limit(5000)`; devuelve `facts.items[{code,name,category,lastSeenAt}]`; sin bloques visuales (block `notice` sólo si 0). Descripción: "Busca unidades por número económico o nombre cuando no estás seguro del código exacto." Permiso `assets.view`, intent `General`.

- [ ] **Step 6: `SearchEventsTool`** — args `event_type?`, `severity?`, `asset_code?`, `limit≤25`. Query `NormalizedEvent::where('team_id')->whereBetween('occurred_at', period)->with(['eventType','eventSeverity','asset:id,code,name'])`; `facts.total`, `facts.by_type` (código → conteo), `facts.by_severity`, `facts.recent` (≤ limit, `{at, type, severity, asset}`); block `{type:'incidents'...}` no aplica: usar block `bars` existente (`{type:'bars', title:'Eventos por tipo', items:[{label, value}]}` — confirmar forma en `copilot-blocks.tsx` al implementar) + lista con block `events` nuevo sólo si el front lo soporta; si no, `notice` + `sources` a eventos (`CopilotPresenter::eventHref`). Permiso `incidents.view`, intent `EventSearch`.

- [ ] **Step 7: `AssetTimelineTool`** — requiere asset: eventos (`occurred_at`), incidentes (`IncidentRows`/`Incident` de la unidad) y `IdleTimeCalculator::forAsset` (tramos ≥ 10 min) → `items` ordenados por `at`, máx 40; block `{type:'timeline', items}`. Permiso `incidents.view`, intent `AssetTimeline`.

- [ ] **Step 8: Frontend mínimo de los blocks nuevos.** En `resources/js/types/copilot.ts` agregar `RankingBlock` y `TimelineBlock` a la unión de blocks; en `copilot-blocks.tsx` agregar `case 'ranking'` (tabla: código con link, valor + unidad, chip "atípica" si `outlier`, fila de promedio) y `case 'timeline'` (lista vertical con hora local, ícono por `kind`, minutos si `idle`). Seguir los componentes/estilos de los cases existentes (`drivers`, `incidents`).

- [ ] **Step 9:** Run `--filter='RankAssetsToolTest|FindAssetsToolTest|SearchEventsToolTest|AssetTimelineToolTest|CopilotAgentTenantLeakTest|CopilotToolPermissionsTest'` → PASS; `npm run types:check && npm run lint:check` → OK.

- [ ] **Step 10: Commit**

```bash
git add app/Domains/Copilot resources/js tests/Feature/Domains/Copilot
git commit -m "feat: tools de ranking, búsqueda de eventos y línea de tiempo"
```

---

### Task 5: CopilotAgent multipaso + guarda por paso + followups

**Files:**
- Modify (reescribir): `app/Infrastructure/AI/Agents/CopilotAgent.php`
- Create: `app/Infrastructure/AI/Middleware/CopilotStepGuard.php`, `app/Domains/Copilot/Tools/Sdk/SuggestFollowupsTool.php`
- Modify: `app/Domains/Copilot/Support/CopilotToolbox.php` (agrega `SuggestFollowupsTool` siempre), `config/ai.php` (+`'copilot' => ['max_turn_tokens' => env('COPILOT_MAX_TURN_TOKENS', 60000)]`), `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Copilot/CopilotStepGuardTest.php`

**Interfaces:**
- Produces: `new CopilotAgent(CopilotTurnScope $scope, array $history, array $tools, CopilotStepGuard $guard)`; `CopilotStepGuard::handle(PendingStep $step, Closure $next): mixed`; `SuggestFollowupsTool` name `suggest_followups`.

- [ ] **Step 1: Test que falla — `CopilotStepGuardTest`:**

```php
public function test_final_step_forces_answer_without_tools(): void
{
    $step = $this->pendingStep(number: 6, isFinalStep: true);
    $seen = null;

    (new CopilotStepGuard(maxTurnTokens: 60000))->handle($step, function (PendingStep $s) use (&$seen) {
        $seen = $s;

        return new StepResult(/* ver constructor en vendor/laravel/ai/src/Gateway/StepResult.php */);
    });

    $this->assertSame('none', $seen->options->toolChoice->mode);   // ajustar al getter real de TextGenerationOptions
    $this->assertSystemLogged('copilot.step.budget_reached', fn ($c) => $c['reason'] === 'max_steps' && $c['input']['step'] === 6);
}

public function test_token_budget_forces_answer_early(): void
{
    $step = $this->pendingStep(number: 3, isFinalStep: false, usage: new TextUsage(inputTokens: 70000, outputTokens: 0));
    // → toolChoice none + reason max_turn_tokens, calc tokens_so_far 70000
}

public function test_normal_step_passes_through_and_logs_debug(): void
{
    // number 2 → sin cambios; copilot.step.started con tools_available
}
```

Construir `PendingStep` con su constructor público (`vendor/laravel/ai/src/PendingStep.php:25`): `new PendingStep(number:, isFinalStep:, provider: 'openai', model: 'gpt-test', instructions: '', messages: [], tools: [], schema: null, options: null, usage:)`.

- [ ] **Step 2:** Run `--filter=CopilotStepGuardTest` → FAIL.

- [ ] **Step 3: `CopilotStepGuard`:**

```php
final class CopilotStepGuard
{
    public function __construct(private readonly int $maxTurnTokens) {}

    public function handle(PendingStep $step, Closure $next): mixed
    {
        $tokens = $step->usage->inputTokens + $step->usage->outputTokens;
        $reason = match (true) {
            $step->isFinalStep => 'max_steps',
            $tokens >= $this->maxTurnTokens => 'max_turn_tokens',
            default => null,
        };

        if ($reason !== null) {
            SystemLog::skipped('copilot.step.budget_reached', $reason, ['step' => $step->number], calc: ['tokens_so_far' => $tokens, 'max_turn_tokens' => $this->maxTurnTokens]);

            return $next($step->withToolChoice(ToolChoice::none));
        }

        SystemLog::ok('copilot.step.started', ['step' => $step->number, 'tools_available' => count($step->tools)], debug: true);

        return $next($step);
    }
}
```

- [ ] **Step 4: `SuggestFollowupsTool`** (implements `Laravel\Ai\Contracts\Tool` directo, no extiende `SdkCopilotTool`):

```php
final class SuggestFollowupsTool implements Tool
{
    public function __construct(private readonly CopilotTurnCollector $collector) {}

    public function name(): string { return 'suggest_followups'; }

    public function description(): Stringable|string
    {
        return 'Llama esto al final con 2 o 3 preguntas cortas que el gerente probablemente haría después, basadas en lo que encontraste.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['questions' => $schema->array()->items($schema->string()->max(80))->min(2)->max(3)->required()];
    }

    public function handle(Request $request): Stringable|string
    {
        $questions = collect((array) $request->get('questions', []))->filter(fn ($q) => is_string($q) && trim($q) !== '')
            ->map(fn ($q) => mb_substr(trim($q), 0, 80))->take(3)->values()->all();

        $this->collector->followups($questions);

        return 'ok';
    }
}
```

(Si `JsonSchema` no tiene `->max()`/`->min()` en arrays/strings, usar los métodos reales de `Illuminate\JsonSchema\Types\*`; verificar con `grep -n "function" vendor/laravel/framework/src/Illuminate/JsonSchema/Types/ArrayType.php`.)

`CopilotToolbox::for()` añade `new SuggestFollowupsTool($collector)` al final (sin permiso). Actualizar el conteo del test `test_super_admin_gets_every_tool` a 15.

- [ ] **Step 5: `CopilotAgent`:**

```php
#[MaxSteps(6)]
#[Timeout(45)]
#[CacheInstructions]
#[CacheToolDefinitions]
class CopilotAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<Tool>  $tools
     */
    public function __construct(
        private readonly CopilotTurnScope $scope,
        private readonly array $history,
        private readonly array $tools,
        private readonly CopilotStepGuard $guard,
    ) {}

    public function instructions(): Stringable|string
    {
        $now = $this->scope->now->setTimezone($this->scope->timezone);

        return <<<INSTRUCTIONS
Eres SAM Copilot, el monitorista senior de una central de monitoreo de flotas.
Te consulta el gerente o el dueño. Respondes en español de México: directo, preciso y accionable.

Ahora es {$now->translatedFormat('l j \d\e F \d\e Y, H:i')} (zona {$this->scope->timezone}, ISO {$now->toIso8601String()}).

CÓMO TRABAJAS
1. Todo dato sale de tus herramientas. Nunca inventes cifras, ubicaciones, nombres, horarios ni estados.
2. Convierte expresiones de tiempo ("anoche", "la semana pasada", "desde el lunes") a from/to ISO-8601 con la zona indicada.
3. Si no sabes el código exacto de una unidad, usa find_assets. Si una herramienta devuelve error, corrige los argumentos o explica qué faltó.
4. Para "cuál/qué unidad más/menos…", "top", "comparar flota" usa rank_assets. Para "qué pasó…" usa search_events y/o open_incidents.
5. Puedes encadenar herramientas: primero encuentra, luego profundiza en lo relevante.
6. Si una herramienta indica falta de permiso, dilo y sugiere pedir acceso al administrador.

CÓMO RESPONDES
- Primero la respuesta directa; luego riesgos, anomalías (marcadas "outlier") y el siguiente paso recomendado.
- 2 a 6 frases o viñetas. La interfaz ya muestra tarjetas (mapas, tablas, rankings): no repitas listas, destaca lo importante.
- Combustible es % de tanque, no litros. Ralentí es motor encendido sin moverse.
- **Negritas** sólo para cifras y unidades clave. Sin encabezados ni emojis.
- Al terminar, llama a suggest_followups con 2 o 3 preguntas de seguimiento.
INSTRUCTIONS;
    }

    /** @return iterable<Message> */
    public function messages(): iterable
    {
        return array_map(fn (array $turn) => new Message($turn['role'], $turn['content']), $this->history);
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return $this->tools;
    }

    public function middleware(): array
    {
        return [$this->guard];
    }
}
```

(Verificar que los atributos `CacheInstructions`/`CacheToolDefinitions` no fallan con OpenAI; si el driver los ignora, OK. Verificar el formato de middleware aceptado — instancia o class-string — en `vendor/laravel/ai/src/Promptable.php`/`Concerns`.)

- [ ] **Step 6:** Actualizar `docs/SAM/logging.md` con `copilot.step.started` y `copilot.step.budget_reached`.

- [ ] **Step 7:** Run `--filter='CopilotStepGuardTest|CopilotToolPermissionsTest'` → PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Infrastructure app/Domains/Copilot config/ai.php docs/SAM/logging.md tests/Feature/Domains/Copilot
git commit -m "feat: copilot como agente multipaso con guardas por paso"
```

---

### Task 6: Orquestación del turno (JSON), memoria y retiro del narrador SDK

**Files:**
- Create: `app/Domains/Copilot/Actions/{PrepareCopilotTurn,RunCopilotAgentTurn,RunDeterministicCopilotTurn,FinishCopilotTurn}.php`, `app/Domains/Copilot/Data/{CopilotTurn,CopilotTurnOutcome}.php`, `app/Domains/Copilot/Support/CopilotHistory.php`
- Modify: `app/Domains/Copilot/Actions/SendCopilotMessage.php`, `app/Domains/Copilot/CopilotServiceProvider.php`, `docs/SAM/logging.md`
- Delete: `app/Infrastructure/AI/Agents/SdkCopilotNarrator.php`
- Test: `tests/Feature/Domains/Copilot/{CopilotAgentTurnTest,CopilotMemoryTest}.php`, migrar `SendCopilotMessageTest::test_llm_narration_records_tokens_cost_and_usage`

**Interfaces:**
- Produces:
  - `CopilotTurn { Team $team; User $user; CopilotConversation $conversation; CopilotMessage $question; array $history; ?int $previousAssetId; CopilotTurnScope $scope; CopilotTurnCollector $collector; array $hints; string $channel; int $startedAt (hrtime) }`
  - `PrepareCopilotTurn::execute(Team, User, array $permissions, string $content, ?CopilotConversation, array $hints, string $channel): CopilotTurn`
  - `CopilotTurnOutcome { string $mode ('agent'|'deterministic'); string $text; ?string $model; TextUsage $usage; int $steps; CopilotIntent $intent; bool $partial; ?int $firstTokenMs }`
  - `RunCopilotAgentTurn::agentFor(CopilotTurn): CopilotAgent` · `RunCopilotAgentTurn::execute(CopilotTurn): CopilotTurnOutcome` (prompt no-stream) · `RunCopilotAgentTurn::available(): bool`
  - `RunDeterministicCopilotTurn::execute(CopilotTurn): CopilotTurnOutcome` (llena el collector con los resultados de `AnswerCopilotQuestion`)
  - `FinishCopilotTurn::execute(CopilotTurn, CopilotTurnOutcome): CopilotMessage`
  - `CopilotHistory::forConversation(CopilotConversation): array{history: list<array{role,content}>, previousAssetId: ?int}`

- [ ] **Step 1: Tests que fallan — `CopilotAgentTurnTest`:**

```php
protected function setUp(): void
{
    parent::setUp();
    config(['ai.providers.openai.key' => 'test-key', 'ai.pricing' => ['gpt-test' => ['input' => 1.0, 'output' => 4.0]]]);
}

public function test_multi_step_turn_persists_tools_blocks_followups_and_usage(): void
{
    [$user, $team] = $this->memberWithRole('supervisor');
    $this->truckWithTelemetry($team, 'T555');
    $this->truckWithTelemetry($team, 'T600');

    CopilotAgent::fake([
        new ToolCall('call_1', 'rank_assets', ['metric' => 'fuel_used_pct']),
        new ToolCall('call_2', 'asset_fuel', ['asset_code' => 'T555']),
        new ToolCall('call_3', 'suggest_followups', ['questions' => ['¿Y el ralentí de T555?', '¿Quién la maneja?']]),
        new TextResponse('**T555** consumió más combustible.', new TextUsage(inputTokens: 900, outputTokens: 120), new Meta('openai', 'gpt-test')),
    ]);

    $answer = $this->actingAs($user)->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Qué unidad gastó más combustible esta semana, Juan?'])
        ->assertCreated()->json('answer');

    $this->assertSame('**T555** consumió más combustible.', $answer['content']);
    $this->assertSame(['rank_assets', 'asset_fuel', 'suggest_followups'], array_column($answer['tools'], 'tool'));  // suggest no debe aparecer si no produce resultado de datos → ajustar: collector no registra suggest_followups
    $this->assertSame('asset_ranking', $answer['intent']);
    $this->assertSame(['¿Y el ralentí de T555?', '¿Quién la maneja?'], $answer['followups']);
    $this->assertNotEmpty($answer['blocks']);
    $this->assertSystemLogged('copilot.turn.completed', fn ($c) => $c['input']['mode'] === 'agent' && $c['calc']['tool_count'] === 2);
    $this->assertNoSensitiveDataLogged();   // "Juan" y la pregunta no aparecen
}

public function test_open_question_uses_fleet_overview(): void
{
    // fake: ToolCall fleet_overview → texto; pregunta "¿cómo vamos?" → answer.tools[0].tool = fleet_overview, intent fleet_overview
}

public function test_provider_error_falls_back_to_deterministic_answer(): void
{
    CopilotAgent::fake(fn () => throw new ProviderOverloadedException('down'));
    // "¿Dónde está T555?" → 201, answer.content no vacío (plantilla), answer.usage.model null
    // assertSystemLogged('copilot.turn.fallback', reason agent_error_before_output)
}

public function test_without_provider_key_uses_deterministic_mode(): void
{
    config(['ai.providers.openai.key' => '']);
    // → copilot.turn.fallback reason no_provider_key (debug) + copilot.turn.completed mode deterministic
}
```

Decisión que el test fija: `suggest_followups` **no** se registra en el collector como tool de datos (sólo sus preguntas). Corregir la aserción a `['rank_assets', 'asset_fuel']`.

**`CopilotMemoryTest`:**

```php
public function test_second_turn_sees_the_facts_of_the_first(): void
{
    // turno 1 (fake): ToolCall asset_fuel T555 → texto "T555 consumió 40 %."
    // turno 2 en la misma conversación: CopilotAgent::fake con closure que captura el prompt/messages;
    // assert que algún mensaje de historial contiene "[datos consultados:" y "asset_fuel"
    // y que context_json.facts_digest de la respuesta 1 no está vacío
}
```

(Para capturar mensajes: `CopilotAgent::assertPrompted(fn ($prompt) => ...)` — verificar API real de asserts en `vendor/laravel/ai/src/Gateway/FakeTextGateway.php` / `Promptable`; si `AgentPrompt` expone `->messages`, usarlo.)

- [ ] **Step 2:** Run `--filter='CopilotAgentTurnTest|CopilotMemoryTest'` → FAIL.

- [ ] **Step 3: `CopilotHistory`** (extraer `history()`/`previousAssetId()` de `SendCopilotMessage`):

```php
final class CopilotHistory
{
    private const TURNS = 6;

    private const MAX_CHARS = 4000;

    /** @return array{history: list<array{role: string, content: string}>, previousAssetId: int|null} */
    public function forConversation(CopilotConversation $conversation): array
    {
        $messages = CopilotMessage::query()
            ->where('team_id', $conversation->team_id)
            ->where('copilot_conversation_id', $conversation->id)
            ->orderByDesc('id')->limit(self::TURNS)
            ->get(['role', 'content', 'context_json'])->reverse()->values();

        $history = $messages->map(function (CopilotMessage $m) {
            $digest = $m->context_json['facts_digest'] ?? null;

            return [
                'role' => $m->role->value,
                'content' => (string) $m->content.($m->role === CopilotMessageRole::Assistant && $digest ? "\n[datos consultados: {$digest}]" : ''),
            ];
        })->all();

        while (mb_strlen(json_encode($history)) > self::MAX_CHARS && count($history) > 2) {
            array_shift($history);
        }

        $assetId = $messages->where('role', CopilotMessageRole::Assistant)->last()?->context_json['resolved']['asset_id'] ?? null;

        return ['history' => array_values($history), 'previousAssetId' => $assetId !== null ? (int) $assetId : null];
    }
}
```

- [ ] **Step 4: `PrepareCopilotTurn`** — mueve de `SendCopilotMessage` el `abort_if` de dueño, creación de conversación y del mensaje de usuario; arma `CopilotTurnScope::fromTeam`, `new CopilotTurnCollector`, historial; loguea `copilot.turn.started` (debug) con `question_length`, `history_turns`, `channel`, `conversation_id`, `mode` (según `RunCopilotAgentTurn::available()`).

- [ ] **Step 5: `RunCopilotAgentTurn`:**

```php
final class RunCopilotAgentTurn
{
    public function __construct(private readonly CopilotToolbox $toolbox) {}

    public static function available(): bool
    {
        $key = config('ai.providers.'.config('ai.default').'.key');

        return is_string($key) && trim($key) !== '';
    }

    public function agentFor(CopilotTurn $turn): CopilotAgent
    {
        return new CopilotAgent(
            $turn->scope,
            $turn->history,
            $this->toolbox->for($turn->scope, $turn->collector),
            new CopilotStepGuard((int) config('ai.copilot.max_turn_tokens', 60000)),
        );
    }

    public function execute(CopilotTurn $turn): CopilotTurnOutcome
    {
        $response = $this->agentFor($turn)->prompt($this->prompt($turn));

        return new CopilotTurnOutcome(
            mode: 'agent',
            text: trim($response->text),
            model: $response->meta?->model,
            usage: $response->usage,
            steps: count($response->steps ?? []),
            intent: $turn->collector->primaryIntent(),
            partial: false,
            firstTokenMs: null,
        );
    }

    /** The UI pills (pinned unit / intent) travel as a hint line the model can use. */
    public function prompt(CopilotTurn $turn): string
    {
        $hints = [];

        if ($assetId = $turn->hints['asset_id'] ?? $turn->previousAssetId) {
            $code = Asset::query()->where('team_id', $turn->scope->teamId)->whereKey($assetId)->value('code');
            $hints[] = $code ? "Unidad en contexto: {$code}." : null;
        }

        if (! empty($turn->hints['intent']) && ($intent = CopilotIntent::tryFrom($turn->hints['intent']))) {
            $hints[] = "El usuario eligió: {$intent->label()}.";
        }

        return trim($turn->question->content."\n\n".implode(' ', array_filter($hints)));
    }
}
```

- [ ] **Step 6: `RunDeterministicCopilotTurn`:**

```php
final class RunDeterministicCopilotTurn
{
    public function __construct(
        private readonly AnswerCopilotQuestion $answer,
        private readonly TemplateCopilotNarrator $template,
    ) {}

    public function execute(CopilotTurn $turn): CopilotTurnOutcome
    {
        $answer = $this->answer->execute(
            teamId: $turn->scope->teamId,
            teamSlug: $turn->scope->teamSlug,
            permissions: $turn->scope->permissions,
            isSuperAdmin: $turn->scope->isSuperAdmin,
            question: (string) $turn->question->content,
            hints: $turn->hints,
            previousAssetId: $turn->previousAssetId,
        );

        foreach ($answer->results as $i => $result) {
            $turn->collector->record("det_{$i}", $result->tool, $result, 'ok', 0, $answer->resolvedContext['asset_id'] ?? null);
        }

        return new CopilotTurnOutcome('deterministic', $this->template->narrate((string) $turn->question->content, $answer)->text, null, new TextUsage, 0, $answer->intent, false, null);
    }
}
```

(`CopilotTurnCollector::primaryIntent` no aplica aquí: se usa `$answer->intent`. `asset_picker` también pasa por `record()`, así su bloque se emite igual.)

- [ ] **Step 7: `FinishCopilotTurn`** — mueve la transacción de persistencia, `RecordCopilotUsage`, auditoría y agrega el log:

```php
public function execute(CopilotTurn $turn, CopilotTurnOutcome $outcome): CopilotMessage
{
    $latencyMs = SystemLog::elapsedMs($turn->startedAt);
    $cost = $this->pricing->estimateUsageCost($outcome->model, $outcome->usage);

    $reply = DB::transaction(function () use ($turn, $outcome, $latencyMs, $cost) {
        $reply = CopilotMessage::query()->create([
            'team_id' => $turn->team->id,
            'copilot_conversation_id' => $turn->conversation->id,
            'user_id' => null,
            'role' => CopilotMessageRole::Assistant,
            'content' => $outcome->text !== '' ? $outcome->text : 'No pude completar la respuesta.',
            'intent' => $outcome->intent,
            'channel' => $turn->channel,
            'context_json' => array_filter([
                'resolved' => ['asset_id' => $turn->collector->lastAssetId()],
                'facts_digest' => $turn->collector->factsDigest() ?: null,
                'followups' => $turn->collector->followupList() ?: null,
                'mode' => $outcome->mode,
                'partial' => $outcome->partial ?: null,
            ]),
            'blocks_json' => $turn->collector->blocks(),
            'tools_json' => $turn->collector->tools(),
            'sources_json' => $turn->collector->sources(),
            'model' => $outcome->model,
            'input_tokens' => $outcome->usage->inputTokens,
            'output_tokens' => $outcome->usage->outputTokens,
            'cost_estimate' => $cost,
            'latency_ms' => $latencyMs,
        ]);

        $turn->conversation->forceFill(['messages_count' => $turn->conversation->messages_count + 2, 'last_message_at' => now()])->save();

        return $reply;
    });

    $this->recordUsage->execute($reply);
    $this->audit->execute(/* igual que hoy en SendCopilotMessage, metadata + 'mode' => $outcome->mode, 'steps' => $outcome->steps */);

    SystemLog::ok('copilot.turn.completed',
        ['team_id' => $turn->team->id, 'message_id' => $reply->id, 'mode' => $outcome->mode, 'partial' => $outcome->partial],
        calc: ['steps' => $outcome->steps, 'tools' => array_column($turn->collector->tools(), 'tool'), 'tool_count' => count($turn->collector->tools()), 'blocks_count' => count($turn->collector->blocks()), 'followups_count' => count($turn->collector->followupList())],
        result: ['model' => $outcome->model, 'input_tokens' => $outcome->usage->inputTokens, 'cached_input_tokens' => $outcome->usage->cacheReadInputTokens, 'output_tokens' => $outcome->usage->outputTokens, 'cost_estimate' => $cost, 'latency_ms' => $latencyMs, 'first_token_ms' => $outcome->firstTokenMs],
        durationMs: $latencyMs);

    return $reply;
}
```

Nota: `tools_json` cambia de forma (`+status`, `+durationMs`); revisar `CopilotMessagePresenter::message()` y `resources/js/types/copilot.ts` (`tools`) para aceptar los campos nuevos opcionales; `CopilotUsageQuery` sigue leyendo `tools_json` — correr sus tests.

`CopilotMessagePresenter::message()` agrega `'followups' => $m->context_json['followups'] ?? []` y `'partial' => (bool) ($m->context_json['partial'] ?? false)`; TS: `followups: string[]; partial?: boolean`.

- [ ] **Step 8: `SendCopilotMessage`** queda como orquestador:

```php
public function execute(Team $team, User $user, array $permissions, string $content, ?CopilotConversation $conversation = null, array $hints = [], string $channel = 'page'): array
{
    return TenantContext::for($team->id, function () use (...) {
        $turn = $this->prepare->execute($team, $user, $permissions, $content, $conversation, $hints, $channel);

        $outcome = $this->run($turn);
        $reply = $this->finish->execute($turn, $outcome);

        return ['conversation' => $turn->conversation->refresh(), 'question' => $turn->question, 'answer' => $reply];
    });
}

private function run(CopilotTurn $turn): CopilotTurnOutcome
{
    if (! RunCopilotAgentTurn::available()) {
        SystemLog::skipped('copilot.turn.fallback', 'no_provider_key', ['team_id' => $turn->team->id], debug: true);

        return $this->deterministic->execute($turn);
    }

    try {
        return $this->agent->execute($turn);
    } catch (Throwable $e) {
        SystemLog::degraded('copilot.turn.fallback', 'agent_error_before_output', ['team_id' => $turn->team->id], error: $e);
        $turn->collector = new CopilotTurnCollector;   // CopilotTurn::$collector no readonly

        return $this->deterministic->execute($turn);
    }
}
```

- [ ] **Step 9: Retirar narrador SDK.** Borrar `SdkCopilotNarrator.php`; en `CopilotServiceProvider::register` bindear `CopilotNarrator` siempre a `TemplateCopilotNarrator` (o quitar el binding si ya no se inyecta el contrato: `grep -rn CopilotNarrator app tests`). Borrar la fila `copilot.narration.fallback` de `docs/SAM/logging.md` y agregar `copilot.turn.started/completed/fallback/failed`.

Migrar `SendCopilotMessageTest::test_llm_narration_records_tokens_cost_and_usage` → `test_agent_turn_records_tokens_cost_and_usage`: mismo cuerpo pero sin `instance(CopilotNarrator::class, ...)`, con `config(['ai.providers.openai.key' => 'test-key'])` y `CopilotAgent::fake([new ToolCall('c1','asset_location',['asset_code'=>'T555']), new TextResponse(...1200/300...)])`; mismas aserciones de tokens, costo 0.0024 y usage events.

- [ ] **Step 10:** Run `--filter='Copilot'` (todo el dominio) → PASS.

- [ ] **Step 11: Commit**

```bash
git add -A app/Domains/Copilot app/Infrastructure/AI docs/SAM/logging.md resources/js/types/copilot.ts tests/Feature/Domains/Copilot
git commit -m "feat: turnos de copilot con agente, memoria de datos y respaldo determinista"
```

---

### Task 7: Streaming SSE con protocolo Vercel

**Files:**
- Create: `app/Domains/Copilot/Streaming/{CopilotStreamProtocol,CopilotStreamState,DeterministicCopilotParts}.php`, `app/Domains/Copilot/Actions/StreamCopilotTurn.php`, `app/Http/Controllers/Copilot/CopilotStreamController.php`
- Modify: `routes/web.php` (junto a `copilot.messages.store`), `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Copilot/{CopilotStreamEndpointTest,CopilotFallbackTest}.php`

**Interfaces:**
- Consumes: `PrepareCopilotTurn`, `RunCopilotAgentTurn::agentFor/prompt/available`, `RunDeterministicCopilotTurn`, `FinishCopilotTurn`, `CopilotMessagePresenter`, `CopilotQuotaQuery`.
- Produces: ruta `POST /{team}/copilot/stream` name `copilot.stream`; partes SSE `data-copilot-blocks {toolCallId, tool, label, blocks}`, `data-copilot-followups {questions}`, `data-copilot-message {answer, conversation, question, quota}`, más las estándar (`start`, `start-step`, `text-start`, `text-delta {id, delta}`, `text-end`, `tool-input-available {toolCallId, toolName, input}`, `tool-output-available {toolCallId, output:{ok:true}}`, `finish-step`, `finish`, `error {errorText}`), terminador `data: [DONE]`.

- [ ] **Step 1: Tests que fallan — `CopilotStreamEndpointTest`:**

```php
private function parts(TestResponse $response): array
{
    return collect(explode("\n\n", $response->streamedContent()))
        ->map(fn ($frame) => trim($frame))
        ->filter(fn ($frame) => str_starts_with($frame, 'data: ') && $frame !== 'data: [DONE]')
        ->map(fn ($frame) => json_decode(substr($frame, 6), true))
        ->values()->all();
}

public function test_streams_blocks_before_text_and_message_before_finish(): void
{
    config(['ai.providers.openai.key' => 'test-key']);
    [$user, $team] = $this->memberWithRole('supervisor');
    $this->truckWithTelemetry($team);
    CopilotAgent::fake([new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']), 'T555 va en ruta.']);

    $response = $this->actingAs($user)->post("/{$team->slug}/copilot/stream", ['content' => '¿Dónde está T555?'], ['Accept' => 'text/event-stream']);

    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');   // ajustar a lo que emita Symfony
    $types = array_column($this->parts($response), 'type');

    $this->assertLessThan(array_search('text-delta', $types), array_search('data-copilot-blocks', $types));
    $this->assertLessThan(array_search('finish', $types), array_search('data-copilot-message', $types));
    $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());
    $this->assertDatabaseCount('copilot_messages', 2);
}

public function test_tool_output_never_exposes_raw_facts(): void
{
    // mismo setup; la parte tool-output-available tiene output === ['ok' => true]
}

public function test_foreign_conversation_is_not_found(): void { /* conversation_id de otro user → 404 JSON antes de abrir stream */ }

public function test_viewer_without_copilot_access_is_forbidden(): void { /* igual que CopilotAccessTest para messages.store */ }

public function test_stream_is_rate_limited(): void { /* 21 requests → 429 */ }

public function test_deterministic_mode_streams_the_same_protocol(): void
{
    config(['ai.providers.openai.key' => '']);
    // tipos incluyen 'data-copilot-blocks', 'text-delta', 'data-copilot-message', 'finish'
}
```

**`CopilotFallbackTest`:**

```php
public function test_error_before_first_token_falls_back_inside_the_stream(): void
{
    config(['ai.providers.openai.key' => 'test-key']);
    CopilotAgent::fake(fn () => throw new ProviderOverloadedException('down'));
    // parts: sin 'error'; con text-delta (plantilla) y data-copilot-message; copilot.turn.fallback agent_error_before_output
}

public function test_error_mid_stream_persists_partial_answer(): void
{
    // fake: primer paso texto parcial + ToolCall a una tool que lanza desde el gateway fake?
    // Forma práctica: CopilotAgent::fake([ 'Parcial...', fn () => throw new ProviderOverloadedException('x') ]) no aplica (un solo paso de texto termina el turno).
    // Usar: [new ToolCall('c1','fleet_overview',[]), fn () => throw new ProviderOverloadedException('x')] y bindear
    // CopilotStreamState para marcar textStarted=true tras la tool (simula que hubo texto) — o preferible:
    // fake StepResponse con text 'Parcial' + toolCalls (FakeTextGateway::toStepResponse acepta TextResponse con toolCalls? revisar líneas 160-176).
    // Assert: 'error' part con errorText 'No pude completar la respuesta.'; mensaje assistant con context_json.partial = true; copilot.turn.failed agent_error_mid_stream.
}

public function test_client_disconnect_persists_partial_answer(): void
{
    // bind CopilotStreamState con aborted = fn () => true después del primer part
    // Assert: mensaje partial guardado; copilot.turn.failed reason client_disconnected (skipped)
}
```

- [ ] **Step 2:** Run `--filter='CopilotStreamEndpointTest|CopilotFallbackTest'` → FAIL.

- [ ] **Step 3: `CopilotStreamState`:**

```php
final class CopilotStreamState
{
    public bool $textStarted = false;

    public ?int $firstTokenMs = null;

    public ?array $messagePayload = null;

    /** @var list<array<string, mixed>> */
    public array $pending = [];

    public function __construct(public ?Closure $aborted = null)
    {
        $this->aborted ??= fn (): bool => connection_aborted() === 1;
    }

    public function aborted(): bool
    {
        return ($this->aborted)();
    }
}
```

- [ ] **Step 4: `CopilotStreamProtocol`:**

```php
final class CopilotStreamProtocol extends VercelDataProtocol
{
    /**
     * @param  Closure(Throwable): iterable<array<string, mixed>>  $fallback
     * @param  Closure(): void  $onDisconnect
     */
    public function __construct(
        private readonly CopilotStreamState $state,
        private readonly CopilotTurnCollector $collector,
        private readonly Closure $fallback,
        private readonly Closure $onDisconnect,
        private readonly int $startedAt,
    ) {
        parent::__construct();

        $collector->onBlocks(function (array $payload): void {
            $this->state->pending[] = ['type' => 'data-copilot-blocks', 'data' => $payload];
        });
    }

    protected function parts(StreamableAgentResponse $response): Generator
    {
        $closed = false;

        try {
            foreach (parent::parts($response) as $part) {
                if ($this->state->aborted()) {
                    ($this->onDisconnect)();

                    return;
                }

                if ($part['type'] === 'finish') {
                    yield from $this->closing();
                    $closed = true;
                }

                if ($part['type'] === 'text-delta' && ! $this->state->textStarted) {
                    $this->state->textStarted = true;
                    $this->state->firstTokenMs = SystemLog::elapsedMs($this->startedAt);
                }

                yield $part;
                yield from $this->drain();
            }

            if (! $closed) {
                yield from $this->closing();
            }
        } catch (Throwable $e) {
            if ($this->state->textStarted) {
                throw $e;   // base masks it; StreamCopilotTurn::catch persists the partial
            }

            yield from $this->drain();
            yield from ($this->fallback)($e);
        }
    }

    protected function toolResultPart(ToolResult $event): array
    {
        $part = parent::toolResultPart($event);

        return $part['type'] === 'tool-output-available' ? [...$part, 'output' => ['ok' => true]] : $part;
    }

    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart(['type' => 'error', 'errorText' => 'No pude completar la respuesta.']);
    }

    /** @return Generator<array<string, mixed>> */
    private function drain(): Generator
    {
        while ($this->state->pending !== []) {
            yield array_shift($this->state->pending);
        }
    }

    /** @return Generator<array<string, mixed>> */
    private function closing(): Generator
    {
        yield from $this->drain();

        if ($this->collector->followupList() !== []) {
            yield ['type' => 'data-copilot-followups', 'data' => ['questions' => $this->collector->followupList()]];
        }

        if ($this->state->messagePayload !== null) {
            yield ['type' => 'data-copilot-message', 'data' => $this->state->messagePayload];
        }
    }
}
```

(Verificar que `toolResultPart` es `protected` y no `private` en `VercelDataProtocol`; lo es en 1.0.1, línea ~203.)

- [ ] **Step 5: `DeterministicCopilotParts`** — genera las partes a partir de un turno determinista ya corrido:

```php
final class DeterministicCopilotParts
{
    /** @return Generator<array<string, mixed>> */
    public static function parts(CopilotTurnCollector $collector, CopilotTurnOutcome $outcome, array $messagePayload, bool $withStart = true): Generator
    {
        $id = 'det_'.Str::ulid();

        if ($withStart) {
            yield ['type' => 'start', 'messageId' => $id];
            yield ['type' => 'start-step'];
        }

        foreach ($collector->tools() as $i => $tool) {
            // bloques por tool en el orden registrado
        }
        // más simple: una sola parte con todos los bloques
        yield ['type' => 'data-copilot-blocks', 'data' => ['toolCallId' => $id, 'tool' => 'deterministic', 'label' => $outcome->intent->label(), 'blocks' => $collector->blocks()]];
        yield ['type' => 'text-start', 'id' => $id];
        yield ['type' => 'text-delta', 'id' => $id, 'delta' => $outcome->text];
        yield ['type' => 'text-end', 'id' => $id];
        yield ['type' => 'data-copilot-message', 'data' => $messagePayload];
        yield ['type' => 'finish-step'];
        yield ['type' => 'finish', 'finishReason' => 'stop'];
    }

    public static function response(Generator $parts): StreamedResponse
    {
        return response()->stream(function () use ($parts) {
            foreach ($parts as $part) {
                echo 'data: '.json_encode($part, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                flush();
            }

            echo "data: [DONE]\n\n";
        }, 200, ['Cache-Control' => 'no-cache, no-transform', 'Content-Type' => 'text/event-stream', 'x-vercel-ai-ui-message-stream' => 'v1', 'X-Accel-Buffering' => 'no']);
    }
}
```

Borrar el `foreach` vacío (dejado arriba sólo como nota): una sola parte `data-copilot-blocks` con todos los bloques. Confirmar la forma de `finish` que emite `VercelDataProtocol::finishPart()` y replicarla.

- [ ] **Step 6: `StreamCopilotTurn`:**

```php
final class StreamCopilotTurn
{
    public function __construct(
        private readonly PrepareCopilotTurn $prepare,
        private readonly RunCopilotAgentTurn $agent,
        private readonly RunDeterministicCopilotTurn $deterministic,
        private readonly FinishCopilotTurn $finish,
        private readonly CopilotQuotaQuery $quota,
        private readonly CopilotStreamState $state,
    ) {}

    public function execute(Team $team, User $user, array $permissions, string $content, ?CopilotConversation $conversation, array $hints, string $channel): Response
    {
        // TenantContext: el stream corre DESPUÉS de retornar el controller, así que
        // cada closure que toca DB se envuelve en TenantContext::for($team->id, ...).
        $turn = TenantContext::for($team->id, fn () => $this->prepare->execute($team, $user, $permissions, $content, $conversation, $hints, $channel));
        ignore_user_abort(true);

        if (! RunCopilotAgentTurn::available()) {
            SystemLog::skipped('copilot.turn.fallback', 'no_provider_key', ['team_id' => $team->id], debug: true);

            return DeterministicCopilotParts::response($this->deterministicParts($turn, withStart: true));
        }

        $stream = $this->agent->agentFor($turn)->stream($this->agent->prompt($turn));

        $stream->then(function (StreamedAgentResponse $response) use ($turn): void {
            TenantContext::for($turn->team->id, function () use ($turn, $response): void {
                $reply = $this->finish->execute($turn, new CopilotTurnOutcome('agent', trim($response->text), $response->meta?->model, $response->usage, count($response->steps ?? []), $turn->collector->primaryIntent(), false, $this->state->firstTokenMs));
                $this->state->messagePayload = $this->payload($turn, $reply);
            });
        });

        $stream->catch(function (Throwable $e) use ($turn): void {
            if (! $this->state->textStarted) {
                return;   // the protocol falls back in-stream
            }

            TenantContext::for($turn->team->id, function () use ($turn, $e): void {
                $reply = $this->persistPartial($turn, $this->textSoFar ?? '');
                SystemLog::failed('copilot.turn.failed', 'agent_error_mid_stream', ['team_id' => $turn->team->id, 'message_id' => $reply->id], error: $e);
            });
        });

        return (new CopilotStreamProtocol(
            state: $this->state,
            collector: $turn->collector,
            fallback: function (Throwable $e) use ($turn): Generator {
                SystemLog::degraded('copilot.turn.fallback', 'agent_error_before_output', ['team_id' => $turn->team->id], error: $e);
                $turn->collector = new CopilotTurnCollector;

                yield from $this->deterministicParts($turn, withStart: false);
            },
            onDisconnect: function () use ($turn): void {
                TenantContext::for($turn->team->id, function () use ($turn): void {
                    $reply = $this->persistPartial($turn, '');
                    SystemLog::skipped('copilot.turn.failed', 'client_disconnected', ['team_id' => $turn->team->id, 'message_id' => $reply->id]);
                });
            },
            startedAt: $turn->startedAt,
        ))->response($stream);
    }

    private function deterministicParts(CopilotTurn $turn, bool $withStart): Generator
    {
        $outcome = TenantContext::for($turn->team->id, fn () => $this->deterministic->execute($turn));
        $reply = TenantContext::for($turn->team->id, fn () => $this->finish->execute($turn, $outcome));

        return DeterministicCopilotParts::parts($turn->collector, $outcome, $this->payload($turn, $reply), $withStart);
    }

    private function persistPartial(CopilotTurn $turn, string $text): CopilotMessage
    {
        return $this->finish->execute($turn, new CopilotTurnOutcome('agent', $text, null, new TextUsage, 0, $turn->collector->primaryIntent(), true, $this->state->firstTokenMs));
    }

    /** @return array<string, mixed> */
    private function payload(CopilotTurn $turn, CopilotMessage $reply): array
    {
        return [
            'answer' => CopilotMessagePresenter::message($reply),
            'question' => CopilotMessagePresenter::message($turn->question),
            'conversation' => CopilotMessagePresenter::conversation($turn->conversation->refresh()),
            'quota' => $this->quota->forTeam($turn->team->id),
        ];
    }
}
```

Texto parcial: `CopilotStreamState` gana `public string $text = ''` que el protocolo acumula en cada `text-delta` (`$this->state->text .= $part['delta']`); `persistPartial($turn, $this->state->text)`. Eliminar la referencia a `$this->textSoFar`.

Doble cobro: si `onDisconnect` ya persistió, `then()` no debe persistir otra vez → `CopilotStreamState::$persisted` bool; `then`/`catch`/`onDisconnect` salen temprano si `persisted`.

- [ ] **Step 7: Controller + ruta.** `CopilotStreamController::__invoke` = copia de `CopilotMessageController::store` (misma `StoreCopilotMessageRequest`, `authorize('create')`, carga de conversación con `authorize('update')`), retorna `$stream->execute(...)`. Ruta en `routes/web.php` debajo de `copilot.messages.store`:

```php
Route::post('copilot/stream', CopilotStreamController::class)->middleware('throttle:copilot')->name('copilot.stream');
```

Luego `php artisan wayfinder:generate --with-form`.

- [ ] **Step 8:** `docs/SAM/logging.md`: `copilot.turn.failed` (reasons `agent_error_mid_stream`, `client_disconnected`). Run `--filter='CopilotStreamEndpointTest|CopilotFallbackTest|Copilot'` → PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Domains/Copilot app/Http/Controllers/Copilot routes/web.php docs/SAM/logging.md tests/Feature/Domains/Copilot
git commit -m "feat: streaming de copilot con protocolo vercel y respaldo determinista"
```

---

### Task 8: Frontend en streaming (estado de tools, tarjetas en vivo, followups)

**Files:**
- Create: `resources/js/components/sam/copilot/copilot-stream.ts`
- Modify: `resources/js/lib/sam-fetch.ts` (+`postStream`), `resources/js/components/sam/copilot/use-copilot-chat.ts`, `copilot-message.tsx`, `resources/js/types/copilot.ts`

**Interfaces:**
- Produces: `readCopilotStream(body: ReadableStream<Uint8Array>): AsyncGenerator<CopilotStreamPart>`; `CopilotMessage` gana `streaming?: boolean; activeTools?: {toolCallId: string; label: string}[]; followups: string[]; partial?: boolean`.

- [ ] **Step 1: `postStream`** en `sam-fetch.ts`:

```ts
/**
 * POST a JSON body and keep the response open as a server-sent event
 * stream (Copilot). Same CSRF handling as postJson.
 */
export function postStream(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body ?? {}),
    });
}
```

- [ ] **Step 2: `copilot-stream.ts`:**

```ts
import type { CopilotBlock, CopilotConversation, CopilotMessage, CopilotQuota } from '@/types/copilot';

export type CopilotStreamPart =
    | { type: 'text-delta'; id: string; delta: string }
    | { type: 'tool-input-available'; toolCallId: string; toolName: string; input: Record<string, unknown> }
    | { type: 'tool-output-available' | 'tool-output-error'; toolCallId: string }
    | { type: 'data-copilot-blocks'; data: { toolCallId: string; tool: string; label: string; blocks: CopilotBlock[] } }
    | { type: 'data-copilot-followups'; data: { questions: string[] } }
    | { type: 'data-copilot-message'; data: { answer: CopilotMessage; question: CopilotMessage; conversation: CopilotConversation; quota: CopilotQuota } }
    | { type: 'error'; errorText: string }
    | { type: string; [key: string]: unknown };

/**
 * Reads the Vercel UI message stream the Copilot endpoint emits: one JSON
 * part per `data:` line, frames separated by a blank line, `[DONE]` last.
 */
export async function* readCopilotStream(
    body: ReadableStream<Uint8Array>,
): AsyncGenerator<CopilotStreamPart> {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });
        let boundary = buffer.indexOf('\n\n');

        while (boundary !== -1) {
            const frame = buffer.slice(0, boundary).trim();
            buffer = buffer.slice(boundary + 2);
            boundary = buffer.indexOf('\n\n');

            if (!frame.startsWith('data: ')) {
                continue;
            }

            const payload = frame.slice(6);

            if (payload === '[DONE]') {
                return;
            }

            try {
                yield JSON.parse(payload) as CopilotStreamPart;
            } catch {
                // A malformed frame is skipped; the final message part carries the persisted answer.
            }
        }
    }
}

/** Spanish status line for a running tool ("Consultando combustible de T555…"). */
export function toolStatusLabel(toolName: string, input: Record<string, unknown>): string {
    const unit = typeof input.asset_code === 'string' ? ` de ${input.asset_code}` : '';
    const labels: Record<string, string> = {
        asset_summary: `Revisando la ficha${unit}`,
        asset_location: `Ubicando${unit || ' la unidad'}`,
        asset_engine: `Leyendo motor${unit}`,
        asset_fuel: `Consultando combustible${unit}`,
        asset_media: `Buscando cámaras${unit}`,
        asset_activity: `Revisando actividad${unit}`,
        asset_timeline: `Armando la línea de tiempo${unit}`,
        panic_kpis: 'Revisando botones de pánico',
        open_incidents: 'Revisando incidentes abiertos',
        driver_ranking: 'Revisando conductores',
        fleet_overview: 'Revisando la flota',
        find_assets: 'Buscando la unidad',
        rank_assets: 'Comparando unidades',
        search_events: 'Buscando eventos',
    };

    return `${labels[toolName] ?? 'Consultando datos'}…`;
}
```

- [ ] **Step 3: `use-copilot-chat.ts` → `send()` en streaming.** Reemplazar el bloque `postJson(...messages...)` + `response.json()` por:

```ts
const response = await postStream(`${base}/stream`, { content: text, conversation_id: conversationId, asset_id: hints.assetId ?? null, intent: hints.intent ?? null, channel }, controller.signal);

if (!response.ok || !response.body) {
    /* mismo manejo de 429/403/otros que hoy */
}

const draftId = -Date.now() - 1;
const draft: CopilotMessage = { ...optimistic, id: draftId, role: 'assistant', content: '', pending: false, streaming: true, activeTools: [], followups: [] };
setMessages((current) => [...current, draft]);

const patch = (fn: (m: CopilotMessage) => CopilotMessage) =>
    setMessages((current) => current.map((m) => (m.id === draftId ? fn(m) : m)));

for await (const part of readCopilotStream(response.body)) {
    switch (part.type) {
        case 'tool-input-available':
            patch((m) => ({ ...m, activeTools: [...(m.activeTools ?? []), { toolCallId: part.toolCallId, label: toolStatusLabel(part.toolName, part.input) }] }));
            break;
        case 'tool-output-available':
        case 'tool-output-error':
            patch((m) => ({ ...m, activeTools: (m.activeTools ?? []).filter((t) => t.toolCallId !== part.toolCallId) }));
            break;
        case 'data-copilot-blocks':
            patch((m) => ({ ...m, blocks: [...m.blocks, ...part.data.blocks] }));
            break;
        case 'text-delta':
            patch((m) => ({ ...m, content: m.content + part.delta }));
            break;
        case 'data-copilot-followups':
            patch((m) => ({ ...m, followups: part.data.questions }));
            break;
        case 'data-copilot-message':
            setConversationId(part.data.conversation.id);
            setMessages((current) => [...current.filter((m) => m.id !== optimistic.id && m.id !== draftId), part.data.question, part.data.answer]);
            onConversationSaved?.(part.data.conversation);
            onQuota?.(part.data.quota);
            break;
        case 'error':
            setError(part.errorText);
            patch((m) => ({ ...m, streaming: false, activeTools: [] }));
            break;
    }
}
```

(Los type guards de `switch` sobre la unión con `{ type: string }` no estrechan; declarar la unión sin el miembro genérico y castear `JSON.parse` a `CopilotStreamPart`, ignorando tipos desconocidos en `default`.) Agregar `stop()` que llama `abortRef.current?.abort()` y exportarlo; en `AbortError` marcar el draft `streaming: false, partial: true`.

- [ ] **Step 4: `copilot-message.tsx`.** Para mensajes `streaming`: arriba, chips de `activeTools` (spinner pequeño + label, estilo de chip existente); `blocks` como hoy; texto con cursor parpadeante (`after:` o span `animate-pulse`) mientras `streaming`. Bajo la respuesta final, si `followups.length`, botones que llaman `onSuggest(q)` (misma prop que las sugerencias de catálogo en `copilot-chat-panel.tsx:173`). Si `partial`, nota gris "Respuesta incompleta".

- [ ] **Step 5: Gates frontend**

Run: `npm run types:check && npm run lint:check && npm run format:check && npm run build`
Expected: todo OK (si `format:check` falla, `npm run format` y re-check).

- [ ] **Step 6: Verificación visual.** Levantar preview (skill `worktree-bootstrap`, sección Preview; o Sail en el checkout principal con esta rama) y probar: pregunta de ranking, "¿qué pasó anoche?", seguimiento "¿y la T600?", detener a mitad. Capturas en el PR.

- [ ] **Step 7: Commit**

```bash
git add resources/js
git commit -m "feat: chat de copilot en streaming con estado de tools y seguimientos"
```

---

### Task 9: Gate final, docs y PR

- [ ] **Step 1:** `docs/SAM/logging.md`: confirmar filas de todos los códigos del spec (tool.ran/denied/failed/invalid_args, step.started/budget_reached, turn.started/completed/fallback/failed) y ausencia de `copilot.narration.fallback`.
- [ ] **Step 2:** Suite completa: `APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit --no-coverage` → OK. Pint: `vendor/bin/pint --dirty --format agent` → passed.
- [ ] **Step 3:** Frontend: `npm run types:check && npm run lint:check && npm run format:check && npm run build` → OK.
- [ ] **Step 4:** Revisión de fugas: agente `tenant-isolation-reviewer` sobre la rama.
- [ ] **Step 5:** Push y PR (`gh pr create`), cuerpo con: resumen, ejemplos antes/después del spec, códigos de log nuevos, migración pendiente (`sail artisan migrate`), resultado de Task 0 (¿llegan `Idle`?), costo estimado por turno, capturas. Esperar CI (`gh pr checks --watch`).

---

## Self-Review

- **Cobertura del spec:** agente+tools (T3–T5), tools nuevas + ralentí (T2, T4), guard (T5), collector/memoria (T3, T6), streaming+protocolo+fallback+desconexión (T7), frontend (T4 blocks, T8), pricing (T1), logging (T3, T5, T6, T7, T9), tests por sección (cada task). Retiro de `SdkCopilotNarrator` (T6). ✔
- **Placeholders:** los puntos marcados "confirmar/verificar" son lecturas concretas de un archivo nombrado (APIs del SDK, columna de fecha de `Incident`, forma de blocks del front), no decisiones abiertas.
- **Consistencia de tipos:** `CopilotTurnCollector::record(toolCallId, tool, result, status, durationMs, assetId)` usado igual en T3, T6 (`det_i`), T7; `CopilotTurnOutcome` con 8 campos en T6/T7; `estimateUsageCost` T1→T6; `forAssets(teamId, ids, from, to)` T2→T4.
- **Review Focus:** los 5 casos tienen test en su task (T6 open question, T5 final step, T2 silent idle, T7 disconnect, T4 rank sin telemetría).
