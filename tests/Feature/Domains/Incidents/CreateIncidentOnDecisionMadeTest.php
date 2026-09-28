<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Listeners\CreateIncidentOnDecisionMade;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CreateIncidentOnDecisionMadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_incident_outcome_dispatches_create_incident_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $decision = $this->makeDecision($user->currentTeam->id, $event->id, DecisionOutcomeCode::Incident);

        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertDispatched(CreateIncidentJob::class, function (CreateIncidentJob $job) use ($event, $decision) {
            return $job->normalizedEventId === $event->id
                && ($job->context['decision_id'] ?? null) === $decision->id;
        });
    }

    public function test_non_actionable_outcome_is_ignored(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $decision = $this->makeDecision($user->currentTeam->id, $event->id, DecisionOutcomeCode::Ignore);

        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertNotDispatched(CreateIncidentJob::class);
    }

    public function test_log_only_outcome_is_ignored(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $decision = $this->makeDecision($user->currentTeam->id, $event->id, DecisionOutcomeCode::LogOnly);

        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertNotDispatched(CreateIncidentJob::class);
    }

    public function test_require_human_review_dispatches_flagged_medium_incident(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $decision = $this->makeDecision($user->currentTeam->id, $event->id, DecisionOutcomeCode::RequireHumanReview);

        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertDispatched(CreateIncidentJob::class, function (CreateIncidentJob $job) use ($event, $decision) {
            return $job->normalizedEventId === $event->id
                && ($job->context['decision_id'] ?? null) === $decision->id
                && ($job->context['priority_code'] ?? null) === 'medium'
                && is_string($job->context['request_review'] ?? null)
                && ($job->context['metadata']['requires_review'] ?? null) === true;
        });
    }

    public function test_alert_dispatches_low_priority_incident(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $decision = $this->makeDecision($user->currentTeam->id, $event->id, DecisionOutcomeCode::Alert);

        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertDispatched(CreateIncidentJob::class, function (CreateIncidentJob $job) use ($decision) {
            return ($job->context['decision_id'] ?? null) === $decision->id
                && ($job->context['priority_code'] ?? null) === 'low'
                && ! array_key_exists('request_review', $job->context)
                && ($job->context['metadata']['decision_outcome'] ?? null) === 'ALERT';
        });
    }

    public function test_review_job_opens_incident_in_review_status(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $this->makeAsset($team)->id,
        ]);
        $decision = $this->makeDecision($team->id, $event->id, DecisionOutcomeCode::RequireHumanReview);

        Bus::fake();
        app(CreateIncidentOnDecisionMade::class)->handle(new DecisionMade($decision));

        $job = null;
        Bus::assertDispatched(CreateIncidentJob::class, function (CreateIncidentJob $dispatched) use (&$job) {
            $job = $dispatched;

            return true;
        });

        $job->handle(app(CreateIncidentFromEvent::class));

        $incident = Incident::withoutGlobalScopes()
            ->with(['status', 'priority'])
            ->where('related_event_id', $event->id)
            ->sole();

        $this->assertSame('in_review', $incident->status->code);
        $this->assertSame('medium', $incident->priority->code);
        $this->assertSame($decision->id, (int) $incident->related_decision_id);
        $this->assertTrue($incident->metadata_json['requires_review']);
    }

    public function test_review_job_does_not_move_an_existing_open_incident_to_review(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);
        $first = NormalizedEvent::factory()->create(['team_id' => $team->id, 'asset_id' => $asset->id]);
        $second = NormalizedEvent::factory()->create(['team_id' => $team->id, 'asset_id' => $asset->id]);

        (new CreateIncidentJob($first->id, ['incident_type_code' => 'collision']))
            ->handle(app(CreateIncidentFromEvent::class));

        $decision = $this->makeDecision($team->id, $second->id, DecisionOutcomeCode::RequireHumanReview);

        (new CreateIncidentJob($second->id, [
            'decision_id' => $decision->id,
            'priority_code' => 'medium',
            'request_review' => CreateIncidentOnDecisionMade::REVIEW_REASON,
        ]))->handle(app(CreateIncidentFromEvent::class));

        $incident = Incident::withoutGlobalScopes()->with('status')->where('related_event_id', $first->id)->sole();

        $this->assertSame('open', $incident->status->code);
    }

    public function test_alert_job_opens_low_priority_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $decision = $this->makeDecision($team->id, $event->id, DecisionOutcomeCode::Alert);

        (new CreateIncidentJob($event->id, [
            'decision_id' => $decision->id,
            'priority_code' => 'low',
            'metadata' => ['decision_outcome' => 'ALERT'],
        ]))->handle(app(CreateIncidentFromEvent::class));

        $incident = Incident::withoutGlobalScopes()
            ->with(['status', 'priority'])
            ->where('related_event_id', $event->id)
            ->sole();

        $this->assertSame('low', $incident->priority->code);
        $this->assertSame('open', $incident->status->code);
    }

    public function test_listener_run_via_job_creates_only_one_incident_for_repeat_dispatch(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
        ]);

        // Simulate two decisions firing for the same normalized event quickly.
        (new CreateIncidentJob($event->id, ['incident_type_code' => 'collision']))
            ->handle(app(CreateIncidentFromEvent::class));
        (new CreateIncidentJob($event->id, ['incident_type_code' => 'collision']))
            ->handle(app(CreateIncidentFromEvent::class));

        $this->assertSame(1, Incident::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('related_event_id', $event->id)
            ->count(),
            'Same normalized event must not create more than one incident — the dedup guard in CreateIncidentFromEvent must reuse the existing one.',
        );
    }

    private function makeDecision(int $teamId, int $normalizedEventId, DecisionOutcomeCode $outcomeCode): Decision
    {
        $outcome = DecisionOutcome::firstOrCreate(
            ['code' => $outcomeCode->value],
            ['name' => $outcomeCode->name, 'is_terminal' => $outcomeCode->isTerminal()],
        );

        return Decision::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $normalizedEventId,
            'outcome_id' => $outcome->id,
        ]);
    }

    private function makeAsset(Team $team): Asset
    {
        $type = AssetType::factory()->vehicle()->create();

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Test Truck',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
