<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\MediaCaptureContext;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaAssessmentInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_excludes_storage_path_and_team_id(): void
    {
        $input = new MediaAssessmentInput(
            teamId: 7,
            evaluationId: 10,
            mediaContextId: 20,
            mediaType: MediaType::Image,
            assessmentType: MediaAssessmentType::VisualValidation,
            storagePath: 'teams/7/events/1/media/still.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 100,
            durationSeconds: null,
            mediaMetadata: [],
            eventContext: [],
            cameraSide: 'driver',
            captureOffsetSeconds: -30,
        );

        $payload = $input->toArray();

        $this->assertArrayNotHasKey('storage_path', $payload);
        $this->assertArrayNotHasKey('team_id', $payload);
        $this->assertSame('driver', $payload['camera_side']);
        $this->assertSame(-30, $payload['capture_offset_seconds']);
        // Siguen disponibles para resolver el adjunto.
        $this->assertSame('teams/7/events/1/media/still.jpg', $input->storagePath);
        $this->assertSame(7, $input->teamId);
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function cameraSides(): array
    {
        return [
            'road facing input' => [['input' => 'dashcamRoadFacing'], 'road'],
            'forward facing input' => [['input' => 'dashcamForwardFacing'], 'road'],
            'driver facing input' => [['input' => 'dashcamDriverFacing'], 'driver'],
            'inward facing input' => [['input' => 'dashcamInwardFacing'], 'driver'],
            'legacy forward url key' => [['source_url_key' => 'downloadForwardVideoUrl'], 'road'],
            'legacy inward url key' => [['source_url_key' => 'downloadTrackedInwardVideoUrl'], 'driver'],
            'camera role front' => [['camera_role' => 'front'], 'road'],
            'unknown' => [['input' => 'auxCamera1'], null],
            'empty' => [[], null],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    #[DataProvider('cameraSides')]
    public function test_camera_side_is_derived_from_media_metadata(array $metadata, ?string $expected): void
    {
        $this->assertSame($expected, MediaCaptureContext::cameraSide($metadata));
    }

    public function test_capture_offset_prefers_still_offset_then_capture_timestamp(): void
    {
        $occurredAt = Carbon::parse('2026-09-25 10:00:00', 'UTC');

        $this->assertSame(-600, MediaCaptureContext::captureOffsetSeconds(['offset_seconds' => -600], $occurredAt));
        $this->assertSame(45, MediaCaptureContext::captureOffsetSeconds(['start_time' => '2026-09-25T10:00:45Z'], $occurredAt));
        $this->assertNull(MediaCaptureContext::captureOffsetSeconds([], $occurredAt));
        $this->assertNull(MediaCaptureContext::captureOffsetSeconds(['start_time' => '2026-09-25T10:00:45Z'], null));
    }

    public function test_multimodal_pipeline_sends_event_type_severity_time_and_camera_side(): void
    {
        $this->seed(AIMeterSeeder::class);

        $team = Team::factory()->create();
        $severity = EventSeverity::factory()->create(['code' => 'critical']);
        $type = EventType::factory()->create(['code' => 'panic_button', 'name' => 'Botón de pánico', 'default_severity_id' => $severity->id]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $type->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => Carbon::parse('2026-09-25 10:00:00', 'UTC'),
        ]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Snapshot,
            'metadata_json' => ['input' => 'dashcamDriverFacing', 'offset_seconds' => -120],
        ]);

        $agent = app(MediaAssessmentAgent::class);

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        $this->assertCount(1, $agent->receivedInputs);
        $payload = $agent->receivedInputs[0]->toArray();

        $this->assertSame('panic_button', $payload['event_context']['event_type_code']);
        $this->assertSame('Botón de pánico', $payload['event_context']['event_type_name']);
        $this->assertSame('critical', $payload['event_context']['severity']);
        $this->assertSame('2026-09-25T10:00:00+00:00', $payload['event_context']['occurred_at']);
        $this->assertSame('driver', $payload['camera_side']);
        $this->assertSame(-120, $payload['capture_offset_seconds']);
    }
}
