<?php

namespace Tests\Feature\Domains\Copilot;

use App\Contracts\ObjectStorage;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Reopening a conversation re-resolves media card URLs from the tenant's
 * own media rows: stored signed URLs expire, and a stored URL is never
 * trusted (another tenant's media id comes back empty).
 */
class CopilotStoredMediaUrlsTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, CopilotFixtures, RefreshDatabase;

    private const STALE = 'https://rustfs.test/bucket/old.jpg?X-Amz-Expires=1800&X-Amz-Signature=expired';

    private User $owner;

    private Team $team;

    private Asset $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        Storage::fake('rustfs');

        [$this->owner, $this->team] = $this->memberWithRole('supervisor');
        $this->truck = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function conversationWithMedia(array $items): CopilotConversation
    {
        $conversation = CopilotConversation::factory()->create(['user_id' => $this->owner->id]);
        CopilotMessage::factory()->for($conversation, 'conversation')->assistant()->create([
            'blocks_json' => [
                ['type' => 'notice', 'tone' => 'info', 'text' => 'hola'],
                ['type' => 'media', 'assetId' => $this->truck->id, 'assetLabel' => 'T555', 'href' => '/x', 'items' => $items],
            ],
        ]);

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function showItems(CopilotConversation $conversation): array
    {
        return $this->actingAs($this->owner)
            ->getJson("/{$this->team->slug}/copilot/conversations/{$conversation->id}")
            ->assertOk()
            ->json('messages.0.blocks.1.items');
    }

    public function test_storage_media_gets_a_fresh_signed_url_instead_of_the_stored_one(): void
    {
        Storage::disk('rustfs')->put('teams/1/snap.jpg', 'img');
        $snapshot = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => 'teams/1/snap.jpg',
        ]);

        $items = $this->showItems($this->conversationWithMedia([
            ['id' => $snapshot->id, 'mediaType' => 'snapshot', 'url' => self::STALE, 'thumbnailUrl' => self::STALE],
        ]));

        $this->assertNotNull($items[0]['url']);
        $this->assertNotSame(self::STALE, $items[0]['url']);
        $this->assertSame($items[0]['url'], $items[0]['thumbnailUrl']);
        $this->assertSystemNotLogged('copilot.media.refresh_skipped');
    }

    public function test_provider_urls_are_kept(): void
    {
        $clip = EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => null,
            'media_url' => 'https://cdn.provider.com/clip.mp4',
            'thumbnail_url' => 'https://cdn.provider.com/poster.jpg',
        ]);

        $items = $this->showItems($this->conversationWithMedia([
            ['id' => $clip->id, 'mediaType' => 'clip', 'url' => self::STALE, 'thumbnailUrl' => null],
        ]));

        $this->assertSame('https://cdn.provider.com/clip.mp4', $items[0]['url']);
        $this->assertSame('https://cdn.provider.com/poster.jpg', $items[0]['thumbnailUrl']);
    }

    public function test_clip_frame_poster_is_resigned_and_a_foreign_frame_is_ignored(): void
    {
        Storage::disk('rustfs')->put('teams/1/frame-0.jpg', 'img');
        $clip = EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'media_url' => 'https://cdn.provider.com/clip.mp4',
            'thumbnail_url' => null,
        ]);
        $frame = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => 'teams/1/frame-0.jpg',
        ]);
        $foreignFrame = EventMediaContext::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'storage_path' => 'teams/2/frame-0.jpg',
        ]);

        $items = $this->showItems($this->conversationWithMedia([
            ['id' => $clip->id, 'mediaType' => 'clip', 'url' => self::STALE, 'thumbnailUrl' => self::STALE, 'thumbnailMediaId' => $frame->id],
            ['id' => $clip->id, 'mediaType' => 'clip', 'url' => self::STALE, 'thumbnailUrl' => self::STALE, 'thumbnailMediaId' => $foreignFrame->id],
        ]));

        $this->assertNotNull($items[0]['thumbnailUrl']);
        $this->assertNotSame(self::STALE, $items[0]['thumbnailUrl']);
        $this->assertStringContainsString('frame-0.jpg', $items[0]['thumbnailUrl']);
        $this->assertNull($items[1]['thumbnailUrl']);
    }

    public function test_media_of_another_tenant_is_never_resigned_nor_the_stored_url_served(): void
    {
        Storage::disk('rustfs')->put('teams/2/secret.jpg', 'img');
        $foreign = EventMediaContext::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'storage_path' => 'teams/2/secret.jpg',
        ]);

        $conversation = $this->conversationWithMedia([
            ['id' => $foreign->id, 'mediaType' => 'snapshot', 'url' => self::STALE, 'thumbnailUrl' => self::STALE],
        ]);

        // The other tenant's row is neither touched nor signed into the reply.
        $items = $this->assertNoTenantLeak($this->team, fn () => $this->showItems($conversation));

        $this->assertNull($items[0]['url']);
        $this->assertNull($items[0]['thumbnailUrl']);
        $this->assertSystemLogged('copilot.media.refresh_skipped', fn (array $c) => $c['reason'] === 'media_not_found'
            && $c['calc']['missing_count'] === 1
            && $c['calc']['requested_count'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_signing_failure_degrades_to_no_url_and_is_logged(): void
    {
        $snapshot = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'storage_path' => 'teams/1/snap.jpg',
        ]);
        $this->mock(ObjectStorage::class)
            ->shouldReceive('temporaryUrl')
            ->andThrow(new RuntimeException('signer down'));

        $items = $this->showItems($this->conversationWithMedia([
            ['id' => $snapshot->id, 'mediaType' => 'snapshot', 'url' => self::STALE, 'thumbnailUrl' => self::STALE],
        ]));

        $this->assertNull($items[0]['url']);
        $this->assertNull($items[0]['thumbnailUrl']);
        $this->assertSystemLogged('copilot.media.sign_failed', fn (array $c) => $c['reason'] === 'signing_failed'
            && $c['input']['event_media_context_id'] === $snapshot->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_messages_without_media_cards_are_untouched(): void
    {
        $conversation = CopilotConversation::factory()->create(['user_id' => $this->owner->id]);
        CopilotMessage::factory()->for($conversation, 'conversation')->assistant()->create([
            'blocks_json' => [['type' => 'notice', 'tone' => 'info', 'text' => 'hola']],
        ]);

        $this->actingAs($this->owner)
            ->getJson("/{$this->team->slug}/copilot/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.blocks.0.text', 'hola');
    }
}
