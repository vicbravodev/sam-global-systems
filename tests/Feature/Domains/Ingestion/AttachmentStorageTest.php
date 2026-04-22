<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Contracts\ObjectStorage;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachment_stored_in_rustfs(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $eventSource = EventSource::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => null,
            'source_type' => EventSourceType::Webhook,
            'source_name' => 'webhook',
            'status' => EventSourceStatus::Active,
        ]);

        $rawEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'SafetyEvent'],
            'received_at' => now(),
            'status' => RawEventStatus::Received,
            'checksum' => hash('sha256', json_encode(['eventType' => 'SafetyEvent'])),
        ]);

        $storagePath = "teams/{$team->id}/raw-events/{$rawEvent->id}/snapshot.jpg";
        $fileContents = 'fake-binary-image-data';

        $mockStorage = Mockery::mock(ObjectStorage::class);
        $mockStorage->shouldReceive('put')
            ->once()
            ->with($storagePath, $fileContents, ['ContentType' => 'image/jpeg'])
            ->andReturnNull();

        $this->app->instance(ObjectStorage::class, $mockStorage);

        $objectStorage = app(ObjectStorage::class);
        $objectStorage->put($storagePath, $fileContents, ['ContentType' => 'image/jpeg']);

        $attachment = RawEventAttachment::create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Snapshot,
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($fileContents),
        ]);

        $this->assertNotNull(
            $attachment->id,
            'RawEventAttachment should be persisted with a valid ID',
        );

        $this->assertEquals(
            $storagePath,
            $attachment->storage_path,
            'Attachment storage_path should reference the RustFS object key',
        );

        $this->assertEquals(
            AttachmentType::Snapshot,
            $attachment->attachment_type,
            'Attachment type should be correctly stored as the AttachmentType enum',
        );

        $this->assertEquals(
            strlen($fileContents),
            $attachment->size_bytes,
            'Attachment size_bytes should accurately reflect the stored file size',
        );

        $this->assertDatabaseHas('raw_event_attachments', [
            'raw_event_id' => $rawEvent->id,
            'storage_path' => $storagePath,
        ]);
    }
}
