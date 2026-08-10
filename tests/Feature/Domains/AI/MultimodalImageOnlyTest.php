<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultimodalImageOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_and_audio_are_not_sent_to_the_model(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_mode' => EvaluationMode::AiText,
        ]);

        $image = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Image,
        ]);
        $video = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Video,
        ]);
        $audio = EventMediaContext::factory()->audio()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        $evaluated = app(EvaluateEventMultimodally::class)->execute(
            $evaluation,
            collect([$image, $video, $audio]),
        );

        $this->assertCount(1, $evaluated, 'Se evaluó más de una pieza: video/audio no fueron filtrados');
        $this->assertSame(1, AIMediaAssessment::where('evaluation_id', $evaluation->id)->count());
        $this->assertDatabaseHas('ai_media_assessments', [
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $image->id,
        ]);
        $this->assertDatabaseMissing('ai_media_assessments', [
            'event_media_context_id' => $video->id,
        ]);
        $this->assertDatabaseMissing('ai_media_assessments', [
            'event_media_context_id' => $audio->id,
        ]);
    }
}
