<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Database\Seeders\AiUsageMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AiUsageMeterSeeder::class);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_ai_calls_meter_is_seeded_by_ai_usage_meter_seeder(): void
    {
        $this->assertTrue(UsageMeter::where('code', 'ai_calls')->exists());
    }

    public function test_evaluation_records_one_ai_calls_usage_event(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        (new EvaluateEventJob($event->id))->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );

        $meter = UsageMeter::where('code', 'ai_calls')->firstOrFail();
        $events = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->where('usage_meter_id', $meter->id)
            ->get();

        $this->assertCount(1, $events);
    }

    public function test_duplicate_event_key_is_deduplicated_via_record_usage_event(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $recordUsage = app(RecordUsageEvent::class);
        $evaluate = app(EvaluateEventWithAI::class);

        (new EvaluateEventJob($event->id))->handle($evaluate, $recordUsage);

        $evaluation = AIEventEvaluation::withoutGlobalScopes()->firstOrFail();
        $eventKey = "ai_call:{$evaluation->id}";

        $recordUsage->execute(
            teamId: $this->teamId,
            meterCode: 'ai_calls',
            quantity: 1,
            eventKey: $eventKey,
        );

        $meter = UsageMeter::where('code', 'ai_calls')->firstOrFail();
        $count = UsageEvent::withoutGlobalScopes()
            ->where('usage_meter_id', $meter->id)
            ->where('event_key', $eventKey)
            ->count();

        $this->assertSame(1, $count);
    }
}
