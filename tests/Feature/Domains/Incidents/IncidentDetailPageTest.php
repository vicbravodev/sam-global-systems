<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentResolution;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F9: the incident full-page detail renders media, AI assessments and
 * related history; the JSON branch keeps serving the inbox panel.
 */
class IncidentDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    /**
     * @return array{0: Incident, 1: NormalizedEvent}
     */
    private function makeIncidentWithEvent(): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        return [$incident, $event];
    }

    public function test_browser_navigation_renders_full_page_with_media_props(): void
    {
        [$incident, $event] = $this->makeIncidentWithEvent();

        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'media_url' => 'https://media.example.test/clip.mp4',
        ]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);

        $prior = Incident::factory()->closed()->create(['team_id' => $this->team->id]);

        EventRelatedIncidentLink::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'incident_id' => $prior->id,
            'relation_type' => IncidentRelationType::PriorSimilarIncident,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('incident.incidentId', $incident->id)
                ->has('media', 1, fn (Assert $item) => $item
                    ->where('id', $media->id)
                    ->where('url', 'https://media.example.test/clip.mp4')
                    ->etc())
                ->has('mediaAssessments', 1, fn (Assert $item) => $item
                    ->where('result', 'contradicts_event')
                    ->where('mediaContextId', $media->id)
                    ->etc())
                ->has('priorIncidents', 1, fn (Assert $item) => $item
                    ->where('incidentId', $prior->id)
                    ->where('relationType', 'prior_similar_incident')
                    ->etc())
                ->has('mediaRequests')
                ->has('members')
                ->has('reclassifyOptions'),
        );
    }

    public function test_json_branch_still_serves_the_inbox_panel(): void
    {
        [$incident] = $this->makeIncidentWithEvent();

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJson(['incidentId' => $incident->id]);
        $response->assertJsonMissing(['component' => 'incidents/show']);
    }

    public function test_detail_payload_aggregates_visual_verdict_and_real_reasoning(): void
    {
        [$incident, $event] = $this->makeIncidentWithEvent();

        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'model_used' => 'openai:gpt-5-mini',
            'signals_json' => [
                'reasoning_steps' => ['Paso uno.', 'Paso dos.'],
                'key_factors' => [],
            ],
        ]);

        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('incident.mediaSummary.total', 1)
                ->where('incident.mediaSummary.assessed', 1)
                ->where('incident.mediaSummary.contradicts', 1)
                ->where('incident.aiReasoningSteps', ['Paso uno.', 'Paso dos.'])
                ->where('incident.resolution', null),
        );
    }

    public function test_json_branch_carries_media_summary_for_the_inbox_panel(): void
    {
        [$incident, $event] = $this->makeIncidentWithEvent();

        EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJsonPath('mediaSummary.total', 1);
        $response->assertJsonPath('mediaSummary.assessed', 0);
    }

    /**
     * A panic lands a photo and a clip per camera; the vision pipeline cuts
     * frames out of the clip. People must see 1 photo + 1 clip (with the first
     * frame as the clip's preview), not "3 imágenes" and a blank clip box.
     *
     * @return array{0: Incident, 1: EventMediaContext, 2: EventMediaContext, 3: EventMediaContext}
     */
    private function makePanicMediaSet(): array
    {
        [$incident, $event] = $this->makeIncidentWithEvent();

        $media = fn (array $attributes): EventMediaContext => EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            ...$attributes,
        ]);

        $clip = $media(['media_type' => 'clip', 'mime_type' => 'video/mp4', 'media_url' => 'https://media.example.test/road.mp4']);
        $photo = $media(['media_type' => 'snapshot', 'mime_type' => 'image/jpeg', 'media_url' => 'https://media.example.test/road.jpg']);
        $media([
            'media_type' => 'snapshot',
            'media_url' => 'https://media.example.test/road-frame-1.jpg',
            'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 15],
        ]);
        $firstFrame = $media([
            'media_type' => 'snapshot',
            'media_url' => 'https://media.example.test/road-frame-0.jpg',
            'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 3],
        ]);

        return [$incident, $photo, $clip, $firstFrame];
    }

    public function test_clip_frames_fold_under_their_clip_and_give_it_a_preview(): void
    {
        [$incident, $photo, $clip, $firstFrame] = $this->makePanicMediaSet();

        $response = $this->actingAs($this->user)->get(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/show')
                ->has('media', 2)
                // Photos first: they load instantly.
                ->has('media.0', fn (Assert $item) => $item
                    ->where('id', $photo->id)
                    ->where('frameIds', [])
                    ->etc())
                ->has('media.1', fn (Assert $item) => $item
                    ->where('id', $clip->id)
                    ->where('thumbnailUrl', 'https://media.example.test/road-frame-0.jpg')
                    ->where('frameIds', fn ($ids) => collect($ids)->first() === $firstFrame->id && count($ids) === 2)
                    ->etc())
                ->where('incident.mediaSummary.total', 2)
                ->where('incident.mediaSummary.images', 1)
                ->where('incident.mediaSummary.clips', 1),
        );
    }

    public function test_inbox_panel_thumbnails_never_point_an_img_at_a_video(): void
    {
        [$incident, $photo, $clip] = $this->makePanicMediaSet();

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJsonPath('mediaSummary.total', 2);
        $response->assertJsonPath('mediaSummary.thumbnails', [
            ['id' => $photo->id, 'url' => 'https://media.example.test/road.jpg', 'mediaType' => 'snapshot'],
            ['id' => $clip->id, 'url' => 'https://media.example.test/road-frame-0.jpg', 'mediaType' => 'clip'],
        ]);
    }

    public function test_visual_summary_counts_one_verdict_per_file_not_per_frame(): void
    {
        [$incident, $photo, $clip, $firstFrame] = $this->makePanicMediaSet();
        $secondFrame = EventMediaContext::query()->where('metadata_json->offset_seconds', 15)->sole();

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $incident->related_event_id,
        ]);

        AIMediaAssessment::factory()->create(['evaluation_id' => $evaluation->id, 'event_media_context_id' => $photo->id, 'result' => 'inconclusive']);
        AIMediaAssessment::factory()->create(['evaluation_id' => $evaluation->id, 'event_media_context_id' => $firstFrame->id, 'result' => 'inconclusive']);
        AIMediaAssessment::factory()->contradicts()->create(['evaluation_id' => $evaluation->id, 'event_media_context_id' => $secondFrame->id]);

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        // 2 files (photo + clip), both assessed; the clip reads as the frame
        // that saw something.
        $response->assertJsonPath('mediaSummary.total', 2);
        $response->assertJsonPath('mediaSummary.assessed', 2);
        $response->assertJsonPath('mediaSummary.contradicts', 1);
        $response->assertJsonPath('mediaSummary.inconclusive', 1);
        $this->assertNotNull($clip->id);
    }

    public function test_timeline_entries_are_presented_in_spanish_with_entry_type(): void
    {
        [$incident] = $this->makeIncidentWithEvent();

        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Created,
            'title' => 'Incident created',
            'occurred_at' => now()->subMinutes(10),
        ]);

        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::MediaAssessed,
            'title' => 'Media evaluada: contradicts_event',
            'payload_json' => [
                'result' => 'contradicts_event',
                'confidence_score' => 0.91,
            ],
            'occurred_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJsonPath('timeline.0.text', 'Incidente creado');
        $response->assertJsonPath('timeline.0.entryType', 'created');
        $response->assertJsonPath(
            'timeline.1.text',
            'Media evaluada: contradice el evento',
        );
        $response->assertJsonPath('timeline.1.type', 'media');
        $response->assertJsonPath('timeline.1.meta.result', 'contradicts_event');
    }

    public function test_detail_payload_includes_resolution_when_resolved(): void
    {
        [$incident] = $this->makeIncidentWithEvent();

        IncidentResolution::factory()->create([
            'incident_id' => $incident->id,
            'resolution_code' => ResolutionCode::OperatorConfirmedSafe,
            'resolution_summary' => 'El conductor confirmó que fue una falsa alarma.',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJsonPath(
            'resolution.code',
            'operator_confirmed_safe',
        );
        $response->assertJsonPath(
            'resolution.summary',
            'El conductor confirmó que fue una falsa alarma.',
        );
    }

    public function test_full_page_is_not_found_for_other_team_incident(): void
    {
        $foreign = Incident::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $foreign->id]),
        );

        $response->assertNotFound();
    }

    public function test_media_request_endpoint_creates_deferred_request(): void
    {
        [$incident, $event] = $this->makeIncidentWithEvent();

        $response = $this->actingAs($this->user)->postJson(
            route('incidents.media.request', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['request_type' => 'fetch_video_clip'],
        );

        $response->assertStatus(202);

        $this->assertSame(1, EventMediaRequest::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());
    }

    public function test_media_request_is_rejected_for_other_team_incident(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignEvent = NormalizedEvent::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);
        $foreign = Incident::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
            'related_event_id' => $foreignEvent->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('incidents.media.request', ['current_team' => $this->team->slug, 'incident' => $foreign->id]),
        );

        $response->assertNotFound();

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_media_request_without_source_event_fails_with_422(): void
    {
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('incidents.media.request', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
        );

        $response->assertStatus(422);
    }
}
