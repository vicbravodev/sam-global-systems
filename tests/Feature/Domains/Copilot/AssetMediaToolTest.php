<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Models\EventMediaContext;
use App\Models\Team;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetMediaToolTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    private const PERMISSIONS = ['assets.view', 'context.view'];

    private Team $team;

    private Asset $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        Storage::fake('rustfs');

        $this->team = Team::factory()->create();
        $this->truck = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555', 'name' => 'Kenworth']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaItems(): array
    {
        $this->callTool($this->team, self::PERMISSIONS, 'asset_media', ['asset_code' => 'T555']);

        return $this->toolBlock('media')['items'];
    }

    public function test_snapshot_in_storage_uses_its_signed_url_as_thumbnail(): void
    {
        Storage::disk('rustfs')->put('teams/1/snap.jpg', 'img');
        EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => 'teams/1/snap.jpg',
        ]);

        $item = $this->mediaItems()[0];

        $this->assertNotNull($item['url']);
        $this->assertSame($item['url'], $item['thumbnailUrl']);
    }

    public function test_clip_with_http_thumbnail_passes_it_through(): void
    {
        EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'thumbnail_url' => 'https://cdn.example.com/poster.jpg',
        ]);

        $this->assertSame('https://cdn.example.com/poster.jpg', $this->mediaItems()[0]['thumbnailUrl']);
    }

    public function test_clip_without_thumbnail_returns_null(): void
    {
        Storage::disk('rustfs')->put('teams/1/clip.mp4', 'vid');
        EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => 'teams/1/clip.mp4',
            'thumbnail_url' => null,
        ]);

        $item = $this->mediaItems()[0];

        $this->assertNotNull($item['url']);
        $this->assertNull($item['thumbnailUrl']);
    }

    public function test_clip_with_non_http_thumbnail_never_leaks_a_path(): void
    {
        EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'thumbnail_url' => 'teams/1/private/poster.jpg',
        ]);

        $this->assertNull($this->mediaItems()[0]['thumbnailUrl']);
    }
}
