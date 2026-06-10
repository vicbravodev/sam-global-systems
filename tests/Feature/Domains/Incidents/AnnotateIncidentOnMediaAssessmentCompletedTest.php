<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Listeners\AnnotateIncidentOnMediaAssessmentCompleted;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Roadmap B8: what the AI saw in the footage lands on the incident timeline
 * and the inbox is told to refresh.
 */
class AnnotateIncidentOnMediaAssessmentCompletedTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([IncidentUpdatedBroadcast::class]);

        $this->team = User::factory()->create()->currentTeam;
    }

    /**
     * @return array{0: NormalizedEvent, 1: AIEventEvaluation, 2: AIMediaAssessment}
     */
    private function makeAssessedEvaluation(): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $assessment = AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);

        return [$event, $evaluation, $assessment];
    }

    private function handle(AIEventEvaluation $evaluation, AIMediaAssessment $assessment): void
    {
        app(AnnotateIncidentOnMediaAssessmentCompleted::class)->handle(
            new MediaAssessmentCompleted($evaluation, collect([$assessment])),
        );
    }

    public function test_appends_media_assessed_timeline_entry_and_broadcasts(): void
    {
        [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->handle($evaluation, $assessment);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::MediaAssessed->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('contradicts_event', $entry->payload_json['result']);
        $this->assertSame($assessment->id, $entry->payload_json['assessment_id']);

        Event::assertDispatched(
            IncidentUpdatedBroadcast::class,
            fn (IncidentUpdatedBroadcast $broadcast) => $broadcast->incidentId === $incident->id
        );
    }

    public function test_resolves_incident_through_event_link(): void
    {
        [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => null,
        ]);

        IncidentEventLink::factory()->create([
            'incident_id' => $incident->id,
            'normalized_event_id' => $event->id,
        ]);

        $this->handle($evaluation, $assessment);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::MediaAssessed->value)
            ->count());
    }

    public function test_terminal_incident_is_still_annotated(): void
    {
        [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        $incident = Incident::factory()->closed()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->handle($evaluation, $assessment);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::MediaAssessed->value)
            ->count());
    }

    public function test_no_ops_when_no_incident_exists(): void
    {
        [, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        $this->handle($evaluation, $assessment);

        $this->assertSame(0, IncidentTimeline::query()->count());
        Event::assertNotDispatched(IncidentUpdatedBroadcast::class);
    }
}
