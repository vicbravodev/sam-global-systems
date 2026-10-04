# Clef en sombra + base etiquetada — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Medir durante una ventana acotada si Cloudflare Clef / Clef-flash deciden tan bien como GPT-5.4 sobre los eventos de SAM, con una base de verdad etiquetada por humanos y un reporte reproducible.

**Architecture:** Un listener de `AIEvaluationCompleted` despacha, después del commit, un job de baja prioridad que lee el contexto exacto que vio GPT (`ai_inference_logs.input_snapshot_json`), le quita lo que filtraría la respuesta, adjunta hasta 4 imágenes del evento y pregunta a cada modelo Clef configurado. Las respuestas van a `ai_shadow_evaluations`. Tres comandos completan el ciclo: backfill del historial, etiquetado a ciegas y reporte. La evaluación oficial no depende de nada de esto.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13 · `Http` client de Laravel · Workers AI REST (`POST https://api.cloudflare.com/client/v4/accounts/{account}/ai/run/@cf/cloudflare/{model}`).

**Spec:** `docs/superpowers/specs/2026-10-03-clef-shadow-evaluation-design.md`

## Global Constraints

- Ningún cambio de comportamiento en la evaluación oficial (`EvaluateEventWithAI`, `EvaluateEventMultimodally`): sus tests existentes deben seguir pasando sin tocarlos.
- Sin `RecordUsageEvent` ni `TenantAIQuota` en todo el código de Clef: es costo de plataforma.
- Toda tabla nueva: `team_id` con `foreignId('team_id')->constrained()->cascadeOnDelete()` + `index('team_id')`; columnas JSON con sufijo `_json`.
- Todo log vía `App\Support\SystemLog`; nunca `Log::`. Nunca loguear el estado enviado, imágenes, URLs firmadas, el token ni texto libre. Errores persistidos o logueados: `error: $e` en logs, y `error_code` (clase o código propio) en DB, nunca `getMessage()`.
- Cada código de log nuevo lleva su fila en `docs/SAM/logging.md` (sección `### IA (\`ai\`) y copiloto`).
- Jobs llevan `team_id` explícito; lookup de entrada `withoutGlobalScopes()` y luego `TenantContext::for($teamId, ...)`.
- Commits: `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla del repo). Un cambio atómico por commit.
- Config Clef: `enabled` default `false`, `send_images` default `true`, `shadow_until` sin default (vacío = no despacha), modelos `['clef', 'clef-flash']`, precios `clef` 0.24 y `clef-flash` 0.09 USD por millón de tokens de entrada, `max_images` 4, `timeout_seconds` 15.
- Límites de la API de Clef: ≤ 4 imágenes, sólo PNG/JPEG/WebP, ≤ 4 MiB cada una y ≤ 8 MiB en total; 1–64 preguntas; `score` con 2–10 niveles.
- Comandos de test: `php artisan test --compact --filter=Nombre`. Antes de push: `composer ci:check`.

## Review Focus

1. **Fuga de la respuesta al modelo:** el snapshot guardado contiene `media_assessments` (veredicto visual de otro modelo) y `recent_history.operator_feedback` (el veredicto humano en reevaluaciones). Si llegan a Clef, el reporte mide un examen con las respuestas a la vista. → test en Task 3.
2. **Imágenes reales fuera de límites:** GIF, imágenes > 4 MiB, o cuatro de 3 MiB (> 8 MiB en total). Se espera que se omitan con log y que la llamada salga con las que caben, no que falle. → test en Task 4.
3. **Reintento parcial:** `clef` responde y `clef-flash` da 503. Al reintentar, el job no debe duplicar la fila de `clef` ni volver a pagarla. → test en Task 5.
4. **Eventos reevaluados:** un evento con versiones 1 y 2 de evaluación cuenta una vez en el reporte (la última versión), no dos. → test en Task 9.
5. **Respuesta de Clef inesperada:** falta una pregunta, o `choice` trae una opción que no se pidió. Se espera `malformed_response` y fila `failed`, no una clasificación inventada. → test en Task 2.

---

## Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `config/services.php` (mod) | credenciales `cloudflare` |
| `config/ai.php` (mod) | bloque `clef` |
| `.env.example` (mod) | variables nuevas, vacías |
| `database/migrations/2026_10_08_100000_create_ai_shadow_evaluations_table.php` | tabla |
| `app/Domains/AI/Models/AIShadowEvaluation.php` | modelo tenant-scoped |
| `database/factories/Domains/AI/AIShadowEvaluationFactory.php` | factory |
| `app/Domains/AI/Models/AIEventEvaluation.php` (mod) | relación `shadowEvaluations()` |
| `app/Infrastructure/AI/Clef/ClefClient.php` | HTTP a Workers AI y validación de forma |
| `app/Infrastructure/AI/Clef/ClefResponse.php` | DTO de respuesta cruda validada |
| `app/Infrastructure/AI/Clef/ClefRequestFailedException.php` | error con `reason` y `retryable` |
| `app/Infrastructure/AI/Clef/ClefQuestionSchema.php` | preguntas versionadas desde los enums |
| `app/Infrastructure/AI/Clef/ClefStateBuilder.php` | snapshot → estado sin fugas |
| `app/Infrastructure/AI/Clef/ClefImageLoader.php` | media del evento → imágenes base64 dentro de límites |
| `app/Infrastructure/AI/Clef/ClefEventDecider.php` | orquesta una llamada y mapea a `ClefDecision` |
| `app/Domains/AI/Data/ClefDecision.php` | DTO de decisión |
| `app/Domains/AI/Support/ClefShadowGate.php` | gates compartidos (enabled, ventana, credenciales) |
| `app/Domains/AI/Jobs/ShadowEvaluateWithClefJob.php` | job por evaluación |
| `app/Domains/AI/Listeners/DispatchClefShadowEvaluation.php` | listener |
| `app/Domains/AI/AIServiceProvider.php` (mod) | listener + comandos |
| `app/Domains/AI/Commands/ClefBackfillCommand.php` | `ai:clef-backfill` |
| `app/Domains/AI/Commands/LabelEventsCommand.php` | `ai:label-events` |
| `app/Domains/AI/Queries/ClefShadowComparisonQuery.php` | métricas |
| `app/Domains/AI/Commands/ClefReportCommand.php` | `ai:clef-report` |
| `docs/SAM/logging.md` (mod) | códigos nuevos |
| `tests/Feature/Domains/AI/Clef/*` | tests |

---

### Task 1: Config, tabla y modelo `AIShadowEvaluation`

**Files:**
- Modify: `config/services.php`, `config/ai.php`, `.env.example`
- Create: `database/migrations/2026_10_08_100000_create_ai_shadow_evaluations_table.php`
- Create: `app/Domains/AI/Models/AIShadowEvaluation.php`
- Create: `database/factories/Domains/AI/AIShadowEvaluationFactory.php`
- Modify: `app/Domains/AI/Models/AIEventEvaluation.php` (relación)
- Test: `tests/Feature/Domains/AI/Clef/Concerns/BuildsClefFixtures.php`, `tests/Feature/Domains/AI/Clef/AIShadowEvaluationModelTest.php`

**Interfaces:**
- Produces: `config('ai.clef.*')`, `config('services.cloudflare.account_id'|'auth_token')`; modelo `AIShadowEvaluation` (columnas abajo); `AIEventEvaluation::shadowEvaluations(): HasMany`; trait de test `BuildsClefFixtures` con `makeEvaluation(Team $team, array $snapshot = [], array $evaluation = []): AIEventEvaluation` y `clefResponse(string $classification, array $overrides = []): array`.

- [ ] **Step 1: Config**

`config/services.php`, al final del array:

```php
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'auth_token' => env('CLOUDFLARE_AUTH_TOKEN'),
    ],
```

`config/ai.php`, nueva clave de primer nivel junto a `providers`:

```php
    /*
    |--------------------------------------------------------------------------
    | Cloudflare Clef (medición en sombra, temporal)
    |--------------------------------------------------------------------------
    |
    | Evalúa en paralelo, sin decidir nada, lo mismo que evaluó GPT, para
    | comparar. Se apaga sola pasada `shadow_until`. Spec:
    | docs/superpowers/specs/2026-10-03-clef-shadow-evaluation-design.md
    |
    */
    'clef' => [
        'enabled' => (bool) env('AI_CLEF_SHADOW_ENABLED', false),
        'shadow_until' => env('AI_CLEF_SHADOW_UNTIL'),
        'models' => ['clef', 'clef-flash'],
        'sample_rate' => (float) env('AI_CLEF_SHADOW_SAMPLE_RATE', 1.0),
        'send_images' => (bool) env('AI_CLEF_SHADOW_SEND_IMAGES', true),
        'max_images' => 4,
        'max_image_bytes' => 4 * 1024 * 1024,
        'max_total_image_bytes' => 8 * 1024 * 1024,
        'timeout_seconds' => 15,
        'pricing_per_million_input' => ['clef' => 0.24, 'clef-flash' => 0.09],
    ],
```

`.env.example`, junto a las otras de IA:

```
CLOUDFLARE_ACCOUNT_ID=
CLOUDFLARE_AUTH_TOKEN=
AI_CLEF_SHADOW_ENABLED=false
AI_CLEF_SHADOW_UNTIL=
AI_CLEF_SHADOW_SAMPLE_RATE=1.0
AI_CLEF_SHADOW_SEND_IMAGES=true
```

- [ ] **Step 2: Migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_shadow_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_event_evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->string('model', 32);
            $table->unsignedSmallInteger('schema_version');
            $table->string('source', 16);
            $table->string('status', 16);
            $table->string('classification', 32)->nullable();
            $table->json('classification_probabilities_json')->nullable();
            $table->decimal('risk_score', 3, 2)->nullable();
            $table->decimal('needs_human_probability', 4, 3)->nullable();
            $table->json('media_answers_json')->nullable();
            $table->unsignedSmallInteger('images_sent')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost_estimate', 8, 5)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('normalized_event_id');
            $table->unique(['ai_event_evaluation_id', 'model', 'schema_version'], 'ai_shadow_eval_model_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_shadow_evaluations');
    }
};
```

- [ ] **Step 3: Modelo**

```php
<?php

namespace App\Domains\AI\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\AI\AIShadowEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de un modelo Clef evaluando en sombra lo mismo que evaluó GPT.
 * Medición temporal: nunca alimenta decisiones ni incidentes.
 */
class AIShadowEvaluation extends Model
{
    /** @use HasFactory<AIShadowEvaluationFactory> */
    use BelongsToTenant, HasFactory;

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_FAILED = 'failed';

    public const string SOURCE_LIVE = 'live';

    public const string SOURCE_BACKFILL = 'backfill';

    protected $table = 'ai_shadow_evaluations';

    protected $fillable = [
        'team_id',
        'ai_event_evaluation_id',
        'normalized_event_id',
        'model',
        'schema_version',
        'source',
        'status',
        'classification',
        'classification_probabilities_json',
        'risk_score',
        'needs_human_probability',
        'media_answers_json',
        'images_sent',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'cost_estimate',
        'error_code',
    ];

    /**
     * @return BelongsTo<AIEventEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AIEventEvaluation::class, 'ai_event_evaluation_id');
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'classification_probabilities_json' => 'array',
            'media_answers_json' => 'array',
            'risk_score' => 'float',
            'needs_human_probability' => 'float',
            'images_sent' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_estimate' => 'float',
        ];
    }

    protected static function newFactory(): AIShadowEvaluationFactory
    {
        return AIShadowEvaluationFactory::new();
    }
}
```

En `AIEventEvaluation.php`, junto a `inferenceLogs()`:

```php
    /**
     * @return HasMany<AIShadowEvaluation, $this>
     */
    public function shadowEvaluations(): HasMany
    {
        return $this->hasMany(AIShadowEvaluation::class, 'ai_event_evaluation_id');
    }
```

- [ ] **Step 4: Factory** (el hijo hereda el tenant del padre)

```php
<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIShadowEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIShadowEvaluation>
 */
class AIShadowEvaluationFactory extends Factory
{
    protected $model = AIShadowEvaluation::class;

    public function definition(): array
    {
        return [
            'ai_event_evaluation_id' => AIEventEvaluation::factory(),
            'team_id' => fn (array $attributes) => AIEventEvaluation::withoutGlobalScopes()->find($attributes['ai_event_evaluation_id'])?->team_id,
            'normalized_event_id' => fn (array $attributes) => AIEventEvaluation::withoutGlobalScopes()->find($attributes['ai_event_evaluation_id'])?->normalized_event_id,
            'model' => 'clef',
            'schema_version' => 1,
            'source' => AIShadowEvaluation::SOURCE_LIVE,
            'status' => AIShadowEvaluation::STATUS_SUCCESS,
            'classification' => 'real_event',
            'classification_probabilities_json' => ['real_event' => 0.8, 'false_positive' => 0.05, 'noise' => 0.05, 'duplicate' => 0.05, 'unclear' => 0.05],
            'risk_score' => 0.6,
            'needs_human_probability' => 0.7,
            'media_answers_json' => null,
            'images_sent' => 0,
            'input_tokens' => 2800,
            'output_tokens' => 0,
            'latency_ms' => 200,
            'cost_estimate' => 0.00067,
            'error_code' => null,
        ];
    }

    public function failed(string $errorCode = 'http_503'): static
    {
        return $this->state(fn () => [
            'status' => AIShadowEvaluation::STATUS_FAILED,
            'classification' => null,
            'classification_probabilities_json' => null,
            'risk_score' => null,
            'needs_human_probability' => null,
            'error_code' => $errorCode,
        ]);
    }
}
```

- [ ] **Step 5: Trait de fixtures de test**

`tests/Feature/Domains/AI/Clef/Concerns/BuildsClefFixtures.php`:

```php
<?php

namespace Tests\Feature\Domains\AI\Clef\Concerns;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;

trait BuildsClefFixtures
{
    /**
     * Evaluación oficial + inference log con snapshot, todo en el mismo tenant.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $evaluation
     */
    protected function makeEvaluation(Team $team, array $snapshot = [], array $evaluation = []): AIEventEvaluation
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $model = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            ...$evaluation,
        ]);

        AIInferenceLog::factory()->create([
            'evaluation_id' => $model->id,
            'input_snapshot_json' => $snapshot !== [] ? $snapshot : [
                'normalized_event_id' => $event->id,
                'normalized_event' => ['type_code' => 'harsh_brake', 'type_name' => 'Frenado brusco'],
                'telemetry' => ['speed_kph' => 62],
                'recent_history' => [],
                'media_assessments' => [],
            ],
            'input_tokens' => 2800,
        ]);

        return $model;
    }

    /**
     * Cuerpo de Workers AI (sobre `result`) para las preguntas sin imágenes.
     *
     * @param  array<string, mixed>  $overrides  se mezcla sobre `answers`
     * @return array<string, mixed>
     */
    protected function clefResponse(string $classification, array $overrides = [], int $inputTokens = 2900): array
    {
        $options = ['real_event', 'false_positive', 'noise', 'duplicate', 'unclear'];
        $probabilities = array_fill_keys($options, 0.05);
        $probabilities[$classification] = 0.8;

        return [
            'success' => true,
            'errors' => [],
            'result' => [
                'model' => 'clef',
                'answers' => [
                    'classification' => ['type' => 'choice', 'choice' => $classification, 'probabilities' => $probabilities, 'confidence' => 0.8],
                    'severity' => ['type' => 'score', 'score' => 3.0, 'legend' => [], 'probabilities' => ['0' => 0.0, '1' => 0.1, '2' => 0.1, '3' => 0.5, '4' => 0.3], 'confidence' => 0.5],
                    'needs_human_now' => ['type' => 'noul', 'noul' => 0.72],
                    ...$overrides,
                ],
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 0],
            ],
        ];
    }
}
```

- [ ] **Step 6: Test del modelo (scope de tenant + factory coherente)**

`tests/Feature/Domains/AI/Clef/AIShadowEvaluationModelTest.php`:

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class AIShadowEvaluationModelTest extends TestCase
{
    use BuildsClefFixtures, RefreshDatabase;

    public function test_factory_inherits_the_tenant_of_its_evaluation(): void
    {
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        $shadow = AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $evaluation->id]);

        $this->assertSame($team->id, $shadow->team_id);
        $this->assertSame($evaluation->normalized_event_id, $shadow->normalized_event_id);
        $this->assertSame(1, $evaluation->shadowEvaluations()->count());
    }

    public function test_scope_hides_other_tenants_rows(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($teamA)->id]);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($teamB)->id]);

        $visible = TenantContext::for($teamA->id, fn () => AIShadowEvaluation::query()->pluck('team_id')->all());

        $this->assertSame([$teamA->id], $visible);
    }
}
```

- [ ] **Step 7: Correr**

Run: `php artisan test --compact --filter=AIShadowEvaluationModelTest`
Expected: PASS (2 tests). Si falla porque la factory de `NormalizedEvent` crea otro team para sus hijos, pasar el `team_id` explícito a los hijos dentro de `makeEvaluation`.

- [ ] **Step 8: Commit**

```bash
git add config/services.php config/ai.php .env.example database/migrations/2026_10_08_100000_create_ai_shadow_evaluations_table.php app/Domains/AI/Models/AIShadowEvaluation.php app/Domains/AI/Models/AIEventEvaluation.php database/factories/Domains/AI/AIShadowEvaluationFactory.php tests/Feature/Domains/AI/Clef
git commit -m "feat: tabla y modelo de evaluaciones en sombra de clef"
```

---

### Task 2: `ClefClient`

**Files:**
- Create: `app/Infrastructure/AI/Clef/ClefClient.php`, `ClefResponse.php`, `ClefRequestFailedException.php`
- Test: `tests/Feature/Domains/AI/Clef/ClefClientTest.php`

**Interfaces:**
- Consumes: `config('services.cloudflare.*')`, `config('ai.clef.timeout_seconds')`.
- Produces:
  - `ClefClient::run(string $model, array $state, array $questions, array $images = []): ClefResponse` — `$images` es `list<array{content_type: string, base64: string}>`.
  - `ClefResponse` readonly: `string $model`, `array $answers` (por id de pregunta), `int $inputTokens`, `int $outputTokens`, `int $latencyMs`.
  - `ClefRequestFailedException` con `public readonly string $reason` (`http_{status}`, `timeout`, `connection`, `malformed_response`) y `public readonly bool $retryable`.

- [ ] **Step 1: Tests que fallan**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefClient;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefClientTest extends TestCase
{
    use BuildsClefFixtures;

    /** @var array<string, array<string, mixed>> */
    private array $questions = [
        'classification' => ['type' => 'choice', 'instructions' => 'x', 'criteria' => ['real_event' => 'a', 'false_positive' => 'b', 'noise' => 'c', 'duplicate' => 'd', 'unclear' => 'e']],
        'severity' => ['type' => 'score', 'instructions' => 'x', 'criteria' => ['0', '1', '2', '3', '4']],
        'needs_human_now' => ['type' => 'noul', 'instructions' => 'x'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare.account_id' => 'acc-123', 'services.cloudflare.auth_token' => 'tok-secret']);
    }

    public function test_sends_model_state_questions_and_images_and_parses_answers(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);

        $response = app(ClefClient::class)->run('clef-flash', ['a' => 1], $this->questions, [['content_type' => 'image/jpeg', 'base64' => 'AAA=']]);

        $this->assertSame('noise', $response->answers['classification']['choice']);
        $this->assertSame(2900, $response->inputTokens);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acc-123/ai/run/@cf/cloudflare/clef-flash'
                && $request->hasHeader('Authorization', 'Bearer tok-secret')
                && $request['model'] === 'clef-flash'
                && $request['state'] === ['a' => 1]
                && array_keys($request['questions']) === ['classification', 'severity', 'needs_human_now']
                && $request['images'] === [['content_type' => 'image/jpeg', 'base64' => 'AAA=']];
        });
    }

    public function test_omits_images_key_when_there_are_none(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('images', $request->data()));
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 503)]);

        try {
            app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
            $this->fail('Expected exception');
        } catch (ClefRequestFailedException $e) {
            $this->assertSame('http_503', $e->reason);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_rate_limit_is_retryable_and_client_error_is_not(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()->push([], 429)->push([], 400)]);
        $client = app(ClefClient::class);

        foreach ([['http_429', true], ['http_400', false]] as [$reason, $retryable]) {
            try {
                $client->run('clef', ['a' => 1], $this->questions);
                $this->fail('Expected exception');
            } catch (ClefRequestFailedException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($retryable, $e->retryable);
            }
        }
    }

    public function test_missing_answer_is_malformed(): void
    {
        $body = $this->clefResponse('noise');
        unset($body['result']['answers']['severity']);
        Http::fake(['api.cloudflare.com/*' => Http::response($body)]);

        $this->expectExceptionObject(new ClefRequestFailedException('malformed_response', false));

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
    }

    public function test_choice_outside_requested_options_is_malformed(): void
    {
        $body = $this->clefResponse('noise');
        $body['result']['answers']['classification']['choice'] = 'panic';
        Http::fake(['api.cloudflare.com/*' => Http::response($body)]);

        $this->expectExceptionObject(new ClefRequestFailedException('malformed_response', false));

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact --filter=ClefClientTest` → FAIL (`Class "App\Infrastructure\AI\Clef\ClefClient" not found`).

- [ ] **Step 3: Implementación**

`ClefRequestFailedException.php`:

```php
<?php

namespace App\Infrastructure\AI\Clef;

use RuntimeException;

/**
 * Fallo de una llamada a Clef. El mensaje es un código propio: nunca lleva el
 * cuerpo de la respuesta, el estado enviado ni el token.
 */
class ClefRequestFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable,
    ) {
        parent::__construct('Clef request failed: '.$reason);
    }
}
```

`ClefResponse.php`:

```php
<?php

namespace App\Infrastructure\AI\Clef;

final readonly class ClefResponse
{
    /**
     * @param  array<string, array<string, mixed>>  $answers  una respuesta por id de pregunta
     */
    public function __construct(
        public string $model,
        public array $answers,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
```

`ClefClient.php`:

```php
<?php

namespace App\Infrastructure\AI\Clef;

use App\Support\SystemLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de Workers AI para los modelos de decisión Clef. Valida que
 * vuelva una respuesta por pregunta y, en `choice`, que la opción elegida sea
 * una de las pedidas: mejor fallar que inventar una clasificación.
 */
class ClefClient
{
    private const string ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/@cf/cloudflare/%s';

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @param  list<array{content_type: string, base64: string}>  $images
     */
    public function run(string $model, array $state, array $questions, array $images = []): ClefResponse
    {
        $payload = ['model' => $model, 'state' => $state, 'questions' => $questions];

        if ($images !== []) {
            $payload['images'] = $images;
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken((string) config('services.cloudflare.auth_token'))
                ->acceptJson()
                ->timeout((int) config('ai.clef.timeout_seconds', 15))
                ->post(sprintf(self::ENDPOINT, (string) config('services.cloudflare.account_id'), $model), $payload);
        } catch (ConnectionException $e) {
            throw new ClefRequestFailedException(str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'connection', true);
        }

        $latencyMs = SystemLog::elapsedMs($startedAt);

        if ($response->failed()) {
            $status = $response->status();

            throw new ClefRequestFailedException('http_'.$status, $status === 429 || $status >= 500);
        }

        $body = $response->json();
        $result = is_array($body) && is_array($body['result'] ?? null) ? $body['result'] : $body;

        if (! is_array($result) || ! is_array($result['answers'] ?? null)) {
            throw new ClefRequestFailedException('malformed_response', false);
        }

        foreach ($questions as $id => $question) {
            $answer = $result['answers'][$id] ?? null;

            if (! is_array($answer) || ($answer['type'] ?? null) !== $question['type']) {
                throw new ClefRequestFailedException('malformed_response', false);
            }

            if ($question['type'] === 'choice' && ! array_key_exists((string) ($answer['choice'] ?? ''), $question['criteria'])) {
                throw new ClefRequestFailedException('malformed_response', false);
            }
        }

        return new ClefResponse(
            model: (string) ($result['model'] ?? $model),
            answers: $result['answers'],
            inputTokens: (int) ($result['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($result['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
        );
    }
}
```

> Nota: `$e->getMessage()` sólo se usa para clasificar el tipo de fallo, nunca se loguea ni se persiste. Si `RawExceptionMessageConventionTest` lo marca, cambiar la detección por `$e->getHandlerContext()['errno'] ?? null` (cURL 28 = timeout).

- [ ] **Step 4: Run** `php artisan test --compact --filter=ClefClientTest` → PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/AI/Clef tests/Feature/Domains/AI/Clef/ClefClientTest.php
git commit -m "feat: cliente de workers ai para los modelos clef"
```

---

### Task 3: `ClefQuestionSchema` y `ClefStateBuilder`

**Files:**
- Create: `app/Infrastructure/AI/Clef/ClefQuestionSchema.php`, `ClefStateBuilder.php`
- Test: `tests/Feature/Domains/AI/Clef/ClefQuestionSchemaTest.php`, `ClefStateBuilderTest.php`

**Interfaces:**
- Produces:
  - `ClefQuestionSchema::VERSION = 1` (int const).
  - `ClefQuestionSchema::CLASSIFICATIONS`: `list<string>` = `['real_event','false_positive','noise','duplicate','unclear']`.
  - `ClefQuestionSchema::SEVERITY_LEVELS = 5`.
  - `ClefQuestionSchema::MEDIA_SIGNALS = ['driver_visible','passenger_detected','visible_threat','cabin_appears_normal','vehicle_moving']`.
  - `ClefQuestionSchema::for(bool $withImages): array<string, array<string, mixed>>`.
  - `ClefStateBuilder::fromSnapshot(array $snapshot): array<string, mixed>`.

- [ ] **Step 1: Tests que fallan**

`ClefQuestionSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use Tests\TestCase;

class ClefQuestionSchemaTest extends TestCase
{
    public function test_classification_options_are_valid_enum_cases(): void
    {
        $criteria = ClefQuestionSchema::for(false)['classification']['criteria'];

        $this->assertSame(ClefQuestionSchema::CLASSIFICATIONS, array_keys($criteria));
        foreach (array_keys($criteria) as $option) {
            $this->assertNotNull(EventClassification::tryFrom($option));
        }
    }

    public function test_text_only_schema_has_no_media_questions(): void
    {
        $this->assertSame(['classification', 'severity', 'needs_human_now'], array_keys(ClefQuestionSchema::for(false)));
    }

    public function test_image_schema_adds_media_result_signals_and_person_count(): void
    {
        $questions = ClefQuestionSchema::for(true);

        foreach (MediaAssessmentResult::cases() as $case) {
            $this->assertArrayHasKey($case->value, $questions['media_result']['criteria']);
        }
        foreach (ClefQuestionSchema::MEDIA_SIGNALS as $signal) {
            $this->assertSame(['si', 'no', 'no_visible'], array_keys($questions[$signal]['criteria']));
        }
        $this->assertSame(['0', '1', '2', '3_o_mas'], array_keys($questions['persons_visible']['criteria']));
        $this->assertLessThanOrEqual(64, count($questions));
    }

    public function test_severity_has_five_ordered_levels(): void
    {
        $this->assertCount(ClefQuestionSchema::SEVERITY_LEVELS, ClefQuestionSchema::for(false)['severity']['criteria']);
    }
}
```

> Si `MediaAssessmentResult` tiene casos que no aplican a una imagen (p. ej. `unavailable`), el test sigue siendo correcto: el schema los incluye todos; así no hay dos fuentes de verdad.

`ClefStateBuilderTest.php` (Review Focus 1):

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefStateBuilder;
use Tests\TestCase;

class ClefStateBuilderTest extends TestCase
{
    public function test_strips_other_models_media_verdicts_and_operator_feedback(): void
    {
        $state = (new ClefStateBuilder)->fromSnapshot([
            'normalized_event' => ['type_code' => 'panic_button'],
            'media_assessments' => [['result' => 'confirms_event']],
            'recent_history' => ['similar_events_24h' => 3, 'operator_feedback' => ['verdicts' => ['false_positive']]],
            'telemetry' => ['speed_kph' => 0],
        ]);

        $this->assertArrayNotHasKey('media_assessments', $state);
        $this->assertSame(['similar_events_24h' => 3], $state['recent_history']);
        $this->assertSame(['speed_kph' => 0], $state['telemetry']);
        $this->assertSame(['type_code' => 'panic_button'], $state['normalized_event']);
    }

    public function test_snapshot_without_those_keys_passes_through(): void
    {
        $this->assertSame(['telemetry' => []], (new ClefStateBuilder)->fromSnapshot(['telemetry' => []]));
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact --filter='ClefQuestionSchemaTest|ClefStateBuilderTest'` → FAIL (clases inexistentes).

- [ ] **Step 3: Implementación**

`ClefStateBuilder.php`:

```php
<?php

namespace App\Infrastructure\AI\Clef;

/**
 * Convierte el snapshot que vio GPT en el estado que ve Clef. Quita lo que
 * le daría la respuesta: el veredicto visual de otro modelo
 * (`media_assessments`; Clef ve las imágenes por sí mismo) y el veredicto
 * humano que viaja en reevaluaciones (`recent_history.operator_feedback`).
 */
class ClefStateBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function fromSnapshot(array $snapshot): array
    {
        unset($snapshot['media_assessments']);

        if (is_array($snapshot['recent_history'] ?? null)) {
            unset($snapshot['recent_history']['operator_feedback']);
        }

        return $snapshot;
    }
}
```

`ClefQuestionSchema.php`:

```php
<?php

namespace App\Infrastructure\AI\Clef;

use App\Domains\AI\Enums\MediaAssessmentResult;

/**
 * Preguntas tipadas que se le hacen a Clef. Cambiar textos u opciones exige
 * subir VERSION: el reporte compara sólo filas de la misma versión.
 */
final class ClefQuestionSchema
{
    public const int VERSION = 1;

    public const int SEVERITY_LEVELS = 5;

    /** @var list<string> */
    public const array CLASSIFICATIONS = ['real_event', 'false_positive', 'noise', 'duplicate', 'unclear'];

    /** @var list<string> */
    public const array MEDIA_SIGNALS = ['driver_visible', 'passenger_detected', 'visible_threat', 'cabin_appears_normal', 'vehicle_moving'];

    private const array SIGNAL_INSTRUCTIONS = [
        'driver_visible' => '¿Se ve al conductor en las imágenes?',
        'passenger_detected' => '¿Hay algún pasajero u otra persona además del conductor?',
        'visible_threat' => '¿Se ve una amenaza (arma, agresión, persona forzando la unidad)?',
        'cabin_appears_normal' => '¿La cabina se ve en condiciones normales?',
        'vehicle_moving' => '¿El vehículo parece estar en movimiento?',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function for(bool $withImages): array
    {
        $questions = [
            'classification' => [
                'type' => 'choice',
                'instructions' => 'Eres el monitor de seguridad de una flota de transporte. Con el evento reportado por el proveedor telemático, su contexto (telemetría, ubicación, historial reciente) y las imágenes si las hay, decide qué es este evento.',
                'criteria' => [
                    'real_event' => 'El evento ocurrió y requiere atención según la evidencia.',
                    'false_positive' => 'El proveedor reportó algo que la evidencia contradice: no ocurrió.',
                    'noise' => 'Señal técnica sin relevancia operativa (falla momentánea, ruido del dispositivo).',
                    'duplicate' => 'Repite un evento ya reportado del mismo activo hace poco.',
                    'unclear' => 'La evidencia no alcanza para decidir; necesita revisión humana.',
                ],
            ],
            'severity' => [
                'type' => 'score',
                'instructions' => '¿Qué tan grave es la situación para la seguridad del conductor, la carga o terceros?',
                'criteria' => ['Ninguna', 'Baja', 'Media', 'Alta', 'Crítica'],
            ],
            'needs_human_now' => [
                'type' => 'noul',
                'instructions' => '¿Un operador de monitoreo debe revisar este evento de inmediato?',
            ],
        ];

        if (! $withImages) {
            return $questions;
        }

        $questions['media_result'] = [
            'type' => 'choice',
            'instructions' => '¿Qué muestran las imágenes respecto al evento reportado?',
            'criteria' => array_combine(
                array_map(fn (MediaAssessmentResult $case): string => $case->value, MediaAssessmentResult::cases()),
                array_map(fn (MediaAssessmentResult $case): string => self::mediaResultCriterion($case), MediaAssessmentResult::cases()),
            ),
        ];

        foreach (self::MEDIA_SIGNALS as $signal) {
            $questions[$signal] = [
                'type' => 'choice',
                'instructions' => self::SIGNAL_INSTRUCTIONS[$signal],
                'criteria' => ['si' => 'Sí', 'no' => 'No', 'no_visible' => 'No se puede determinar con las imágenes'],
            ];
        }

        $questions['persons_visible'] = [
            'type' => 'choice',
            'instructions' => '¿Cuántas personas se ven en total?',
            'criteria' => ['0' => 'Ninguna', '1' => 'Una', '2' => 'Dos', '3_o_mas' => 'Tres o más'],
        ];

        return $questions;
    }

    private static function mediaResultCriterion(MediaAssessmentResult $case): string
    {
        return match ($case->value) {
            'confirms_event' => 'Las imágenes confirman el evento.',
            'contradicts_event' => 'Las imágenes contradicen el evento.',
            'inconclusive' => 'Las imágenes no permiten confirmar ni descartar.',
            'low_quality' => 'Las imágenes son de muy baja calidad para juzgar.',
            default => 'Las imágenes no están disponibles o no aplican.',
        };
    }
}
```

- [ ] **Step 4: Run** el mismo filtro → PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/AI/Clef/ClefQuestionSchema.php app/Infrastructure/AI/Clef/ClefStateBuilder.php tests/Feature/Domains/AI/Clef/ClefQuestionSchemaTest.php tests/Feature/Domains/AI/Clef/ClefStateBuilderTest.php
git commit -m "feat: preguntas versionadas y estado sin fugas para clef"
```

---

### Task 4: `ClefImageLoader`

**Files:**
- Create: `app/Infrastructure/AI/Clef/ClefImageLoader.php`
- Test: `tests/Feature/Domains/AI/Clef/ClefImageLoaderTest.php`

**Interfaces:**
- Consumes: `App\Contracts\ObjectStorage::get(string): ?string`, `App\Domains\AI\Support\ImageSignature::detect(string): ?string`, modelo `EventMediaContext`.
- Produces: `ClefImageLoader::forEvent(int $evaluationId, int $normalizedEventId): array{images: list<array{content_type: string, base64: string}>, skipped: array<string, int>}`. Debe llamarse dentro de `TenantContext::for(...)`.

- [ ] **Step 1: Test que falla** (Review Focus 2)

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Infrastructure\AI\Clef\ClefImageLoader;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefImageLoaderTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    private const string JPEG = "\xFF\xD8\xFF\xE0";

    private const string GIF = 'GIF89a';

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $files = &$this->files;
        $this->app->instance(ObjectStorage::class, new class($files) implements ObjectStorage
        {
            /** @param array<string, string> $files */
            public function __construct(private array &$files) {}

            public function put(string $path, mixed $contents, array $options = []): void
            {
                $this->files[$path] = (string) $contents;
            }

            public function get(string $path): ?string
            {
                return $this->files[$path] ?? null;
            }

            public function delete(string $path): void {}

            public function exists(string $path): bool
            {
                return isset($this->files[$path]);
            }

            public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $options = []): string
            {
                return 'https://example.test/'.$path;
            }
        });
    }

    public function test_loads_ready_images_and_skips_gif_oversize_missing_and_video(): void
    {
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $media = fn (string $path, MediaType $type = MediaType::Snapshot) => EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'media_type' => $type,
            'retrieval_status' => MediaRetrievalStatus::Ready,
            'storage_path' => $path,
        ]);

        $this->files['ok.jpg'] = self::JPEG.str_repeat('a', 100);
        $this->files['anim.gif'] = self::GIF.str_repeat('a', 100);
        $this->files['big.jpg'] = self::JPEG.str_repeat('a', 4 * 1024 * 1024);
        $media('ok.jpg');
        $media('anim.gif');
        $media('big.jpg');
        $media('missing.jpg');
        $media('clip.mp4', MediaType::Video);

        $result = TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertCount(1, $result['images']);
        $this->assertSame('image/jpeg', $result['images'][0]['content_type']);
        $this->assertSame(['unsupported_type' => 1, 'oversize' => 1, 'missing' => 1], $result['skipped']);
        $this->assertSystemLogged('ai.clef_shadow.image_skipped');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_respects_total_budget_and_max_count(): void
    {
        config(['ai.clef.max_total_image_bytes' => 250, 'ai.clef.max_images' => 4]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        foreach (range(1, 5) as $i) {
            $this->files["f{$i}.jpg"] = self::JPEG.str_repeat('a', 100);
            EventMediaContext::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $evaluation->normalized_event_id,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'storage_path' => "f{$i}.jpg",
            ]);
        }

        $result = TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertCount(2, $result['images']);
        $this->assertSame(['total_budget' => 3], $result['skipped']);
    }
}
```

> Si `EventMediaContext` usa `BelongsToTenant` y la factory resuelve `team_id` de otra forma, mantener los `team_id` explícitos como arriba. Si el repo ya tiene un fake de `ObjectStorage` en `tests/`, usarlo en lugar de la clase anónima.

- [ ] **Step 2: Run** `php artisan test --compact --filter=ClefImageLoaderTest` → FAIL.

- [ ] **Step 3: Implementación**

```php
<?php

namespace App\Infrastructure\AI\Clef;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Support\ImageSignature;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Support\SystemLog;

/**
 * Junta hasta `ai.clef.max_images` imágenes listas del evento, dentro de los
 * límites de la API de Clef (PNG/JPEG/WebP, tamaño por imagen y total). Lo
 * que no cabe se omite y se registra; nunca hace fallar la llamada.
 * Se invoca dentro del TenantContext del evento.
 */
class ClefImageLoader
{
    private const array ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly ObjectStorage $storage) {}

    /**
     * @return array{images: list<array{content_type: string, base64: string}>, skipped: array<string, int>}
     */
    public function forEvent(int $evaluationId, int $normalizedEventId): array
    {
        $maxImages = (int) config('ai.clef.max_images', 4);
        $maxBytes = (int) config('ai.clef.max_image_bytes', 4 * 1024 * 1024);
        $budget = (int) config('ai.clef.max_total_image_bytes', 8 * 1024 * 1024);

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $normalizedEventId)
            ->whereIn('media_type', [MediaType::Image, MediaType::Snapshot])
            ->where('retrieval_status', MediaRetrievalStatus::Ready)
            ->whereNotNull('storage_path')
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        $images = [];
        $skipped = [];
        $used = 0;

        foreach ($media as $item) {
            $reason = null;
            $bytes = $this->storage->get((string) $item->storage_path);
            $mime = $bytes !== null && $bytes !== '' ? ImageSignature::detect($bytes) : null;

            if ($bytes === null || $bytes === '') {
                $reason = 'missing';
            } elseif ($mime === null || ! in_array($mime, self::ACCEPTED, true)) {
                $reason = 'unsupported_type';
            } elseif (strlen($bytes) > $maxBytes) {
                $reason = 'oversize';
            } elseif (count($images) >= $maxImages) {
                $reason = 'max_images';
            } elseif ($used + strlen($bytes) > $budget) {
                $reason = 'total_budget';
            }

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $used += strlen($bytes);
            $images[] = ['content_type' => $mime, 'base64' => base64_encode($bytes)];
        }

        if ($skipped !== []) {
            SystemLog::skipped(
                'ai.clef_shadow.image_skipped',
                reason: 'limits',
                input: ['evaluation_id' => $evaluationId],
                calc: [
                    'candidates' => $media->count(),
                    'sent' => count($images),
                    'skipped_by_reason' => $skipped,
                    'max_images' => $maxImages,
                    'max_image_bytes' => $maxBytes,
                    'max_total_image_bytes' => $budget,
                    'bytes_sent' => $used,
                ],
            );
        }

        return ['images' => $images, 'skipped' => $skipped];
    }
}
```

> El test de presupuesto espera `total_budget => 3`: con 250 bytes de presupuesto y archivos de 104 bytes caben 2; los 3 restantes chocan con el presupuesto antes que con `max_images` (que es 4). Si el orden de las validaciones cambia, ajustar el test, no la regla.

- [ ] **Step 4: Run** → PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/AI/Clef/ClefImageLoader.php tests/Feature/Domains/AI/Clef/ClefImageLoaderTest.php
git commit -m "feat: carga de imágenes del evento dentro de los límites de clef"
```

---

### Task 5: `ClefDecision`, `ClefEventDecider` y `ShadowEvaluateWithClefJob`

**Files:**
- Create: `app/Domains/AI/Data/ClefDecision.php`, `app/Infrastructure/AI/Clef/ClefEventDecider.php`, `app/Domains/AI/Jobs/ShadowEvaluateWithClefJob.php`
- Test: `tests/Feature/Domains/AI/Clef/ClefEventDeciderTest.php`, `ShadowEvaluateWithClefJobTest.php`, `ShadowEvaluateWithClefJobTenantLeakTest.php`

**Interfaces:**
- Consumes: `ClefClient::run`, `ClefQuestionSchema::for`, `ClefStateBuilder::fromSnapshot`, `ClefImageLoader::forEvent`, `AIShadowEvaluation` (Task 1).
- Produces:
  - `ClefDecision` readonly: `string $model, string $classification, array $classificationProbabilities, float $riskScore, float $needsHumanProbability, ?array $mediaAnswers, int $imagesSent, int $inputTokens, int $outputTokens, int $latencyMs, float $costEstimate`.
  - `ClefEventDecider::decide(string $model, array $snapshot, array $images): ClefDecision`.
  - `ShadowEvaluateWithClefJob::__construct(int $teamId, int $evaluationId, string $source = AIShadowEvaluation::SOURCE_LIVE)`. Cola `default`.

- [ ] **Step 1: Test del decider**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefEventDecider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefEventDeciderTest extends TestCase
{
    use BuildsClefFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok']);
    }

    public function test_maps_answers_to_decision_with_cost_and_risk(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise', inputTokens: 1_000_000))]);

        $decision = app(ClefEventDecider::class)->decide('clef', ['media_assessments' => [['x']], 'telemetry' => []], []);

        $this->assertSame('noise', $decision->classification);
        $this->assertSame(0.8, $decision->classificationProbabilities['noise']);
        $this->assertSame(0.75, $decision->riskScore);          // score 3 de 0..4
        $this->assertSame(0.72, $decision->needsHumanProbability);
        $this->assertNull($decision->mediaAnswers);
        $this->assertSame(0.24, $decision->costEstimate);        // 1M tokens × $0.24
        Http::assertSent(fn (Request $r): bool => ! array_key_exists('media_assessments', $r['state']) && ! isset($r['questions']['media_result']));
    }

    public function test_with_images_asks_media_questions_and_keeps_their_answers(): void
    {
        $media = ['media_result' => ['type' => 'choice', 'choice' => 'confirms_event', 'probabilities' => ['confirms_event' => 0.9], 'confidence' => 0.9],
            'persons_visible' => ['type' => 'choice', 'choice' => '1', 'probabilities' => ['1' => 0.9], 'confidence' => 0.9]];
        foreach (['driver_visible', 'passenger_detected', 'visible_threat', 'cabin_appears_normal', 'vehicle_moving'] as $s) {
            $media[$s] = ['type' => 'choice', 'choice' => 'no_visible', 'probabilities' => ['no_visible' => 1.0], 'confidence' => 1.0];
        }
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('real_event', $media))]);

        $decision = app(ClefEventDecider::class)->decide('clef-flash', [], [['content_type' => 'image/jpeg', 'base64' => 'AA==']]);

        $this->assertSame(1, $decision->imagesSent);
        $this->assertSame('confirms_event', $decision->mediaAnswers['media_result']['choice']);
        $this->assertSame('no_visible', $decision->mediaAnswers['visible_threat']['choice']);
    }
}
```

- [ ] **Step 2: Test del job** (incluye Review Focus 3)

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ShadowEvaluateWithClefJobTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.cloudflare.account_id' => 'acc',
            'services.cloudflare.auth_token' => 'tok',
            'ai.clef.models' => ['clef', 'clef-flash'],
            'ai.clef.send_images' => false,
        ]);
    }

    public function test_persists_one_row_per_model_without_touching_the_official_evaluation(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $before = $evaluation->fresh()->toArray();

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $rows = AIShadowEvaluation::withoutGlobalScopes()->orderBy('model')->get();
        $this->assertSame(['clef', 'clef-flash'], $rows->pluck('model')->all());
        $this->assertSame(['noise', 'noise'], $rows->pluck('classification')->all());
        $this->assertSame([$team->id], $rows->pluck('team_id')->unique()->values()->all());
        $this->assertSame($before, $evaluation->fresh()->toArray());
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('ai.clef_shadow.completed', fn (array $c) => $c['input']['model'] === 'clef' && $c['calc']['matches_gpt'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retry_after_partial_failure_does_not_duplicate_or_repay(): void
    {
        Http::fake([
            '*/@cf/cloudflare/clef-flash' => Http::sequence()->push([], 503)->push($this->clefResponse('noise')),
            '*/@cf/cloudflare/clef' => Http::response($this->clefResponse('real_event')),
        ]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        try {
            ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);
            $this->fail('El 503 debe relanzarse para que la cola reintente');
        } catch (ClefRequestFailedException $e) {
            $this->assertSame('http_503', $e->reason);
        }
        $this->assertSame(['clef'], AIShadowEvaluation::withoutGlobalScopes()->pluck('model')->all());

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $this->assertSame(2, AIShadowEvaluation::withoutGlobalScopes()->count());
        Http::assertSentCount(3); // clef 1 vez, clef-flash 2 veces
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'already_evaluated');
    }

    public function test_non_retryable_failure_persists_failed_row(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([], 400)]);
        config(['ai.clef.models' => ['clef']]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        $row = AIShadowEvaluation::withoutGlobalScopes()->sole();
        $this->assertSame(AIShadowEvaluation::STATUS_FAILED, $row->status);
        $this->assertSame('http_400', $row->error_code);
        $this->assertSystemLogged('ai.clef_shadow.failed');
    }

    public function test_aborts_when_team_does_not_match(): void
    {
        Http::fake();
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        ShadowEvaluateWithClefJob::dispatchSync($other->id, $evaluation->id);

        Http::assertNothingSent();
        $this->assertSame(0, AIShadowEvaluation::withoutGlobalScopes()->count());
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'team_mismatch');
    }

    public function test_skips_when_snapshot_is_missing(): void
    {
        Http::fake();
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $evaluation->inferenceLogs()->delete();

        ShadowEvaluateWithClefJob::dispatchSync($team->id, $evaluation->id);

        Http::assertNothingSent();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'no_snapshot');
    }
}
```

> El modelo de eventos de uso puede llamarse distinto: confirmar con `grep -rn "class UsageEvent" app/Domains/Tenancy/Models` y ajustar el `use`.

`ShadowEvaluateWithClefJobTenantLeakTest.php`:

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Models\EventMediaContext;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ShadowEvaluateWithClefJobTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, BuildsClefFixtures, RefreshDatabase;

    public function test_job_never_reads_or_sends_another_tenants_media(): void
    {
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok', 'ai.clef.models' => ['clef'], 'ai.clef.send_images' => true]);
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);

        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();
        $evaluation = $this->makeEvaluation($attacker);

        // Media del otro tenant colgada (por error de datos) del mismo evento.
        EventMediaContext::factory()->create([
            'team_id' => $victim->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'retrieval_status' => MediaRetrievalStatus::Ready,
            'storage_path' => 'victim.jpg',
        ]);

        $this->assertNoTenantLeak($victim, fn () => ShadowEvaluateWithClefJob::dispatchSync($attacker->id, $evaluation->id));

        Http::assertSent(fn (Request $r): bool => ! array_key_exists('images', $r->data()));
        $this->assertSame([$attacker->id], AIShadowEvaluation::withoutGlobalScopes()->pluck('team_id')->all());
    }
}
```

- [ ] **Step 3: Run** `php artisan test --compact --filter='ClefEventDeciderTest|ShadowEvaluateWithClefJob'` → FAIL (clases inexistentes).

- [ ] **Step 4: `ClefDecision`**

```php
<?php

namespace App\Domains\AI\Data;

final readonly class ClefDecision
{
    /**
     * @param  array<string, float>  $classificationProbabilities
     * @param  array<string, array<string, mixed>>|null  $mediaAnswers  null cuando no se enviaron imágenes
     */
    public function __construct(
        public string $model,
        public string $classification,
        public array $classificationProbabilities,
        public float $riskScore,
        public float $needsHumanProbability,
        public ?array $mediaAnswers,
        public int $imagesSent,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public float $costEstimate,
    ) {}
}
```

- [ ] **Step 5: `ClefEventDecider`**

```php
<?php

namespace App\Infrastructure\AI\Clef;

use App\Domains\AI\Data\ClefDecision;

/**
 * Una evaluación de un evento con un modelo Clef: arma estado y preguntas,
 * llama y traduce las respuestas a una ClefDecision.
 */
class ClefEventDecider
{
    public function __construct(
        private readonly ClefClient $client,
        private readonly ClefStateBuilder $stateBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  AIInputContext::toArray() guardado en ai_inference_logs
     * @param  list<array{content_type: string, base64: string}>  $images
     */
    public function decide(string $model, array $snapshot, array $images): ClefDecision
    {
        $withImages = $images !== [];
        $questions = ClefQuestionSchema::for($withImages);

        $response = $this->client->run($model, $this->stateBuilder->fromSnapshot($snapshot), $questions, $images);
        $answers = $response->answers;

        $mediaAnswers = null;

        if ($withImages) {
            $mediaAnswers = array_intersect_key($answers, array_flip(['media_result', 'persons_visible', ...ClefQuestionSchema::MEDIA_SIGNALS]));
        }

        $pricePerMillion = (float) (config('ai.clef.pricing_per_million_input')[$model] ?? 0.0);

        return new ClefDecision(
            model: $model,
            classification: (string) $answers['classification']['choice'],
            classificationProbabilities: array_map('floatval', (array) $answers['classification']['probabilities']),
            riskScore: round(max(0.0, min(1.0, (float) $answers['severity']['score'] / (ClefQuestionSchema::SEVERITY_LEVELS - 1))), 2),
            needsHumanProbability: round((float) $answers['needs_human_now']['noul'], 3),
            mediaAnswers: $mediaAnswers,
            imagesSent: count($images),
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            latencyMs: $response->latencyMs,
            costEstimate: round($response->inputTokens * $pricePerMillion / 1_000_000, 5),
        );
    }
}
```

- [ ] **Step 6: `ShadowEvaluateWithClefJob`**

```php
<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Infrastructure\AI\Clef\ClefEventDecider;
use App\Infrastructure\AI\Clef\ClefImageLoader;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Evalúa en sombra, con cada modelo Clef configurado, lo mismo que evaluó
 * GPT. Nunca toca la evaluación oficial. Idempotente por
 * (evaluación, modelo, versión de schema): un reintento sólo llama a los
 * modelos que aún no tienen fila.
 */
class ShadowEvaluateWithClefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $teamId,
        public readonly int $evaluationId,
        public readonly string $source = AIShadowEvaluation::SOURCE_LIVE,
    ) {
        $this->onQueue('default');
    }

    public function handle(ClefEventDecider $decider, ClefImageLoader $images): void
    {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()->find($this->evaluationId);

        if ($evaluation === null || $evaluation->team_id !== $this->teamId) {
            SystemLog::skipped('ai.clef_shadow.skipped', reason: $evaluation === null ? 'evaluation_missing' : 'team_mismatch', input: ['evaluation_id' => $this->evaluationId, 'team_id' => $this->teamId]);

            return;
        }

        TenantContext::for($this->teamId, function () use ($evaluation, $decider, $images): void {
            $snapshot = AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->orderBy('id')->value('input_snapshot_json');
            $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

            if (! is_array($snapshot) || $snapshot === []) {
                SystemLog::skipped('ai.clef_shadow.skipped', reason: 'no_snapshot', input: ['evaluation_id' => $evaluation->id]);

                return;
            }

            $pending = $this->pendingModels($evaluation);

            if ($pending === []) {
                return;
            }

            $loaded = (bool) config('ai.clef.send_images', true)
                ? $images->forEvent($evaluation->id, $evaluation->normalized_event_id)['images']
                : [];

            $retryable = null;

            foreach ($pending as $model) {
                try {
                    $decision = $decider->decide($model, $snapshot, $loaded);
                } catch (ClefRequestFailedException $e) {
                    if ($e->retryable && $this->attempts() < $this->tries) {
                        SystemLog::degraded('ai.clef_shadow.failed', reason: $e->reason, input: ['evaluation_id' => $evaluation->id, 'model' => $model, 'attempt' => $this->attempts()], error: $e);
                        $retryable = $e;

                        continue;
                    }

                    $this->persistFailure($evaluation, $model, $e->reason, $e);

                    continue;
                } catch (Throwable $e) {
                    $this->persistFailure($evaluation, $model, class_basename($e), $e);

                    continue;
                }

                AIShadowEvaluation::create([
                    'team_id' => $evaluation->team_id,
                    'ai_event_evaluation_id' => $evaluation->id,
                    'normalized_event_id' => $evaluation->normalized_event_id,
                    'model' => $model,
                    'schema_version' => ClefQuestionSchema::VERSION,
                    'source' => $this->source,
                    'status' => AIShadowEvaluation::STATUS_SUCCESS,
                    'classification' => $decision->classification,
                    'classification_probabilities_json' => $decision->classificationProbabilities,
                    'risk_score' => $decision->riskScore,
                    'needs_human_probability' => $decision->needsHumanProbability,
                    'media_answers_json' => $decision->mediaAnswers,
                    'images_sent' => $decision->imagesSent,
                    'input_tokens' => $decision->inputTokens,
                    'output_tokens' => $decision->outputTokens,
                    'latency_ms' => $decision->latencyMs,
                    'cost_estimate' => $decision->costEstimate,
                ]);

                SystemLog::ok(
                    'ai.clef_shadow.completed',
                    input: ['evaluation_id' => $evaluation->id, 'model' => $model, 'source' => $this->source, 'schema_version' => ClefQuestionSchema::VERSION],
                    calc: [
                        'input_tokens' => $decision->inputTokens,
                        'price_per_million_input' => (float) (config('ai.clef.pricing_per_million_input')[$model] ?? 0.0),
                        'cost_estimate' => $decision->costEstimate,
                        'latency_ms' => $decision->latencyMs,
                        'images_sent' => $decision->imagesSent,
                        'gpt_classification' => $evaluation->classification?->value,
                        'matches_gpt' => $decision->classification === $evaluation->classification?->value,
                    ],
                    result: [
                        'classification' => $decision->classification,
                        'classification_probability' => $decision->classificationProbabilities[$decision->classification] ?? null,
                        'risk_score' => $decision->riskScore,
                    ],
                );
            }

            if ($retryable !== null) {
                throw $retryable;
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['evaluation_id' => $this->evaluationId]);
    }

    /**
     * @return list<string>
     */
    private function pendingModels(AIEventEvaluation $evaluation): array
    {
        $configured = array_values((array) config('ai.clef.models', []));
        $done = AIShadowEvaluation::query()
            ->where('ai_event_evaluation_id', $evaluation->id)
            ->where('schema_version', ClefQuestionSchema::VERSION)
            ->pluck('model')
            ->all();

        foreach (array_intersect($configured, $done) as $model) {
            SystemLog::skipped('ai.clef_shadow.skipped', reason: 'already_evaluated', input: ['evaluation_id' => $evaluation->id, 'model' => $model]);
        }

        return array_values(array_diff($configured, $done));
    }

    private function persistFailure(AIEventEvaluation $evaluation, string $model, string $errorCode, Throwable $e): void
    {
        AIShadowEvaluation::create([
            'team_id' => $evaluation->team_id,
            'ai_event_evaluation_id' => $evaluation->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'model' => $model,
            'schema_version' => ClefQuestionSchema::VERSION,
            'source' => $this->source,
            'status' => AIShadowEvaluation::STATUS_FAILED,
            'error_code' => substr($errorCode, 0, 64),
        ]);

        SystemLog::failed('ai.clef_shadow.failed', reason: $errorCode, input: ['evaluation_id' => $evaluation->id, 'model' => $model, 'attempt' => $this->attempts()], error: $e);
    }
}
```

> `dispatchSync` en tests: `attempts()` vale 1 y `tries` 3, así que un 503 se relanza (lo que el test de reintento espera). Si `JobFailureReporter::report` tiene otra firma, copiar la de `EvaluateEventMediaJob::failed`.

- [ ] **Step 7: Run** `php artisan test --compact --filter='ClefEventDeciderTest|ShadowEvaluateWithClefJob'` → PASS (8 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Domains/AI/Data/ClefDecision.php app/Infrastructure/AI/Clef/ClefEventDecider.php app/Domains/AI/Jobs/ShadowEvaluateWithClefJob.php tests/Feature/Domains/AI/Clef
git commit -m "feat: job de evaluación en sombra con clef idempotente por modelo"
```

---

### Task 6: `ClefShadowGate` + listener `DispatchClefShadowEvaluation`

**Files:**
- Create: `app/Domains/AI/Support/ClefShadowGate.php`, `app/Domains/AI/Listeners/DispatchClefShadowEvaluation.php`
- Modify: `app/Domains/AI/AIServiceProvider.php` (registrar el listener junto a los demás `AIEvaluationCompleted`)
- Test: `tests/Feature/Domains/AI/Clef/DispatchClefShadowEvaluationTest.php`

**Interfaces:**
- Produces:
  - `ClefShadowGate::closedReason(bool $requireWindow = true): ?string`. Devuelve `null` si está abierto; si no, `disabled` | `shadow_expired` | `missing_credentials`. Con `$requireWindow=false` sólo revisa credenciales (lo usa el backfill).
  - Listener `DispatchClefShadowEvaluation::handle(AIEvaluationCompleted $event): void`.

- [ ] **Step 1: Tests que fallan**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class DispatchClefShadowEvaluationTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ShadowEvaluateWithClefJob::class]);
        config([
            'ai.clef.enabled' => true,
            'ai.clef.shadow_until' => now()->addWeek()->toDateString(),
            'ai.clef.sample_rate' => 1.0,
            'services.cloudflare.account_id' => 'acc',
            'services.cloudflare.auth_token' => 'tok',
        ]);
    }

    public function test_dispatches_for_ai_text_and_hybrid_evaluations(): void
    {
        $team = Team::factory()->create();

        foreach ([EvaluationMode::AiText, EvaluationMode::Hybrid] as $mode) {
            AIEvaluationCompleted::dispatch($this->makeEvaluation($team, evaluation: ['evaluation_mode' => $mode]));
        }

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 2);
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, fn (ShadowEvaluateWithClefJob $job) => $job->teamId === $team->id && $job->source === 'live');
        $this->assertSystemLogged('ai.clef_shadow.dispatched');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function closedGates(): array
    {
        return [
            'apagado' => [['ai.clef.enabled' => false], 'disabled'],
            'ventana vencida' => [['ai.clef.shadow_until' => '2026-01-01'], 'shadow_expired'],
            'ventana vacía' => [['ai.clef.shadow_until' => null], 'shadow_expired'],
            'sin credenciales' => [['services.cloudflare.auth_token' => null], 'missing_credentials'],
            'fuera de muestra' => [['ai.clef.sample_rate' => 0.0], 'not_sampled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('closedGates')]
    public function test_each_gate_skips_with_its_reason(array $config, string $reason): void
    {
        config($config);

        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create()));

        Queue::assertNothingPushed();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === $reason);
    }

    public function test_rules_only_evaluations_are_not_shadowed(): void
    {
        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create(), evaluation: ['evaluation_mode' => EvaluationMode::RulesOnly]));

        Queue::assertNothingPushed();
        $this->assertSystemLogged('ai.clef_shadow.skipped', fn (array $c) => $c['reason'] === 'rules_only_mode');
    }

    public function test_window_includes_its_last_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(23, 30));
        config(['ai.clef.shadow_until' => '2026-10-20']);

        AIEvaluationCompleted::dispatch($this->makeEvaluation(Team::factory()->create()));

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact --filter=DispatchClefShadowEvaluationTest` → FAIL.

- [ ] **Step 3: Gate**

```php
<?php

namespace App\Domains\AI\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Condiciones globales para correr la medición en sombra con Clef. La
 * ventana (`ai.clef.shadow_until`, inclusiva hasta el fin de ese día) hace
 * que la sombra se apague sola: vacía o vencida = cerrada.
 */
class ClefShadowGate
{
    public function closedReason(bool $requireWindow = true): ?string
    {
        if ($requireWindow && ! (bool) config('ai.clef.enabled', false)) {
            return 'disabled';
        }

        if ($requireWindow && ! $this->windowOpen()) {
            return 'shadow_expired';
        }

        if (blank(config('services.cloudflare.account_id')) || blank(config('services.cloudflare.auth_token'))) {
            return 'missing_credentials';
        }

        return null;
    }

    private function windowOpen(): bool
    {
        $until = config('ai.clef.shadow_until');

        if (blank($until)) {
            return false;
        }

        try {
            return now()->lessThanOrEqualTo(Carbon::parse((string) $until)->endOfDay());
        } catch (Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Listener**

```php
<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Support\ClefShadowGate;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Despacha la medición en sombra con Clef de una evaluación oficial hecha
 * por GPT. Después del commit: `AIEvaluationCompleted` se emite dentro de la
 * transacción de la evaluación.
 */
class DispatchClefShadowEvaluation
{
    public function __construct(private readonly ClefShadowGate $gate) {}

    public function handle(AIEvaluationCompleted $event): void
    {
        $evaluation = $event->evaluation;
        $input = ['evaluation_id' => $evaluation->id];

        $reason = $this->gate->closedReason();

        if ($reason === null && ! in_array($evaluation->evaluation_mode, [EvaluationMode::AiText, EvaluationMode::Hybrid], true)) {
            $reason = 'rules_only_mode';
        }

        $rate = max(0.0, min(1.0, (float) config('ai.clef.sample_rate', 1.0)));
        $roll = mt_rand() / mt_getrandmax();

        if ($reason === null && $roll >= $rate && $rate < 1.0) {
            $reason = 'not_sampled';
        }

        if ($reason !== null) {
            SystemLog::skipped('ai.clef_shadow.skipped', reason: $reason, input: $input, calc: $reason === 'not_sampled' ? ['sample_rate' => $rate, 'roll' => round($roll, 4)] : null, debug: $reason === 'disabled');

            return;
        }

        $teamId = $evaluation->team_id;
        $evaluationId = $evaluation->id;

        DB::afterCommit(function () use ($teamId, $evaluationId, $input): void {
            ShadowEvaluateWithClefJob::dispatch($teamId, $evaluationId);
            SystemLog::ok('ai.clef_shadow.dispatched', input: $input, result: ['models' => array_values((array) config('ai.clef.models', []))]);
        });
    }
}
```

> Con `sample_rate = 0.0`, `$roll >= 0.0` siempre es cierto, así que se salta. Con `1.0` nunca se muestrea fuera.

En `AIServiceProvider::boot()`, junto a las otras líneas de `AIEvaluationCompleted`:

```php
        Event::listen(AIEvaluationCompleted::class, DispatchClefShadowEvaluation::class);
```

(con su `use App\Domains\AI\Listeners\DispatchClefShadowEvaluation;`).

- [ ] **Step 5: Run** el test → PASS (8 casos). Correr también `php artisan test --compact tests/Feature/Domains/AI` para confirmar que nada existente cambió (con `enabled=false` por defecto, el listener sólo registra un `skipped` en debug).

- [ ] **Step 6: Commit**

```bash
git add app/Domains/AI/Support/ClefShadowGate.php app/Domains/AI/Listeners/DispatchClefShadowEvaluation.php app/Domains/AI/AIServiceProvider.php tests/Feature/Domains/AI/Clef/DispatchClefShadowEvaluationTest.php
git commit -m "feat: despacho de la sombra de clef con ventana que se apaga sola"
```

---

### Task 7: `ai:clef-backfill`

**Files:**
- Create: `app/Domains/AI/Commands/ClefBackfillCommand.php`
- Modify: `app/Domains/AI/AIServiceProvider.php` (`$this->commands([...])` dentro de `runningInConsole()`, como `AssetsServiceProvider`)
- Test: `tests/Feature/Domains/AI/Clef/ClefBackfillCommandTest.php`

**Interfaces:**
- Consumes: `ClefShadowGate::closedReason(false)`, `ShadowEvaluateWithClefJob`, `AIEventEvaluation::shadowEvaluations()`.
- Produces: comando `ai:clef-backfill {--team=} {--since=} {--limit=500} {--sync} {--force}`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefBackfillCommandTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ShadowEvaluateWithClefJob::class]);
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok', 'ai.clef.models' => ['clef', 'clef-flash']]);
    }

    public function test_dispatches_pending_evaluations_verdicted_first_and_reports_cost(): void
    {
        $team = Team::factory()->create();
        $plain = $this->makeEvaluation($team);
        $verdicted = $this->makeEvaluation($team, evaluation: ['operator_verdict' => OperatorVerdict::Confirmed]);
        $this->makeEvaluation($team, evaluation: ['evaluation_mode' => EvaluationMode::RulesOnly]);
        $done = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $done->id, 'model' => 'clef']);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $done->id, 'model' => 'clef-flash']);

        $this->artisan('ai:clef-backfill', ['--force' => true])
            ->expectsOutputToContain('2 evaluaciones')
            ->assertSuccessful();

        $pushed = [];
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, function (ShadowEvaluateWithClefJob $job) use (&$pushed) {
            $pushed[] = $job->evaluationId;

            return $job->source === 'backfill';
        });
        $this->assertSame([$verdicted->id, $plain->id], $pushed);
        // 2 evaluaciones × 2800 tokens × (0.24 + 0.09) / 1M
        $this->assertSystemLogged('ai.clef_backfill.planned', fn (array $c) => $c['calc']['evaluations'] === 2 && abs($c['calc']['estimated_cost'] - 0.00185) < 0.00001);
    }

    public function test_asks_for_confirmation_without_force(): void
    {
        $this->makeEvaluation(Team::factory()->create());

        $this->artisan('ai:clef-backfill')
            ->expectsConfirmation('¿Lanzar el backfill?', 'no')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_refuses_without_credentials(): void
    {
        config(['services.cloudflare.auth_token' => null]);

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_team_option_only_touches_that_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $this->makeEvaluation($teamA);
        $this->makeEvaluation($teamB);

        $this->artisan('ai:clef-backfill', ['--team' => $teamA->id, '--force' => true])->assertSuccessful();

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, fn (ShadowEvaluateWithClefJob $job) => $job->teamId === $teamA->id);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact --filter=ClefBackfillCommandTest` → FAIL (comando inexistente).

- [ ] **Step 3: Implementación**

```php
<?php

namespace App\Domains\AI\Commands;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\AI\Support\ClefShadowGate;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ClefBackfillCommand extends Command
{
    protected $signature = 'ai:clef-backfill
        {--team= : Sólo este team id}
        {--since= : Sólo evaluaciones desde esta fecha (Y-m-d)}
        {--limit=500 : Máximo de evaluaciones}
        {--sync : Ejecutar en este proceso en lugar de encolar}
        {--force : No pedir confirmación}';

    protected $description = 'Evalúa con Clef, en sombra, evaluaciones pasadas de GPT (las que tienen veredicto primero)';

    public function handle(ClefShadowGate $gate): int
    {
        if (($reason = $gate->closedReason(requireWindow: false)) !== null) {
            $this->error('No se puede lanzar: '.$reason);

            return self::FAILURE;
        }

        $models = array_values((array) config('ai.clef.models', []));
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;

        $evaluations = TenantContext::withoutTenant(fn () => AIEventEvaluation::withoutGlobalScopes()
            ->when($team !== null, fn (Builder $q) => $q->where('team_id', $team))
            ->when($this->option('since') !== null, fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $this->option('since'))->startOfDay()))
            ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
            ->whereHas('inferenceLogs')
            ->where(function (Builder $q) use ($models) {
                foreach ($models as $model) {
                    $q->orWhereDoesntHave('shadowEvaluations', fn (Builder $s) => $s->withoutGlobalScopes()->where('model', $model)->where('schema_version', ClefQuestionSchema::VERSION));
                }
            })
            ->orderByRaw('operator_verdict is null')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'team_id']));

        $tokens = (int) AIInferenceLog::query()->whereIn('evaluation_id', $evaluations->pluck('id'))->sum('input_tokens');
        $prices = (array) config('ai.clef.pricing_per_million_input', []);
        $pricePerMillion = array_sum(array_map(fn (string $m): float => (float) ($prices[$m] ?? 0.0), $models));
        $cost = round($tokens * $pricePerMillion / 1_000_000, 5);

        $this->info(sprintf('%d evaluaciones · %d tokens de entrada · modelos %s · costo estimado US$%.4f', $evaluations->count(), $tokens, implode(', ', $models), $cost));

        SystemLog::ok('ai.clef_backfill.planned', input: ['team_id' => $team, 'since' => $this->option('since'), 'limit' => (int) $this->option('limit')], calc: [
            'evaluations' => $evaluations->count(),
            'input_tokens' => $tokens,
            'models' => $models,
            'price_per_million_sum' => $pricePerMillion,
            'estimated_cost' => $cost,
        ]);

        if ($evaluations->isEmpty() || (! $this->option('force') && ! $this->confirm('¿Lanzar el backfill?'))) {
            return self::SUCCESS;
        }

        foreach ($evaluations as $evaluation) {
            $job = new ShadowEvaluateWithClefJob($evaluation->team_id, $evaluation->id, AIShadowEvaluation::SOURCE_BACKFILL);
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }

        return self::SUCCESS;
    }
}
```

> `orderByRaw('operator_verdict is null')` funciona en SQLite y PostgreSQL (false ordena antes que true). Si `inferenceLogs` usa otra clave foránea, `whereHas('inferenceLogs')` ya la respeta.

En `AIServiceProvider::boot()`:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClefBackfillCommand::class,
            ]);
        }
```

(Las Tasks 8 y 9 agregan sus comandos a este mismo array.)

- [ ] **Step 4: Run** → PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domains/AI/Commands/ClefBackfillCommand.php app/Domains/AI/AIServiceProvider.php tests/Feature/Domains/AI/Clef/ClefBackfillCommandTest.php
git commit -m "feat: backfill de evaluaciones pasadas con clef y costo estimado"
```

---

### Task 8: `ai:label-events` (base de verdad a ciegas)

**Files:**
- Create: `app/Domains/AI/Commands/LabelEventsCommand.php`
- Modify: `app/Domains/AI/AIServiceProvider.php` (agregar al array de comandos)
- Test: `tests/Feature/Domains/AI/Clef/LabelEventsCommandTest.php`

**Interfaces:**
- Consumes: `RecordOperatorVerdict::execute(int $teamId, int $normalizedEventId, OperatorVerdict $verdict, ?int $userId, ?string $note)`, `App\Support\TeamMembers::isMember(int $teamId, int $userId)`, `ObjectStorage::temporaryUrl`, `ClefStateBuilder`.
- Produces: comando `ai:label-events {--team=} {--user=} {--limit=100} {--since=}`; nota de veredicto `etiquetado:baseline`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class LabelEventsCommandTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, BuildsClefFixtures, RefreshDatabase;

    private const array CHOICES = ['r' => 'real', 'f' => 'falso positivo', 's' => 'saltar', 'q' => 'salir'];

    private function member(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);

        return $user;
    }

    public function test_records_blind_verdicts_through_record_operator_verdict(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        $real = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent]);
        $noise = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email, '--limit' => 2])
            ->doesntExpectOutputToContain('real_event')
            ->doesntExpectOutputToContain('noise')
            ->expectsChoice('Veredicto', 'r', self::CHOICES)
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->assertSuccessful();

        $verdicts = AIEventEvaluation::withoutGlobalScopes()->whereIn('id', [$real->id, $noise->id])->pluck('operator_verdict')->filter();
        $this->assertCount(2, $verdicts);
        $this->assertEqualsCanonicalizing([OperatorVerdict::Confirmed, OperatorVerdict::FalsePositive], $verdicts->all());
        $this->assertSame(['etiquetado:baseline'], AIEventEvaluation::withoutGlobalScopes()->pluck('operator_verdict_note')->unique()->values()->all());
        $this->assertSystemLogged('ai.label.recorded');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_skip_and_quit_leave_events_pending_for_next_run(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        $this->makeEvaluation($team);
        $this->makeEvaluation($team);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email])
            ->expectsChoice('Veredicto', 's', self::CHOICES)
            ->expectsChoice('Veredicto', 'q', self::CHOICES)
            ->assertSuccessful();

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->whereNotNull('operator_verdict')->count());
    }

    public function test_stratifies_by_gpt_classification(): void
    {
        $team = Team::factory()->create();
        $user = $this->member($team);
        foreach (range(1, 5) as $i) {
            $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent]);
        }
        $noise = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email, '--limit' => 2])
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->expectsChoice('Veredicto', 'f', self::CHOICES)
            ->assertSuccessful();

        $this->assertNotNull(AIEventEvaluation::withoutGlobalScopes()->find($noise->id)->operator_verdict);
    }

    public function test_rejects_user_outside_the_team_and_never_touches_other_tenants(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $outsider = $this->member($other);
        $this->makeEvaluation($other);

        $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $outsider->email])->assertFailed();

        $user = $this->member($team);
        $this->assertNoTenantLeak($other, fn () => $this->artisan('ai:label-events', ['--team' => $team->id, '--user' => $user->email])->assertSuccessful());
    }
}
```

> Confirmar cómo se agrega un miembro a un team en otros tests (`grep -rn "members()->attach" tests | head -3`) y copiar esa forma si difiere.

- [ ] **Step 2: Run** `php artisan test --compact --filter=LabelEventsCommandTest` → FAIL.

- [ ] **Step 3: Implementación**

```php
<?php

namespace App\Domains\AI\Commands;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Actions\RecordOperatorVerdict;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Infrastructure\AI\Clef\ClefStateBuilder;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TeamMembers;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Etiquetado humano a ciegas para la base de verdad de la medición Clef vs
 * GPT. Nunca muestra lo que opinó ningún modelo. Graba por el mismo camino
 * que el operador en la UI (RecordOperatorVerdict), con auditoría.
 */
class LabelEventsCommand extends Command
{
    public const string NOTE = 'etiquetado:baseline';

    private const array CHOICES = ['r' => 'real', 'f' => 'falso positivo', 's' => 'saltar', 'q' => 'salir'];

    protected $signature = 'ai:label-events
        {--team= : Team id (obligatorio)}
        {--user= : Email o id del usuario que etiqueta (obligatorio, miembro del team)}
        {--limit=100 : Máximo de eventos en esta sesión}
        {--since= : Sólo eventos evaluados desde esta fecha (Y-m-d)}';

    protected $description = 'Etiqueta eventos a ciegas (real / falso positivo) para medir a GPT y Clef';

    public function handle(RecordOperatorVerdict $record, ObjectStorage $storage, ClefStateBuilder $stateBuilder): int
    {
        $teamId = (int) $this->option('team');
        $userOption = (string) $this->option('user');
        $user = ctype_digit($userOption) ? User::find((int) $userOption) : User::findByEmail($userOption);

        if ($teamId <= 0 || $user === null || ! TeamMembers::isMember($teamId, $user->id)) {
            $this->error('--team y --user son obligatorios y el usuario debe ser miembro del team.');

            return self::FAILURE;
        }

        return TenantContext::for($teamId, function () use ($teamId, $user, $record, $storage, $stateBuilder): int {
            $sample = $this->stratifiedSample(max(1, (int) $this->option('limit')));

            if ($sample->isEmpty()) {
                $this->info('No hay eventos pendientes de etiquetar.');

                return self::SUCCESS;
            }

            foreach ($sample as $index => $evaluation) {
                $this->newLine();
                $this->line(sprintf('<options=bold>Evento %d de %d</>', $index + 1, $sample->count()));
                $this->renderEvent($evaluation, $storage, $stateBuilder);

                $answer = $this->choice('Veredicto', self::CHOICES);

                if ($answer === 'q') {
                    break;
                }

                if ($answer === 's') {
                    continue;
                }

                $verdict = $answer === 'r' ? OperatorVerdict::Confirmed : OperatorVerdict::FalsePositive;
                $record->execute($teamId, $evaluation->normalized_event_id, $verdict, $user->id, self::NOTE);

                SystemLog::ok('ai.label.recorded', input: ['evaluation_id' => $evaluation->id, 'normalized_event_id' => $evaluation->normalized_event_id, 'user_id' => $user->id], result: ['verdict' => $verdict->value]);
            }

            return self::SUCCESS;
        });
    }

    /**
     * Última versión de cada evento, sin veredicto, repartida en round-robin
     * por (clasificación de GPT, tipo de evento) para no etiquetar sólo pánicos.
     *
     * @return Collection<int, AIEventEvaluation>
     */
    private function stratifiedSample(int $limit): Collection
    {
        $candidates = AIEventEvaluation::query()
            ->with('normalizedEvent:id,event_type_id')
            ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
            ->whereNull('operator_verdict')
            ->when($this->option('since') !== null, fn ($q) => $q->where('created_at', '>=', Carbon::parse((string) $this->option('since'))->startOfDay()))
            ->orderByDesc('evaluation_version')
            ->orderByDesc('id')
            ->get()
            ->unique('normalized_event_id');

        $groups = $candidates
            ->groupBy(fn (AIEventEvaluation $e): string => ($e->classification?->value ?? 'none').'|'.($e->normalizedEvent?->event_type_id ?? 0))
            ->map(fn (Collection $group) => $group->values())
            ->values();

        $picked = collect();

        for ($round = 0; $picked->count() < $limit && $groups->contains(fn (Collection $g) => $g->has($round)); $round++) {
            foreach ($groups as $group) {
                if ($picked->count() < $limit && $group->has($round)) {
                    $picked->push($group[$round]);
                }
            }
        }

        return $picked->values();
    }

    private function renderEvent(AIEventEvaluation $evaluation, ObjectStorage $storage, ClefStateBuilder $stateBuilder): void
    {
        $snapshot = AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->orderBy('id')->value('input_snapshot_json');
        $snapshot = $stateBuilder->fromSnapshot(is_array($snapshot) ? $snapshot : (array) json_decode((string) $snapshot, true));

        $rows = [];
        foreach (['normalized_event', 'asset', 'driver', 'location', 'telemetry', 'context_signals', 'recent_history', 'incidents'] as $key) {
            if (! empty($snapshot[$key])) {
                $rows[] = [$key, json_encode($snapshot[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
            }
        }
        $this->table(['Dato', 'Valor'], $rows);

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $evaluation->normalized_event_id)
            ->whereIn('media_type', [MediaType::Image, MediaType::Snapshot])
            ->where('retrieval_status', MediaRetrievalStatus::Ready)
            ->whereNotNull('storage_path')
            ->limit(4)
            ->get();

        foreach ($media as $item) {
            $this->line('Imagen (15 min): '.$storage->temporaryUrl((string) $item->storage_path, now()->addMinutes(15)));
        }
    }
}
```

> El snapshot no contiene la clasificación de GPT (es la entrada, no la salida) y `ClefStateBuilder` quita `media_assessments` y `operator_feedback`: así se garantiza el "a ciegas" que verifica el test. Agregar `LabelEventsCommand::class` al array de comandos del `AIServiceProvider`.

- [ ] **Step 4: Run** → PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domains/AI/Commands/LabelEventsCommand.php app/Domains/AI/AIServiceProvider.php tests/Feature/Domains/AI/Clef/LabelEventsCommandTest.php
git commit -m "feat: etiquetado a ciegas de eventos para la base de verdad"
```

---

### Task 9: `ClefShadowComparisonQuery` + `ai:clef-report`

**Files:**
- Create: `app/Domains/AI/Queries/ClefShadowComparisonQuery.php`, `app/Domains/AI/Commands/ClefReportCommand.php`
- Modify: `app/Domains/AI/AIServiceProvider.php` (array de comandos)
- Test: `tests/Feature/Domains/AI/Clef/ClefShadowComparisonQueryTest.php`, `ClefReportCommandTest.php`

**Interfaces:**
- Produces:
  - `ClefShadowComparisonQuery::execute(?int $teamId, \DateTimeInterface $since, bool $byEventType = false): array<string, array<string, array<string, float|int|null>>>`. Claves de primer nivel: `'all'` o el código de tipo de evento. Debajo, una por modelo (`gpt`, `clef`, `clef-flash`) con las métricas: `n`, `agree_with_gpt`, `verdict_n`, `real_n`, `recall_real`, `fp_n`, `discard_correct`, `strict_accuracy`, `brier`, `cost_total`, `cost_avg`, `latency_p50`, `latency_p95`, `failed`.
  - Constante `ClefShadowComparisonQuery::MIN_SAMPLE = 30`.

- [ ] **Step 1: Test de la query** (incluye Review Focus 4)

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\AI\Queries\ClefShadowComparisonQuery;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefShadowComparisonQueryTest extends TestCase
{
    use BuildsClefFixtures, RefreshDatabase;

    private function shadow(AIEventEvaluation $evaluation, string $classification, float $pReal, string $model = 'clef', int $latency = 200): void
    {
        AIShadowEvaluation::factory()->create([
            'ai_event_evaluation_id' => $evaluation->id,
            'model' => $model,
            'classification' => $classification,
            'classification_probabilities_json' => ['real_event' => $pReal, $classification => $classification === 'real_event' ? $pReal : 1 - $pReal],
            'latency_ms' => $latency,
            'cost_estimate' => 0.001,
        ]);
    }

    public function test_computes_safety_savings_accuracy_and_calibration(): void
    {
        $team = Team::factory()->create();

        // Real confirmado: GPT acierta, Clef también (p=0.9).
        $a = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::Confirmed]);
        $this->shadow($a, 'real_event', 0.9);
        // Real confirmado: GPT dice unclear (cuenta para recall), Clef dice noise (se le escapa).
        $b = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Unclear, 'operator_verdict' => OperatorVerdict::Confirmed]);
        $this->shadow($b, 'noise', 0.2);
        // Falso positivo confirmado: GPT dice real, Clef descarta.
        $c = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::RealEvent, 'operator_verdict' => OperatorVerdict::FalsePositive]);
        $this->shadow($c, 'false_positive', 0.1);
        // Sin veredicto: sólo cuenta para concordancia.
        $d = $this->makeEvaluation($team, evaluation: ['classification' => EventClassification::Noise]);
        $this->shadow($d, 'noise', 0.05);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(4, $all['clef']['n']);
        $this->assertSame(0.5, $all['clef']['agree_with_gpt']);         // a=sí, b=no, c=no, d=sí
        $this->assertSame(1.0, $all['gpt']['recall_real']);              // real o unclear en a y b
        $this->assertSame(0.5, $all['clef']['recall_real']);             // se le escapó b
        $this->assertSame(0.0, $all['gpt']['discard_correct']);
        $this->assertSame(1.0, $all['clef']['discard_correct']);
        $this->assertSame(round(((0.9 - 1) ** 2 + (0.2 - 1) ** 2 + (0.1 - 0) ** 2) / 3, 4), $all['clef']['brier']);
        $this->assertNull($all['gpt']['brier']);
    }

    public function test_reevaluated_event_counts_once_using_latest_version(): void
    {
        $team = Team::factory()->create();
        $v1 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 1, 'classification' => EventClassification::Unclear]);
        $v2 = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 2, 'classification' => EventClassification::RealEvent]);
        $v2->forceFill(['normalized_event_id' => $v1->normalized_event_id])->save();
        $this->shadow($v1, 'unclear', 0.4);
        $this->shadow($v2, 'real_event', 0.9);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(1, $all['clef']['n']);
        $this->assertSame(1.0, $all['clef']['agree_with_gpt']);
    }

    public function test_failed_rows_are_counted_apart_and_other_teams_excluded(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $mine = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->failed()->create(['ai_event_evaluation_id' => $mine->id]);
        $this->shadow($this->makeEvaluation($other), 'noise', 0.1);

        $all = app(ClefShadowComparisonQuery::class)->execute($team->id, now()->subDay())['all'];

        $this->assertSame(0, $all['clef']['n']);
        $this->assertSame(1, $all['clef']['failed']);
    }
}
```

- [ ] **Step 2: Test del comando**

```php
<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefReportCommandTest extends TestCase
{
    use BuildsClefFixtures, RefreshDatabase;

    public function test_prints_a_row_per_model_and_flags_small_samples(): void
    {
        $team = Team::factory()->create();
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $this->makeEvaluation($team)->id]);

        $this->artisan('ai:clef-report', ['--team' => $team->id, '--days' => 7])
            ->expectsOutputToContain('gpt')
            ->expectsOutputToContain('clef')
            ->expectsOutputToContain('muestra insuficiente')
            ->assertSuccessful();
    }
}
```

- [ ] **Step 3: Run** `php artisan test --compact --filter='ClefShadowComparisonQueryTest|ClefReportCommandTest'` → FAIL.

- [ ] **Step 4: Query**

```php
<?php

namespace App\Domains\AI\Queries;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Métricas de la medición Clef vs GPT. Una fila por evento (última versión
 * de su evaluación) para no contar dos veces las reevaluaciones.
 *
 * - recall_real (seguridad): de los `confirmed`, % que el modelo dejó accionable (real_event/unclear).
 * - discard_correct (ahorro): de los `false_positive`, % que el modelo descartó.
 * - strict_accuracy: OperatorVerdict::agreesWith().
 * - brier: calibración de P(real_event) contra el veredicto (sólo Clef).
 */
class ClefShadowComparisonQuery
{
    public const int MIN_SAMPLE = 30;

    /**
     * @return array<string, array<string, array<string, float|int|null>>>
     */
    public function execute(?int $teamId, DateTimeInterface $since, bool $byEventType = false): array
    {
        $load = function () use ($teamId, $since): Collection {
            $evaluations = AIEventEvaluation::withoutGlobalScopes()
                ->with('normalizedEvent.eventType')
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
                ->where('created_at', '>=', $since)
                ->orderByDesc('evaluation_version')
                ->orderByDesc('id')
                ->get()
                ->unique('normalized_event_id')
                ->values();

            $shadows = AIShadowEvaluation::withoutGlobalScopes()
                ->whereIn('ai_event_evaluation_id', $evaluations->pluck('id'))
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->where('schema_version', ClefQuestionSchema::VERSION)
                ->get()
                ->groupBy('ai_event_evaluation_id');

            $logs = AIInferenceLog::query()
                ->whereIn('evaluation_id', $evaluations->pluck('id'))
                ->get(['evaluation_id', 'latency_ms', 'cost_estimate'])
                ->keyBy('evaluation_id');

            return $evaluations->map(fn (AIEventEvaluation $e) => [
                'evaluation' => $e,
                'type' => $e->normalizedEvent?->eventType?->code ?? 'desconocido',
                'gpt_log' => $logs->get($e->id),
                'shadows' => $shadows->get($e->id, collect()),
            ]);
        };

        $rows = $teamId !== null ? TenantContext::for($teamId, $load) : TenantContext::withoutTenant($load);

        $buckets = ['all' => $rows];

        if ($byEventType) {
            foreach ($rows->groupBy('type') as $type => $group) {
                $buckets[(string) $type] = $group;
            }
        }

        $models = array_values((array) config('ai.clef.models', ['clef', 'clef-flash']));

        return array_map(fn (Collection $bucket) => [
            'gpt' => $this->metricsForGpt($bucket),
            ...array_combine($models, array_map(fn (string $m) => $this->metricsForModel($bucket, $m), $models)),
        ], $buckets);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|null>
     */
    private function metricsForGpt(Collection $rows): array
    {
        $items = $rows->map(fn (array $r) => [
            'classification' => $r['evaluation']->classification,
            'verdict' => $r['evaluation']->operator_verdict,
            'gpt' => $r['evaluation']->classification,
            'p_real' => null,
            'latency' => $r['gpt_log']?->latency_ms,
            'cost' => $r['gpt_log']?->cost_estimate !== null ? (float) $r['gpt_log']->cost_estimate : null,
        ]);

        return $this->metrics($items, failed: 0);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|null>
     */
    private function metricsForModel(Collection $rows, string $model): array
    {
        $failed = 0;
        $items = collect();

        foreach ($rows as $r) {
            $shadow = $r['shadows']->firstWhere('model', $model);

            if ($shadow === null) {
                continue;
            }

            if ($shadow->status !== AIShadowEvaluation::STATUS_SUCCESS) {
                $failed++;

                continue;
            }

            $items->push([
                'classification' => EventClassification::tryFrom((string) $shadow->classification),
                'verdict' => $r['evaluation']->operator_verdict,
                'gpt' => $r['evaluation']->classification,
                'p_real' => (float) ($shadow->classification_probabilities_json['real_event'] ?? 0.0),
                'latency' => $shadow->latency_ms,
                'cost' => $shadow->cost_estimate,
            ]);
        }

        return $this->metrics($items, $failed);
    }

    /**
     * @param  Collection<int, array{classification: ?EventClassification, verdict: ?OperatorVerdict, gpt: ?EventClassification, p_real: ?float, latency: ?int, cost: ?float}>  $items
     * @return array<string, float|int|null>
     */
    private function metrics(Collection $items, int $failed): array
    {
        $ratio = fn (int $hits, int $n): ?float => $n > 0 ? round($hits / $n, 4) : null;
        $verdicted = $items->filter(fn ($i) => $i['verdict'] !== null);
        $real = $verdicted->filter(fn ($i) => $i['verdict'] === OperatorVerdict::Confirmed);
        $fp = $verdicted->filter(fn ($i) => $i['verdict'] === OperatorVerdict::FalsePositive);
        $calibrated = $verdicted->filter(fn ($i) => $i['p_real'] !== null);
        $latencies = $items->pluck('latency')->filter(fn ($v) => $v !== null)->sort()->values();
        $costs = $items->pluck('cost')->filter(fn ($v) => $v !== null);

        return [
            'n' => $items->count(),
            'agree_with_gpt' => $ratio($items->filter(fn ($i) => $i['classification'] === $i['gpt'])->count(), $items->count()),
            'verdict_n' => $verdicted->count(),
            'real_n' => $real->count(),
            'recall_real' => $ratio($real->filter(fn ($i) => $i['classification']?->isActionable() === true)->count(), $real->count()),
            'fp_n' => $fp->count(),
            'discard_correct' => $ratio($fp->filter(fn ($i) => in_array($i['classification'], [EventClassification::FalsePositive, EventClassification::Noise, EventClassification::Duplicate], true))->count(), $fp->count()),
            'strict_accuracy' => $ratio($verdicted->filter(fn ($i) => $i['verdict']->agreesWith($i['classification']))->count(), $verdicted->count()),
            'brier' => $calibrated->isEmpty() ? null : round($calibrated->avg(fn ($i) => ($i['p_real'] - ($i['verdict'] === OperatorVerdict::Confirmed ? 1 : 0)) ** 2), 4),
            'cost_total' => round((float) $costs->sum(), 5),
            'cost_avg' => $costs->isEmpty() ? null : round((float) $costs->avg(), 5),
            'latency_p50' => $this->percentile($latencies, 0.5),
            'latency_p95' => $this->percentile($latencies, 0.95),
            'failed' => $failed,
        ];
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    private function percentile(Collection $sorted, float $p): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        return (int) $sorted[(int) min($sorted->count() - 1, (int) ceil($p * $sorted->count()) - 1)];
    }
}
```

> Confirmar que `EventType` tiene columna `code` (`grep -n "'code'" app/Domains/Normalization/Models/EventType.php`); si se llama distinto, usar esa.

- [ ] **Step 5: Comando**

```php
<?php

namespace App\Domains\AI\Commands;

use App\Domains\AI\Queries\ClefShadowComparisonQuery;
use Illuminate\Console\Command;

class ClefReportCommand extends Command
{
    protected $signature = 'ai:clef-report
        {--team= : Sólo este team id}
        {--days=30 : Ventana en días}
        {--by-type : Desglosar por tipo de evento}';

    protected $description = 'Compara GPT, Clef y Clef-flash contra GPT y contra el veredicto humano';

    public function handle(ClefShadowComparisonQuery $query): int
    {
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;
        $report = $query->execute($team, now()->subDays(max(1, (int) $this->option('days'))), (bool) $this->option('by-type'));
        $pct = fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1).' %';

        foreach ($report as $bucket => $models) {
            $this->newLine();
            $this->line('<options=bold>'.($bucket === 'all' ? 'Todos los eventos' : 'Tipo: '.$bucket).'</>');

            $this->table(
                ['Modelo', 'n', 'Concuerda con GPT', 'Recall reales (n)', 'Descarte FP (n)', 'Acierto', 'Brier', 'Costo total', 'p50 ms', 'p95 ms', 'Fallidas'],
                collect($models)->map(fn (array $m, string $model) => [
                    $model,
                    $m['n'],
                    $pct($m['agree_with_gpt']),
                    $pct($m['recall_real']).' ('.$m['real_n'].')',
                    $pct($m['discard_correct']).' ('.$m['fp_n'].')',
                    $pct($m['strict_accuracy']),
                    $m['brier'] ?? '—',
                    'US$'.number_format((float) $m['cost_total'], 4),
                    $m['latency_p50'] ?? '—',
                    $m['latency_p95'] ?? '—',
                    $m['failed'],
                ])->values()->all(),
            );

            $minVerdicts = min(array_map(fn (array $m) => (int) $m['verdict_n'], $models));

            if ($minVerdicts < ClefShadowComparisonQuery::MIN_SAMPLE) {
                $this->warn(sprintf('Muestra insuficiente: %d veredictos (mínimo %d para juzgar; criterio de paso: 300).', $minVerdicts, ClefShadowComparisonQuery::MIN_SAMPLE));
            }
        }

        return self::SUCCESS;
    }
}
```

Agregar `ClefReportCommand::class` al array de comandos del `AIServiceProvider`.

- [ ] **Step 6: Run** el filtro → PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Domains/AI/Queries/ClefShadowComparisonQuery.php app/Domains/AI/Commands/ClefReportCommand.php app/Domains/AI/AIServiceProvider.php tests/Feature/Domains/AI/Clef/ClefShadowComparisonQueryTest.php tests/Feature/Domains/AI/Clef/ClefReportCommandTest.php
git commit -m "feat: reporte de comparación gpt vs clef contra veredicto humano"
```

---

### Task 10: Docs de logging, gates completos y PR

**Files:**
- Modify: `docs/SAM/logging.md` (sección `### IA (\`ai\`) y copiloto`)

- [ ] **Step 1: Filas en `docs/SAM/logging.md`**, con el mismo formato de tabla que `ai.evaluation.completed`:

```markdown
| `ai.clef_shadow.dispatched` | ok | - | `evaluation_id`; result `models`. Después del commit de la evaluación oficial |
| `ai.clef_shadow.skipped` | skipped | `disabled` (debug), `shadow_expired` (ventana `ai.clef.shadow_until` vacía o vencida), `missing_credentials`, `rules_only_mode`, `not_sampled` (calc `sample_rate`, `roll`), `already_evaluated` (por modelo), `evaluation_missing`, `team_mismatch`, `no_snapshot` | `evaluation_id`, y `model` o `team_id` cuando aplica |
| `ai.clef_shadow.completed` | ok | - | `evaluation_id`, `model`, `source` (`live`/`backfill`), `schema_version`; calc `input_tokens`, `price_per_million_input`, `cost_estimate` (= `round(input_tokens × price_per_million_input / 1e6, 5)`), `latency_ms`, `images_sent`, `gpt_classification`, `matches_gpt`; result `classification`, `classification_probability`, `risk_score`. Nunca el estado ni las imágenes |
| `ai.clef_shadow.image_skipped` | skipped | `limits` | `evaluation_id`; calc `candidates`, `sent`, `skipped_by_reason` (`missing`, `unsupported_type`, `oversize`, `max_images`, `total_budget`), `max_images`, `max_image_bytes`, `max_total_image_bytes`, `bytes_sent` |
| `ai.clef_shadow.failed` | degraded / failed | `http_{status}`, `timeout`, `connection`, `malformed_response` o clase de la excepción | `evaluation_id`, `model`, `attempt`; `degraded` si la cola va a reintentar, `failed` cuando se persiste la fila `failed` |
| `ai.clef_backfill.planned` | ok | - | `team_id`, `since`, `limit`; calc `evaluations`, `input_tokens`, `models`, `price_per_million_sum`, `estimated_cost` (= `round(input_tokens × price_per_million_sum / 1e6, 5)`) |
| `ai.label.recorded` | ok | - | `evaluation_id`, `normalized_event_id`, `user_id`; result `verdict`. Nunca datos del evento |
```

- [ ] **Step 2: Formato y análisis estático**

Run: `vendor/bin/pint --dirty --format agent` → sin cambios pendientes.
Run: `composer analyse` → 0 errores (sin tocar `phpstan-baseline.neon`; si Larastan se queja de tipos en arrays de `config()`, castear en el punto de uso como ya se hace).

- [ ] **Step 3: Suite de IA y suite completa**

Run: `php artisan test --compact tests/Feature/Domains/AI` → PASS (incluye los tests existentes sin cambios).
Run: `composer ci:check` → verde.

- [ ] **Step 4: Revisión de aislamiento** con el subagente `tenant-isolation-reviewer` sobre la rama; atender lo que reporte con commits nuevos.

- [ ] **Step 5: Commit y PR**

```bash
git add docs/SAM/logging.md
git commit -m "docs: códigos de log de la medición en sombra con clef"
git push -u origin feat/clef-shadow-evaluation
gh pr create --title "feat: medición en sombra de cloudflare clef + base etiquetada" --body-file -
```

Cuerpo del PR: resumen del spec, cómo activarlo, y el checklist de puesta en marcha:
1. `sail artisan migrate`.
2. `.env`: `AI_CLEF_SHADOW_ENABLED=true`, `AI_CLEF_SHADOW_UNTIL=<hoy + 28 días>`.
3. `docker compose restart horizon scheduler` (los workers cachean config y autoloader).
4. `sail artisan ai:clef-backfill --team=<team real>` (revisar el costo estimado y confirmar).
5. `sail artisan ai:label-events --team=<team real> --user=<tu email> --limit=100`.
6. `sail artisan ai:clef-report --team=<team real> --by-type`.

Después del push: `gh pr checks --watch` y arreglar lo rojo con commits nuevos.
