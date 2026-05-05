<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\FileObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachImmediateEventMediaTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        Event::fake([EventMediaAvailable::class]);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_uploads_attachment_to_canonical_path_and_creates_media_context(): void
    {
        [$normalizedEvent, $attachment] = $this->buildEventWithAttachment(
            attachmentType: AttachmentType::Clip,
            mimeType: 'video/mp4',
            sourcePath: 'integrations/incoming/clip-abc.mp4',
            contents: 'binary-clip-bytes',
        );

        $result = app(AttachImmediateEventMedia::class)->execute($normalizedEvent);

        $this->assertCount(1, $result);

        $expectedPath = sprintf(
            'teams/%d/events/%d/media/clip-abc.mp4',
            $this->teamId,
            $normalizedEvent->id,
        );

        Storage::disk('rustfs')->assertExists($expectedPath);

        $media = $result->first();
        $this->assertSame($expectedPath, $media->storage_path);
        $this->assertSame(MediaType::Clip, $media->media_type);
        $this->assertSame(MediaAvailabilityStatus::Available, $media->availability_status);
        $this->assertSame(MediaRetrievalStatus::Ready, $media->retrieval_status);
        $this->assertSame($attachment->id, $media->source_attachment_id);
        $this->assertSame($this->teamId, $media->team_id);
        $this->assertNotNull($media->file_object_id);

        $fileObject = FileObject::withoutGlobalScopes()->findOrFail($media->file_object_id);
        $this->assertSame('media', $fileObject->category);
        $this->assertSame($expectedPath, $fileObject->object_key);
        $this->assertSame(EventMediaContext::class, $fileObject->fileable_type);
        $this->assertSame($media->id, $fileObject->fileable_id);

        Event::assertDispatched(
            EventMediaAvailable::class,
            fn (EventMediaAvailable $event) => $event->media->id === $media->id
                && $event->normalizedEvent->id === $normalizedEvent->id,
        );
    }

    public function test_returns_empty_collection_when_raw_event_has_no_attachments(): void
    {
        $rawEvent = RawEvent::factory()->create(['team_id' => $this->teamId]);
        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
        ]);

        $result = app(AttachImmediateEventMedia::class)->execute($normalizedEvent);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, EventMediaContext::withoutGlobalScopes()->count());
    }

    public function test_skips_when_source_storage_has_no_bytes(): void
    {
        [$normalizedEvent] = $this->buildEventWithAttachment(
            attachmentType: AttachmentType::Snapshot,
            mimeType: 'image/jpeg',
            sourcePath: 'integrations/incoming/missing.jpg',
            contents: null,
        );

        $result = app(AttachImmediateEventMedia::class)->execute($normalizedEvent);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, EventMediaContext::withoutGlobalScopes()->count());
    }

    public function test_is_idempotent_across_repeated_runs(): void
    {
        [$normalizedEvent] = $this->buildEventWithAttachment(
            attachmentType: AttachmentType::Snapshot,
            mimeType: 'image/jpeg',
            sourcePath: 'integrations/incoming/snap-xyz.jpg',
            contents: 'snapshot-bytes',
        );

        app(AttachImmediateEventMedia::class)->execute($normalizedEvent);
        app(AttachImmediateEventMedia::class)->execute($normalizedEvent->fresh());

        $this->assertSame(
            1,
            EventMediaContext::withoutGlobalScopes()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->count(),
        );

        $this->assertSame(
            1,
            FileObject::withoutGlobalScopes()
                ->where('object_key', 'like', '%snap-xyz.jpg')
                ->count(),
        );
    }

    /**
     * @return array{0: NormalizedEvent, 1: RawEventAttachment}
     */
    private function buildEventWithAttachment(
        AttachmentType $attachmentType,
        string $mimeType,
        string $sourcePath,
        ?string $contents,
    ): array {
        $rawEvent = RawEvent::factory()->create(['team_id' => $this->teamId]);

        $attachment = RawEventAttachment::factory()->create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => $attachmentType,
            'mime_type' => $mimeType,
            'storage_path' => $sourcePath,
        ]);

        if ($contents !== null) {
            Storage::disk('rustfs')->put($sourcePath, $contents);
        }

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
        ]);

        return [$normalizedEvent->fresh(), $attachment];
    }
}
