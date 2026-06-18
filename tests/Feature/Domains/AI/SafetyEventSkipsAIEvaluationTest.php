<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\LoadRecentAssetHistory;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SafetyEventSkipsAIEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_listener_skips_dispatch_for_safety_category_event(): void
    {
        Bus::fake();

        $event = $this->eventWithCategory('safety');
        [$snapshot, $profile] = $this->contextFor($event);

        app(EvaluateOnEventContextBuilt::class)->handle(new EventContextBuilt($snapshot, $profile));

        Bus::assertNotDispatched(EvaluateEventJob::class);
    }

    public function test_listener_dispatches_for_emergency_category_event(): void
    {
        Bus::fake();

        $event = $this->eventWithCategory('emergency');
        [$snapshot, $profile] = $this->contextFor($event);

        app(EvaluateOnEventContextBuilt::class)->handle(new EventContextBuilt($snapshot, $profile));

        Bus::assertDispatched(EvaluateEventJob::class, fn (EvaluateEventJob $job) => $job->normalizedEventId === $event->id);
    }

    public function test_job_creates_no_evaluation_for_safety_category_event(): void
    {
        $event = $this->eventWithCategory('safety');

        (new EvaluateEventJob($event->id))->handle(app(EvaluateEventWithAI::class), app(AIEvaluationGate::class));

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());
    }

    public function test_job_evaluates_non_safety_category_event(): void
    {
        $event = $this->eventWithCategory('emergency', ['payload_normalized_json' => ['severity' => 'high']]);

        (new EvaluateEventJob($event->id))->handle(app(EvaluateEventWithAI::class), app(AIEvaluationGate::class));

        $this->assertSame(1, AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());
    }

    public function test_reevaluation_job_does_not_evaluate_safety_category_event(): void
    {
        $event = $this->eventWithCategory('safety');

        (new ReevaluateEventJob($event->id, ReevaluationTrigger::MediaArrived->value))
            ->handle(app(ReevaluateEventWithNewEvidence::class), app(AIEvaluationGate::class));

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());
        $this->assertSame(0, AIReevaluationRequest::query()
            ->where('normalized_event_id', $event->id)
            ->count());
    }

    public function test_safety_event_keeps_media_as_evidence_without_multimodal_assessment(): void
    {
        Storage::fake('rustfs');

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $safetyCategory = EventCategory::factory()->safety()->create();

        $rawEvent = RawEvent::factory()->create(['team_id' => $teamId]);
        RawEventAttachment::factory()->create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Clip,
            'mime_type' => 'video/mp4',
            'storage_path' => 'integrations/incoming/safety-clip.mp4',
        ]);
        Storage::disk('rustfs')->put('integrations/incoming/safety-clip.mp4', 'binary-clip-bytes');

        $event = NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'raw_event_id' => $rawEvent->id,
            'event_category_id' => $safetyCategory->id,
        ]);

        $media = app(AttachImmediateEventMedia::class)->execute($event->fresh());

        // Media is retained as evidence...
        $this->assertCount(1, $media);
        $this->assertSame(1, EventMediaContext::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());

        // ...but the safety event is never analyzed by AI (text or multimodal).
        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->count());
        $this->assertSame(0, AIMediaAssessment::query()->count());
    }

    public function test_safety_event_feeds_correlation_without_ai_evaluation(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);

        $safetyCategory = EventCategory::factory()->safety()->create();
        $harshType = EventType::factory()->create([
            'code' => 'harsh_braking',
            'category_id' => $safetyCategory->id,
        ]);

        $panicTime = now();
        $safetyEvent = NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $harshType->id,
            'event_category_id' => $safetyCategory->id,
            'occurred_at' => $panicTime->copy()->subMinutes(2),
        ]);

        $history = app(LoadRecentAssetHistory::class)->execute(
            assetId: $asset->id,
            currentEventTypeId: null,
            before: $panicTime,
        );

        $this->assertTrue($history['harsh_driving_near_event']);
        $this->assertSame(1, $history['nearby_safety_events_count']);
        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $safetyEvent->id)
            ->count());
    }

    public function test_skip_categories_is_config_driven(): void
    {
        config(['ai.skip_evaluation_categories' => []]);
        Bus::fake();

        $event = $this->eventWithCategory('safety');
        [$snapshot, $profile] = $this->contextFor($event);

        app(EvaluateOnEventContextBuilt::class)->handle(new EventContextBuilt($snapshot, $profile));

        Bus::assertDispatched(EvaluateEventJob::class, fn (EvaluateEventJob $job) => $job->normalizedEventId === $event->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventWithCategory(string $code, array $attributes = []): NormalizedEvent
    {
        $user = User::factory()->create();
        $category = EventCategory::factory()->state(['code' => $code, 'name' => ucfirst($code)])->create();

        return NormalizedEvent::factory()->create(array_merge([
            'team_id' => $user->currentTeam->id,
            'event_category_id' => $category->id,
        ], $attributes));
    }

    /**
     * @return array{0: EventContextSnapshot, 1: OperationalContextProfile}
     */
    private function contextFor(NormalizedEvent $event): array
    {
        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $event->team_id,
            'normalized_event_id' => $event->id,
        ]);
        $profile = OperationalContextProfile::factory()->create(['team_id' => $event->team_id]);

        return [$snapshot, $profile];
    }
}
