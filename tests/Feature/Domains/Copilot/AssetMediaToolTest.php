<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Tools\AssetMediaTool;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Copilot must be able to answer "¿qué se ve en las cámaras del último pánico
 * de la T-879?": the files of the panic, which incident they belong to and
 * what the vision model saw in them. Thumbnails are real browser URLs:
 * never a storage path.
 */
class AssetMediaToolTest extends TestCase
{
    use AssertsTenantIsolation;
    use CopilotFixtures;
    use RefreshDatabase;
    use RunsCopilotTools;

    private const PERMISSIONS = ['assets.view', 'context.view'];

    private Team $team;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        Storage::fake('rustfs');

        $this->team = User::factory()->create()->currentTeam;
        $this->asset = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555', 'name' => 'T-879 JC 27BF7U']);
    }

    private function context(): CopilotToolContext
    {
        $now = CarbonImmutable::now();

        return new CopilotToolContext(
            teamId: $this->team->id,
            teamSlug: $this->team->slug,
            permissions: ['context.view'],
            period: new CopilotPeriod($now->subDay(), $now, 'hoy', 1),
            asset: $this->asset,
        );
    }

    /**
     * @return array{0: Incident, 1: EventMediaContext, 2: EventMediaContext}
     */
    private function panicWithFootage(): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'asset_id' => $this->asset->id]);
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id, 'related_event_id' => $event->id]);

        $media = fn (array $attributes): EventMediaContext => EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'asset_id' => $this->asset->id,
            'captured_at' => now()->subMinutes(5),
            ...$attributes,
        ]);

        $photo = $media(['media_type' => 'snapshot', 'media_url' => 'https://m.test/driver.jpg', 'metadata_json' => ['input' => 'dashcamDriverFacing', 'source' => 'uploaded_media']]);
        $clip = $media(['media_type' => 'clip', 'mime_type' => 'video/mp4', 'media_url' => 'https://m.test/road.mp4', 'metadata_json' => ['input' => 'dashcamRoadFacing', 'source' => 'uploaded_media']]);
        $frame = $media(['media_type' => 'snapshot', 'media_url' => 'https://m.test/road-f0.jpg', 'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 3]]);

        $evaluation = AIEventEvaluation::factory()->create(['team_id' => $this->team->id, 'normalized_event_id' => $event->id]);
        AIMediaAssessment::factory()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $photo->id,
            'result' => 'inconclusive',
            'summary_text' => 'Cabina de noche con los asientos vacíos.',
        ]);
        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $frame->id,
            'summary_text' => 'Unidad detenida dentro de un taller techado.',
        ]);

        return [$incident, $photo, $clip];
    }

    public function test_lists_real_files_with_their_incident_and_what_the_ai_saw(): void
    {
        [$incident, $photo, $clip] = $this->panicWithFootage();

        $result = app(AssetMediaTool::class)->run($this->context());

        $items = collect($result->blocks[0]['items'])->keyBy('id');

        // The frame folds under its clip: 2 files, not 3.
        $this->assertSame(2, $result->facts['media_count']);
        $this->assertEqualsCanonicalizing([$photo->id, $clip->id], $items->keys()->all());

        $this->assertSame('https://m.test/road-f0.jpg', $items[$clip->id]['thumbnailUrl']);
        $this->assertSame('contradicts_event', $items[$clip->id]['aiVerdict']);
        $this->assertSame('Unidad detenida dentro de un taller techado.', $items[$clip->id]['aiSummary']);
        $this->assertSame('Cámara frontal', $items[$clip->id]['roleLabel']);
        $this->assertSame('Cámara de cabina', $items[$photo->id]['roleLabel']);
        $this->assertSame($incident->reference(), $items[$photo->id]['incident']);
        $this->assertStringEndsWith("/incidents/{$incident->id}", $items[$photo->id]['incidentHref']);

        $this->assertSame(2, $result->facts['assessed_count']);
        $this->assertContains('Unidad detenida dentro de un taller techado.', array_column($result->facts['items'], 'ai_saw'));
        // The model gets the Spanish verdict, never the enum code.
        $this->assertEqualsCanonicalizing(['Contradice el evento', 'No concluyente'], array_column($result->facts['items'], 'ai_verdict'));
        $this->assertCount(1, $result->sources);
    }

    public function test_never_reads_another_tenant_footage_or_incidents(): void
    {
        $this->panicWithFootage();
        $other = User::factory()->create()->currentTeam;
        $otherEvent = NormalizedEvent::factory()->create(['team_id' => $other->id]);
        $foreignMedia = EventMediaContext::factory()->create(['team_id' => $other->id, 'normalized_event_id' => $otherEvent->id, 'asset_id' => $this->asset->id, 'captured_at' => now()]);
        Incident::factory()->open()->create(['team_id' => $other->id, 'related_event_id' => $otherEvent->id]);
        AIMediaAssessment::factory()->create([
            'evaluation_id' => AIEventEvaluation::factory()->create(['team_id' => $other->id, 'normalized_event_id' => $otherEvent->id])->id,
            'event_media_context_id' => $foreignMedia->id,
            'summary_text' => 'Lo que vio la IA en otra empresa.',
        ]);

        $result = $this->assertNoTenantLeak($this->team, fn () => app(AssetMediaTool::class)->run($this->context()));

        $this->assertSame(2, $result->facts['media_count']);
        $this->assertNotContains($foreignMedia->id, array_column($result->blocks[0]['items'], 'id'));
        $this->assertStringNotContainsString('otra empresa', json_encode($result->facts));
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
            'asset_id' => $this->asset->id,
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
            'asset_id' => $this->asset->id,
            'thumbnail_url' => 'https://cdn.example.com/poster.jpg',
        ]);

        $this->assertSame('https://cdn.example.com/poster.jpg', $this->mediaItems()[0]['thumbnailUrl']);
    }

    public function test_clip_without_thumbnail_returns_null(): void
    {
        Storage::disk('rustfs')->put('teams/1/clip.mp4', 'vid');
        EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->asset->id,
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
            'asset_id' => $this->asset->id,
            'thumbnail_url' => 'teams/1/private/poster.jpg',
        ]);

        $this->assertNull($this->mediaItems()[0]['thumbnailUrl']);
    }

    public function test_clip_without_poster_uses_its_first_stored_frame_and_remembers_it(): void
    {
        Storage::disk('rustfs')->put('teams/1/clip.mp4', 'vid');
        Storage::disk('rustfs')->put('teams/1/frame-0.jpg', 'img');
        $clip = EventMediaContext::factory()->videoClip()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->asset->id,
            'storage_path' => 'teams/1/clip.mp4',
            'thumbnail_url' => 'teams/1/private/poster.jpg',
        ]);
        $frame = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->asset->id,
            'media_type' => 'snapshot',
            'storage_path' => 'teams/1/frame-0.jpg',
            'metadata_json' => ['source' => 'video_frame', 'parent_media_context_id' => $clip->id, 'offset_seconds' => 0],
        ]);

        $items = $this->mediaItems();

        $this->assertCount(1, $items);
        $this->assertSame($clip->id, $items[0]['id']);
        $this->assertNotNull($items[0]['thumbnailUrl']);
        $this->assertStringNotContainsString('private/poster', $items[0]['thumbnailUrl']);
        $this->assertStringContainsString('frame-0.jpg', $items[0]['thumbnailUrl']);
        $this->assertSame($frame->id, $items[0]['thumbnailMediaId']);
    }
}
