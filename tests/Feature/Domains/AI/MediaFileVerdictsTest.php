<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\MediaFileVerdicts;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaFileVerdictsTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_assessment_per_media_then_the_most_telling_across_a_clip(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $media = fn (): EventMediaContext => EventMediaContext::factory()->create(['team_id' => $teamId, 'normalized_event_id' => $event->id]);
        [$clip, $frameA, $frameB, $photo] = [$media(), $media(), $media(), $media()];

        $v1 = AIEventEvaluation::factory()->create(['team_id' => $teamId, 'normalized_event_id' => $event->id]);
        $v2 = AIEventEvaluation::factory()->create(['team_id' => $teamId, 'normalized_event_id' => $event->id, 'evaluation_version' => 2]);

        $assess = fn (AIEventEvaluation $eval, EventMediaContext $m, string $result, string $at): AIMediaAssessment => AIMediaAssessment::factory()->create([
            'evaluation_id' => $eval->id, 'event_media_context_id' => $m->id, 'result' => $result, 'assessed_at' => $at,
        ]);

        // The photo was re-assessed: v2 supersedes v1 even if v1 was "stronger".
        $assess($v1, $photo, 'contradicts_event', '2026-09-30 10:00:00');
        $latestPhoto = $assess($v2, $photo, 'inconclusive', '2026-09-30 10:05:00');
        // One frame of the clip saw something.
        $assess($v1, $frameA, 'inconclusive', '2026-09-30 10:00:00');
        $seen = $assess($v1, $frameB, 'confirms_event', '2026-09-30 10:00:01');

        $verdicts = MediaFileVerdicts::forFiles(AIMediaAssessment::query()->get(), [
            $photo->id => [$photo->id],
            $clip->id => [$clip->id, $frameA->id, $frameB->id],
            999 => [999],
        ]);

        $this->assertSame($latestPhoto->id, $verdicts[$photo->id]->id);
        $this->assertSame($seen->id, $verdicts[$clip->id]->id);
        $this->assertArrayNotHasKey(999, $verdicts);
    }
}
