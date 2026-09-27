<?php

namespace Tests\Feature\Domains\Context;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Jobs\ExtractVideoFramesJob;
use App\Domains\Context\Listeners\ExtractVideoFramesOnMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\VideoFrameExtractor;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\FileObject;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ExtractVideoFramesJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    /** @var list<array<int, string>> */
    private array $frameCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        $this->team = Team::factory()->create();
    }

    /**
     * Fake de ffmpeg: `-version` ok, `-i` sin salida imprime la duración y la
     * extracción escribe un JPEG falso en la ruta de salida (último argumento).
     */
    private function fakeFfmpeg(string $duration = '00:00:20.00'): void
    {
        Process::fake(function (PendingProcess $process) use ($duration) {
            $command = (array) $process->command;

            if (in_array('-version', $command, true)) {
                return Process::result('ffmpeg version 7.1');
            }

            if (in_array('-frames:v', $command, true)) {
                $this->frameCommands[] = $command;
                File::put(end($command), 'jpeg-bytes-'.count($this->frameCommands));

                return Process::result();
            }

            return Process::result(
                errorOutput: "Input #0, mov,mp4\n  Duration: {$duration}, start: 0.000000, bitrate: 1200 kb/s",
                exitCode: 1,
            );
        });
    }

    private function makeClip(?Team $team = null, array $attributes = []): EventMediaContext
    {
        $team ??= $this->team;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $path = sprintf('teams/%d/events/%d/media/media-0-road-facing.mp4', $team->id, $event->id);

        Storage::disk('rustfs')->put($path, 'fake-mp4-bytes');

        return EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Clip,
            'media_role' => MediaRole::PrimaryEvidence,
            'storage_path' => $path,
            'mime_type' => 'video/mp4',
            'duration_seconds' => null,
            'captured_at' => now(),
            'metadata_json' => ['input' => 'dashcamRoadFacing', 'camera_role' => 'road'],
            ...$attributes,
        ]);
    }

    private function runJob(EventMediaContext $clip): void
    {
        (new ExtractVideoFramesJob($clip->id, $clip->team_id))->handle(
            app(VideoFrameExtractor::class),
            app(ObjectStorage::class),
            app(RefreshContextMediaSnapshot::class),
        );
    }

    private function framesOf(EventMediaContext $clip)
    {
        return EventMediaContext::withoutGlobalScopes()
            ->where('team_id', $clip->team_id)
            ->where('normalized_event_id', $clip->normalized_event_id)
            ->where('media_type', MediaType::Snapshot)
            ->orderBy('id')
            ->get();
    }

    public function test_extracts_three_keyframes_as_snapshots_next_to_the_clip(): void
    {
        Event::fake([EventMediaAvailable::class]);
        $this->fakeFfmpeg('00:00:20.00');
        $clip = $this->makeClip();

        $this->runJob($clip);

        $frames = $this->framesOf($clip);
        $this->assertCount(3, $frames);

        $this->assertSame([2.0, 10.0, 18.0], $frames->map(fn ($f) => (float) $f->metadata_json['offset_seconds'])->all());

        $first = $frames->first();
        $this->assertSame(
            sprintf('teams/%d/events/%d/media/frames/media-0-road-facing-frame-0.jpg', $this->team->id, $clip->normalized_event_id),
            $first->storage_path,
        );
        $this->assertSame('image/jpeg', $first->mime_type);
        $this->assertSame('video_frame', $first->metadata_json['source']);
        $this->assertSame($clip->id, $first->metadata_json['parent_media_context_id']);
        $this->assertSame('dashcamRoadFacing', $first->metadata_json['input']);
        $this->assertSame('road', $first->metadata_json['camera_role']);
        Storage::disk('rustfs')->assertExists($first->storage_path);

        $fileObject = FileObject::withoutGlobalScopes()->findOrFail($first->file_object_id);
        $this->assertSame($this->team->id, (int) $fileObject->team_id);
        $this->assertSame($first->id, (int) $fileObject->fileable_id);

        Event::assertDispatchedTimes(EventMediaAvailable::class, 3);

        // Escalado a máx. 1280 px de ancho, JPEG, un solo fotograma por seek.
        $this->assertContains("scale='min(1280,iw)':-2", $this->frameCommands[0]);
        $this->assertSame('2.000', $this->frameCommands[0][array_search('-ss', $this->frameCommands[0], true) + 1]);
    }

    public function test_rerunning_does_not_duplicate_frames_or_call_ffmpeg_again(): void
    {
        Event::fake([EventMediaAvailable::class]);
        $this->fakeFfmpeg();
        $clip = $this->makeClip();

        $this->runJob($clip);
        $this->runJob($clip);

        $this->assertCount(3, $this->framesOf($clip));
        $this->assertCount(3, $this->frameCommands, 'La segunda corrida no vuelve a extraer.');
        Event::assertDispatchedTimes(EventMediaAvailable::class, 3);
    }

    public function test_known_duration_skips_probe(): void
    {
        Event::fake([EventMediaAvailable::class]);
        $this->fakeFfmpeg('00:00:99.00');
        $clip = $this->makeClip(attributes: ['duration_seconds' => 10]);

        $this->runJob($clip);

        $this->assertSame([1.0, 5.0, 9.0], $this->framesOf($clip)->map(fn ($f) => (float) $f->metadata_json['offset_seconds'])->all());
    }

    public function test_missing_ffmpeg_logs_warning_and_is_a_no_op(): void
    {
        Event::fake([EventMediaAvailable::class]);
        Log::spy();
        Process::fake(['*' => Process::result(errorOutput: 'not found', exitCode: 127)]);
        $clip = $this->makeClip();

        $this->runJob($clip);

        $this->assertCount(0, $this->framesOf($clip));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'ffmpeg no disponible'))->once();
        Event::assertNotDispatched(EventMediaAvailable::class);
    }

    public function test_temp_files_are_cleaned_up(): void
    {
        Event::fake([EventMediaAvailable::class]);
        $this->fakeFfmpeg();
        $clip = $this->makeClip();

        $this->runJob($clip);

        $inputPath = $this->frameCommands[0][array_search('-i', $this->frameCommands[0], true) + 1];
        $this->assertFileDoesNotExist($inputPath);
        $this->assertDirectoryDoesNotExist(dirname($inputPath));
    }

    public function test_listener_dispatches_only_for_clips(): void
    {
        Bus::fake([ExtractVideoFramesJob::class]);

        $clip = $this->makeClip();
        $still = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $clip->normalized_event_id,
            'media_type' => MediaType::Snapshot,
        ]);
        $event = NormalizedEvent::withoutGlobalScopes()->findOrFail($clip->normalized_event_id);

        $listener = new ExtractVideoFramesOnMediaAvailable;
        $listener->handle(new EventMediaAvailable($still, $event));
        $listener->handle(new EventMediaAvailable($clip, $event));

        Bus::assertDispatchedTimes(ExtractVideoFramesJob::class, 1);
        Bus::assertDispatched(ExtractVideoFramesJob::class, fn (ExtractVideoFramesJob $job) => $job->mediaContextId === $clip->id
            && $job->teamId === $this->team->id);
    }

    public function test_job_with_mismatched_team_does_nothing(): void
    {
        Event::fake([EventMediaAvailable::class]);
        $this->fakeFfmpeg();
        $victim = Team::factory()->create();
        $foreignClip = $this->makeClip($victim);

        $this->assertNoTenantLeak($this->team, fn () => (new ExtractVideoFramesJob($foreignClip->id, $this->team->id))->handle(
            app(VideoFrameExtractor::class),
            app(ObjectStorage::class),
            app(RefreshContextMediaSnapshot::class),
        ));

        $this->assertCount(0, $this->framesOf($foreignClip));
        $this->assertSame([], $this->frameCommands);
    }
}
