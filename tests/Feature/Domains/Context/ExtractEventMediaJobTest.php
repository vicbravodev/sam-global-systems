<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Jobs\ExtractEventMediaJob;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtractEventMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_listener_dispatches_job_when_context_built(): void
    {
        Bus::fake();
        Event::fake([EventMediaAvailable::class]);

        $rawEvent = RawEvent::factory()->create(['team_id' => $this->teamId]);
        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
        ]);

        app(BuildEventContext::class)->execute($normalizedEvent);

        Bus::assertDispatched(
            ExtractEventMediaJob::class,
            fn (ExtractEventMediaJob $job) => $job->normalizedEventId === $normalizedEvent->id,
        );
    }

    public function test_handle_uploads_attachment_and_refreshes_snapshot(): void
    {
        Event::fake([EventContextBuilt::class, EventMediaAvailable::class]);

        $rawEvent = RawEvent::factory()->create(['team_id' => $this->teamId]);
        RawEventAttachment::factory()->create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Snapshot,
            'mime_type' => 'image/jpeg',
            'storage_path' => 'integrations/incoming/snap.jpg',
        ]);
        Storage::disk('rustfs')->put('integrations/incoming/snap.jpg', 'snapshot-bytes');

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
        ]);
        app(BuildEventContext::class)->execute($normalizedEvent);

        $initialSnapshot = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEvent->id)
            ->firstOrFail();
        $this->assertSame(1, $initialSnapshot->context_version);
        $this->assertSame([], $initialSnapshot->media_snapshot_json);

        (new ExtractEventMediaJob($normalizedEvent->id))->handle(
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
        );

        $expectedPath = sprintf(
            'teams/%d/events/%d/media/snap.jpg',
            $this->teamId,
            $normalizedEvent->id,
        );
        Storage::disk('rustfs')->assertExists($expectedPath);

        $this->assertSame(
            1,
            EventMediaContext::withoutGlobalScopes()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->count(),
        );

        $refreshed = $initialSnapshot->fresh();
        $this->assertSame(2, $refreshed->context_version);
        $this->assertNotEmpty($refreshed->media_snapshot_json);
        $this->assertTrue($refreshed->signals_json['has_visual_evidence']);
        $this->assertFalse($refreshed->signals_json['no_media_available']);
    }

    public function test_handle_is_idempotent_across_repeated_runs(): void
    {
        Event::fake([EventContextBuilt::class, EventMediaAvailable::class]);

        $rawEvent = RawEvent::factory()->create(['team_id' => $this->teamId]);
        RawEventAttachment::factory()->create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Clip,
            'mime_type' => 'video/mp4',
            'storage_path' => 'integrations/incoming/clip.mp4',
        ]);
        Storage::disk('rustfs')->put('integrations/incoming/clip.mp4', 'mp4-bytes');

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
        ]);
        app(BuildEventContext::class)->execute($normalizedEvent);

        (new ExtractEventMediaJob($normalizedEvent->id))->handle(
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
        );
        (new ExtractEventMediaJob($normalizedEvent->id))->handle(
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
        );

        $this->assertSame(
            1,
            EventMediaContext::withoutGlobalScopes()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->count(),
        );
    }

    public function test_handle_no_ops_when_normalized_event_missing(): void
    {
        (new ExtractEventMediaJob(99999))->handle(
            app(AttachImmediateEventMedia::class),
            app(RefreshContextMediaSnapshot::class),
        );

        $this->assertSame(0, EventMediaContext::withoutGlobalScopes()->count());
    }

    public function test_unique_id_is_normalized_event_id(): void
    {
        $job = new ExtractEventMediaJob(42);

        $this->assertSame('42', $job->uniqueId());
    }
}
