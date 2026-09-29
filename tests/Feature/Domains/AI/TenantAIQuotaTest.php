<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\TenantAIQuota;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class TenantAIQuotaTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_tenant_ai_limits_prevent_over_quota_evaluation(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $meterIn = UsageMeter::where('code', 'ai_tokens_in')->firstOrFail();
        UsageEvent::create([
            'team_id' => $team->id,
            'usage_meter_id' => $meterIn->id,
            'event_key' => 'seed:over-quota',
            'quantity' => 6_000_000,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);
        $this->assertStringContainsString('Cuota', $evaluation->explanation_text);

        $ctx = $this->assertSystemLogged('ai.quota.checked', fn (array $c): bool => $c['result']['blocked'] === true);
        $this->assertSame($event->id, $ctx['input']['normalized_event_id']);
        $this->assertSame('text', $ctx['input']['purpose']);
        $this->assertFalse($ctx['calc']['is_critical']);
        $this->assertFalse($ctx['calc']['bypassed']);
        $this->assertTrue($ctx['calc']['tokens_meters_present']);
        $this->assertSame(6_000_000, $ctx['calc']['tokens_used_this_period']);
        $this->assertSame(5_000_000, $ctx['calc']['tokens_limit']);
        $this->assertSame(now()->format('Y-m'), $ctx['calc']['billing_period_key']);
        $this->assertNull($ctx['calc']['calls_today']);
        $this->assertSame('monthly_tokens', $ctx['result']['exceeded_by']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_limits_come_from_config(): void
    {
        $team = Team::factory()->create();

        $profile = app(ResolveTenantAIProfile::class)->execute($team->id);
        $this->assertSame(5_000_000, $profile->monthlyTokenLimit);
        $this->assertSame(2_000, $profile->dailyCallLimit);

        config()->set('ai.quota.monthly_token_limit', 123);
        config()->set('ai.quota.daily_call_limit', 7);

        $profile = app(ResolveTenantAIProfile::class)->execute($team->id);
        $this->assertSame(123, $profile->monthlyTokenLimit);
        $this->assertSame(7, $profile->dailyCallLimit);
    }

    public function test_daily_call_limit_falls_back_to_rules_only(): void
    {
        config()->set('ai.quota.daily_call_limit', 3);
        $team = Team::factory()->create();
        $this->seedCalls($team, 3);

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->makeEvent($team, 'high'));

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertStringContainsString('Cuota', $evaluation->explanation_text);

        $ctx = $this->assertSystemLogged('ai.quota.checked', fn (array $c): bool => $c['result']['blocked'] === true);
        $this->assertSame('daily_calls', $ctx['result']['exceeded_by']);
        $this->assertTrue($ctx['calc']['calls_meter_present']);
        $this->assertSame(3, $ctx['calc']['calls_limit']);
        $this->assertGreaterThanOrEqual($ctx['calc']['calls_limit'], $ctx['calc']['calls_today']);
    }

    public function test_calls_from_previous_days_do_not_count_toward_the_daily_limit(): void
    {
        config()->set('ai.quota.daily_call_limit', 3);
        $team = Team::factory()->create();
        $this->seedCalls($team, 5, now()->subDay());

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->makeEvent($team, 'high'));

        $this->assertNotSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
    }

    public function test_critical_events_bypass_the_token_and_call_quota(): void
    {
        // Solo interesa la evaluación: no correr decisiones/incidentes aguas abajo.
        Event::fake([AIEvaluationCompleted::class]);
        config()->set('ai.quota.monthly_token_limit', 10);
        config()->set('ai.quota.daily_call_limit', 1);
        $team = Team::factory()->create();
        $this->seedCalls($team, 5);
        $this->seedTokens($team, 1_000);

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->makeEvent($team, 'critical'));

        $this->assertNotSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame('null-agent:1.0', $evaluation->model_used);

        $ctx = $this->assertSystemLogged('ai.quota.checked');
        $this->assertTrue($ctx['calc']['is_critical']);
        $this->assertTrue($ctx['calc']['bypassed']);
        $this->assertFalse($ctx['result']['blocked']);
        $this->assertArrayNotHasKey('tokens_used_this_period', $ctx['calc']);
    }

    public function test_missing_meters_make_the_limit_not_apply_and_say_so(): void
    {
        UsageMeter::query()->delete();
        $team = Team::factory()->create();

        $blocked = app(TenantAIQuota::class)->blocks($this->makeEvent($team, 'high'));

        $this->assertFalse($blocked);
        $ctx = $this->assertSystemLogged('ai.quota.checked');
        $this->assertFalse($ctx['calc']['tokens_meters_present']);
        $this->assertNull($ctx['calc']['tokens_used_this_period']);
        $this->assertFalse($ctx['result']['blocked']);
        $this->assertNull($ctx['result']['exceeded_by']);
    }

    public function test_vision_is_skipped_over_quota_for_non_critical_events(): void
    {
        config()->set('ai.quota.daily_call_limit', 1);
        $team = Team::factory()->create();
        $this->seedCalls($team, 2);

        [$evaluation, $media] = $this->makeEvaluationWithMedia($team, 'high');

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        $this->assertSame(0, AIMediaAssessment::query()->count());
        $this->assertSame([], app(MediaAssessmentAgent::class)->receivedInputs);

        $ctx = $this->assertSystemLogged('ai.quota.checked', fn (array $c): bool => $c['input']['purpose'] === 'vision');
        $this->assertTrue($ctx['result']['blocked']);
    }

    public function test_vision_for_critical_events_bypasses_quota(): void
    {
        config()->set('ai.quota.daily_call_limit', 1);
        $team = Team::factory()->create();
        $this->seedCalls($team, 2);

        [$evaluation, $media] = $this->makeEvaluationWithMedia($team, 'critical');

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        $this->assertSame(1, AIMediaAssessment::query()->count());
    }

    private function makeEvent(Team $team, string $severityCode): NormalizedEvent
    {
        $severity = EventSeverity::query()->firstOrCreate(
            ['code' => $severityCode],
            EventSeverity::factory()->raw(['code' => $severityCode]),
        );

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_severity_id' => $severity->id,
        ]);
    }

    /**
     * @return array{0: AIEventEvaluation, 1: EventMediaContext}
     */
    private function makeEvaluationWithMedia(Team $team, string $severityCode): array
    {
        $event = $this->makeEvent($team, $severityCode);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Snapshot,
        ]);

        return [$evaluation, $media];
    }

    private function seedCalls(Team $team, int $quantity, $occurredAt = null): void
    {
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => UsageMeter::where('code', 'ai_calls')->value('id'),
            'quantity' => $quantity,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function seedTokens(Team $team, int $quantity): void
    {
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => UsageMeter::where('code', 'ai_tokens_in')->value('id'),
            'quantity' => $quantity,
        ]);
    }
}
