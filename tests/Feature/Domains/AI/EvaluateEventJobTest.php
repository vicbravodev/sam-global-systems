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

class EvaluateEventJobTest extends TestCase
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

    public function test_job_creates_evaluation_and_records_usage_when_context_exists(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $job = new EvaluateEventJob($event->id);
        $job->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );

        $evaluation = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        $this->assertNotNull($evaluation);

        $meter = UsageMeter::where('code', 'ai_calls')->firstOrFail();
        $usage = UsageEvent::withoutGlobalScopes()
            ->where('usage_meter_id', $meter->id)
            ->where('event_key', "ai_call:{$evaluation->id}")
            ->first();

        $this->assertNotNull($usage);
        $this->assertSame(1, (int) $usage->quantity);
    }

    public function test_job_is_idempotent_and_does_not_duplicate_evaluation_on_replay(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $job1 = new EvaluateEventJob($event->id);
        $job2 = new EvaluateEventJob($event->id);

        $job1->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );
        $job2->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );

        $count = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_job_no_ops_when_context_snapshot_missing(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $job = new EvaluateEventJob($event->id);
        $job->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->count());
    }

    public function test_job_no_ops_when_normalized_event_missing(): void
    {
        $job = new EvaluateEventJob(999999);
        $job->handle(
            app(EvaluateEventWithAI::class),
            app(RecordUsageEvent::class),
        );

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->count());
    }

    public function test_unique_id_is_normalized_event_id_and_queue_is_ai_evaluation(): void
    {
        $job = new EvaluateEventJob(42);

        $this->assertSame('42', $job->uniqueId());
        $this->assertSame('ai-evaluation', $job->queue);
    }
}
