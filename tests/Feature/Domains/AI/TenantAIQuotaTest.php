<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAIQuotaTest extends TestCase
{
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
            'quantity' => 2_000_000,
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
    }
}
