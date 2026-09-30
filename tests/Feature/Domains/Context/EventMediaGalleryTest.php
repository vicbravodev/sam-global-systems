<?php

namespace Tests\Feature\Domains\Context;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\EventMediaGallery;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class EventMediaGalleryTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private NormalizedEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $teamId = User::factory()->create()->currentTeam->id;
        $this->event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
    }

    private function media(array $attributes): EventMediaContext
    {
        return EventMediaContext::factory()->create([
            'team_id' => $this->event->team_id,
            'normalized_event_id' => $this->event->id,
            'media_url' => null,
            'thumbnail_url' => null,
            ...$attributes,
        ]);
    }

    public function test_frames_fold_under_their_clip_in_offset_order(): void
    {
        $clip = $this->media(['media_type' => 'clip', 'storage_path' => 'teams/1/clip.mp4']);
        $late = $this->media(['media_type' => 'snapshot', 'storage_path' => 'teams/1/f27.jpg', 'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 27]]);
        $early = $this->media(['media_type' => 'snapshot', 'storage_path' => 'teams/1/f3.jpg', 'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 3]]);
        $photo = $this->media(['media_type' => 'snapshot', 'storage_path' => 'teams/1/photo.jpg']);

        $storage = Mockery::mock(ObjectStorage::class);
        $storage->shouldReceive('temporaryUrl')->andReturnUsing(fn (string $path): string => "https://signed.test/{$path}");
        $this->app->instance(ObjectStorage::class, $storage);

        $entries = collect(app(EventMediaGallery::class)->entries(EventMediaContext::query()->get()))->keyBy(fn (array $e): int => $e['media']->id);

        $this->assertSame([$clip->id, $photo->id], $entries->keys()->sort()->values()->all());
        $this->assertSame([$early->id, $late->id], $entries[$clip->id]['frameIds']);
        $this->assertSame('https://signed.test/teams/1/f3.jpg', $entries[$clip->id]['thumbnailUrl']);
        $this->assertSame('https://signed.test/teams/1/clip.mp4', $entries[$clip->id]['url']);
        $this->assertNull($entries[$photo->id]['thumbnailUrl']);
        $this->assertTrue(EventMediaGallery::isVideo($clip));
        $this->assertFalse(EventMediaGallery::isVideo($photo));
    }

    public function test_a_frame_whose_clip_is_not_listed_stays_visible(): void
    {
        $orphan = $this->media(['media_type' => 'snapshot', 'media_url' => 'https://m.test/f.jpg', 'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => 999999]]);

        $entries = app(EventMediaGallery::class)->entries(EventMediaContext::query()->get());

        $this->assertCount(1, $entries);
        $this->assertSame($orphan->id, $entries[0]['media']->id);
    }

    public function test_signing_failure_degrades_to_no_url_and_is_logged(): void
    {
        $photo = $this->media(['media_type' => 'snapshot', 'storage_path' => 'teams/1/photo.jpg']);

        $storage = Mockery::mock(ObjectStorage::class);
        $storage->shouldReceive('temporaryUrl')->andThrow(new \RuntimeException('rustfs down'));
        $this->app->instance(ObjectStorage::class, $storage);

        $this->assertNull(app(EventMediaGallery::class)->urlFor($photo));

        $this->assertSystemLogged('context.media.url_unavailable', fn (array $c) => $c['reason'] === 'signing_failed'
            && $c['input']['event_media_context_id'] === $photo->id
            && $c['input']['normalized_event_id'] === $this->event->id);
        $this->assertNoSensitiveDataLogged();
    }
}
