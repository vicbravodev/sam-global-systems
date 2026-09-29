<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ReevaluateEventTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_reevaluation_increments_version(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $user->currentTeam->id,
            'evaluation_version' => 1,
        ]);

        $evaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::ManualReviewRequested,
            reason: 'operator wants a second look',
        );

        $this->assertSame(2, $evaluation->evaluation_version);

        $versions = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->pluck('evaluation_version')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2], $versions);

        $this->assertSystemLogged('ai.reevaluation.completed', fn (array $c) => $c['result']['evaluation_version'] === 2
            && $c['result']['evaluation_id'] === $evaluation->id
            && $c['input']['reason_present'] === true);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('operator wants a second look', json_encode($this->systemLogEntries()));
    }

    public function test_reevaluation_reason_text_is_never_logged(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::MediaArrived,
            reason: 'motivo-libre-xyz',
        );

        $this->assertSystemLogged('ai.reevaluation.completed', fn (array $c) => $c['input']['reason_present'] === true);
        $this->assertStringNotContainsString('motivo-libre-xyz', json_encode($this->systemLogEntries()));
    }

    public function test_job_logs_when_event_is_missing(): void
    {
        (new ReevaluateEventJob(999_999, ReevaluationTrigger::MediaArrived->value))
            ->handle(app(ReevaluateEventWithNewEvidence::class), app(AIEvaluationGate::class));

        $this->assertSystemLogged('ai.reevaluation.skipped', fn (array $c) => $c['reason'] === 'normalized_event_missing'
            && $c['input']['normalized_event_id'] === 999_999
            && $c['input']['trigger_type'] === ReevaluationTrigger::MediaArrived->value);
    }

    public function test_reevaluation_deduplicates_pending_requests(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'medium'],
        ]);

        $first = AIReevaluationRequest::create([
            'normalized_event_id' => $event->id,
            'trigger_type' => ReevaluationTrigger::ManualReviewRequested,
            'status' => ReevaluationStatus::Pending,
            'requested_at' => now(),
        ]);

        app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::ManualReviewRequested,
        );

        $skipped = AIReevaluationRequest::where('normalized_event_id', $event->id)
            ->where('status', ReevaluationStatus::Skipped)
            ->count();

        $completed = AIReevaluationRequest::where('normalized_event_id', $event->id)
            ->where('status', ReevaluationStatus::Completed)
            ->count();

        $this->assertSame(1, $skipped);
        $this->assertSame(1, $completed);

        $this->assertSystemLogged('ai.reevaluation.superseded', fn (array $c) => $c['result']['superseded_request_id'] === $first->id
            && $c['result']['previous_status'] === 'pending');
    }
}
