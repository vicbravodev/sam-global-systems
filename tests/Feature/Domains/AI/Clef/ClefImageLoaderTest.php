<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Infrastructure\AI\Clef\ClefImageLoader;
use App\Infrastructure\Storage\RustFsObjectStorage;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefImageLoaderTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    private const string JPEG = "\xFF\xD8\xFF\xE0";

    private const string GIF = 'GIF89a';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('rustfs');
        $this->app->instance(ObjectStorage::class, new RustFsObjectStorage);
    }

    public function test_loads_ready_images_and_skips_gif_oversize_missing_and_video(): void
    {
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);
        $media = fn (string $path, MediaType $type = MediaType::Snapshot) => EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'media_type' => $type,
            'retrieval_status' => MediaRetrievalStatus::Ready,
            'storage_path' => $path,
        ]);

        Storage::disk('rustfs')->put('ok.jpg', self::JPEG.str_repeat('a', 100));
        Storage::disk('rustfs')->put('anim.gif', self::GIF.str_repeat('a', 100));
        Storage::disk('rustfs')->put('big.jpg', self::JPEG.str_repeat('a', 4 * 1024 * 1024));
        Storage::disk('rustfs')->put('clip.mp4', 'video-bytes');
        $media('ok.jpg');
        $media('anim.gif');
        $media('big.jpg');
        $media('missing.jpg');
        $media('clip.mp4', MediaType::Video);

        $result = TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertCount(1, $result['images']);
        $this->assertSame('image/jpeg', $result['images'][0]['content_type']);
        $this->assertSame(base64_encode(self::JPEG.str_repeat('a', 100)), $result['images'][0]['base64']);
        $this->assertEqualsCanonicalizing(['unsupported_type' => 1, 'oversize' => 1, 'missing' => 1], $result['skipped']);
        $this->assertSystemLogged('ai.clef_shadow.image_skipped');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_respects_total_budget(): void
    {
        config(['ai.clef.max_total_image_bytes' => 250, 'ai.clef.max_images' => 4]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        foreach (range(1, 5) as $i) {
            Storage::disk('rustfs')->put("f{$i}.jpg", self::JPEG.str_repeat('a', 100));
            EventMediaContext::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $evaluation->normalized_event_id,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'storage_path' => "f{$i}.jpg",
            ]);
        }

        $result = TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertCount(2, $result['images']);
        $this->assertSame(['total_budget' => 3], $result['skipped']);
    }

    public function test_never_sends_more_than_max_images(): void
    {
        config(['ai.clef.max_images' => 4]);
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        foreach (range(1, 6) as $i) {
            Storage::disk('rustfs')->put("g{$i}.jpg", self::JPEG.str_repeat('a', 10));
            EventMediaContext::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $evaluation->normalized_event_id,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'storage_path' => "g{$i}.jpg",
            ]);
        }

        $result = TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertCount(4, $result['images']);
        $this->assertSame(['max_images' => 2], $result['skipped']);
    }

    public function test_does_not_download_images_beyond_the_cap(): void
    {
        config(['ai.clef.max_images' => 4]);
        $downloads = 0;
        $this->app->instance(ObjectStorage::class, new class($downloads) extends RustFsObjectStorage
        {
            public function __construct(private int &$downloads) {}

            public function get(string $path): ?string
            {
                $this->downloads++;

                return parent::get($path);
            }
        });
        $team = Team::factory()->create();
        $evaluation = $this->makeEvaluation($team);

        foreach (range(1, 6) as $i) {
            Storage::disk('rustfs')->put("h{$i}.jpg", self::JPEG.str_repeat('a', 10));
            EventMediaContext::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $evaluation->normalized_event_id,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'storage_path' => "h{$i}.jpg",
            ]);
        }

        TenantContext::for($team->id, fn () => app(ClefImageLoader::class)->forEvent($evaluation->id, $evaluation->normalized_event_id));

        $this->assertSame(4, $downloads);
    }
}
