<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EvaluateEventJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_listener_dispatches_evaluate_event_job_on_event_context_built(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);
        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $user->currentTeam->id,
            'normalized_event_id' => $event->id,
        ]);
        $profile = OperationalContextProfile::factory()->create(['team_id' => $user->currentTeam->id]);

        (new EvaluateOnEventContextBuilt)->handle(new EventContextBuilt($snapshot, $profile));

        Bus::assertDispatched(EvaluateEventJob::class, fn (EvaluateEventJob $job) => $job->normalizedEventId === $event->id);
    }

    public function test_usage_events_emitted_for_ai_calls_and_tokens(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        (new EvaluateEventJob($event->id))->handle(app(EvaluateEventWithAI::class));

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_calls');
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_tokens_in');
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_tokens_out');
    }

    public function test_job_is_idempotent_on_same_normalized_event_id(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $handler = app(EvaluateEventWithAI::class);

        (new EvaluateEventJob($event->id))->handle($handler);
        (new EvaluateEventJob($event->id))->handle($handler);

        $count = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_job_no_ops_when_event_missing(): void
    {
        (new EvaluateEventJob(999999))->handle(app(EvaluateEventWithAI::class));

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->count());
    }

    public function test_job_unique_id_is_normalized_event_id(): void
    {
        $job = new EvaluateEventJob(42);

        $this->assertSame('42', $job->uniqueId());
    }
}
