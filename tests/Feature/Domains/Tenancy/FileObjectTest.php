<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Tenancy\Models\FileObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileObjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_object_belongs_to_tenant_via_trait(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        FileObject::withoutGlobalScopes()->create([
            'team_id' => $userA->currentTeam->id,
            'bucket' => 'sam',
            'object_key' => 'team-a/file.jpg',
            'category' => 'attachment',
            'size_bytes' => 100,
        ]);

        FileObject::withoutGlobalScopes()->create([
            'team_id' => $userB->currentTeam->id,
            'bucket' => 'sam',
            'object_key' => 'team-b/file.jpg',
            'category' => 'attachment',
            'size_bytes' => 100,
        ]);

        $this->actingAs($userA);

        $files = FileObject::all();

        $this->assertCount(1, $files, 'BelongsToTenant scope should only return files for current team');
        $this->assertSame('team-a/file.jpg', $files->first()->object_key);
    }

    public function test_file_object_auto_sets_team_id_on_creation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $file = FileObject::create([
            'bucket' => 'sam',
            'object_key' => 'auto/set.jpg',
            'category' => 'attachment',
            'size_bytes' => 50,
        ]);

        $this->assertSame($user->currentTeam->id, $file->team_id);
    }

    public function test_file_object_morphs_to_raw_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $source = EventSource::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => null,
            'source_type' => EventSourceType::Webhook,
            'source_name' => 'webhook-test',
            'status' => EventSourceStatus::Active,
        ]);

        $rawEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'event_source_id' => $source->id,
            'payload_json' => ['hello' => 'world'],
            'received_at' => now(),
            'status' => RawEventStatus::Received,
            'checksum' => hash('sha256', 'payload'),
        ]);

        $file = FileObject::create([
            'bucket' => 'sam',
            'object_key' => "team/{$user->currentTeam->id}/events/{$rawEvent->id}/snapshot.jpg",
            'category' => 'attachment',
            'size_bytes' => 1024,
            'fileable_type' => RawEvent::class,
            'fileable_id' => $rawEvent->id,
        ]);

        $resolved = $file->fresh()->fileable;

        $this->assertInstanceOf(RawEvent::class, $resolved);
        $this->assertSame($rawEvent->id, $resolved->id);
    }

    public function test_file_object_casts_metadata_json(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $file = FileObject::create([
            'bucket' => 'sam',
            'object_key' => 'meta/file.jpg',
            'category' => 'attachment',
            'size_bytes' => 100,
            'metadata_json' => ['camera' => 'front', 'fps' => 30],
        ]);

        $fresh = $file->fresh();

        $this->assertIsArray($fresh->metadata_json);
        $this->assertSame('front', $fresh->metadata_json['camera']);
        $this->assertSame(30, $fresh->metadata_json['fps']);
    }
}
