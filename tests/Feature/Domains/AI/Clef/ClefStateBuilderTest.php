<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefStateBuilder;
use Tests\TestCase;

class ClefStateBuilderTest extends TestCase
{
    public function test_strips_other_models_media_verdicts_and_operator_feedback(): void
    {
        $state = (new ClefStateBuilder)->fromSnapshot([
            'normalized_event' => ['type_code' => 'panic_button'],
            'media_assessments' => [['result' => 'confirms_event']],
            'recent_history' => ['similar_events_24h' => 3, 'operator_feedback' => ['verdicts' => ['false_positive']]],
            'telemetry' => ['speed_kph' => 0],
        ]);

        $this->assertArrayNotHasKey('media_assessments', $state);
        $this->assertSame(['similar_events_24h' => 3], $state['recent_history']);
        $this->assertSame(['speed_kph' => 0], $state['telemetry']);
        $this->assertSame(['type_code' => 'panic_button'], $state['normalized_event']);
    }

    public function test_snapshot_without_those_keys_passes_through(): void
    {
        $this->assertSame(['telemetry' => []], (new ClefStateBuilder)->fromSnapshot(['telemetry' => []]));
    }
}
