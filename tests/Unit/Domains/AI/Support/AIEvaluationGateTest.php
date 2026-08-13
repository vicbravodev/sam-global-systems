<?php

namespace Tests\Unit\Domains\AI\Support;

use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\NormalizedEvent;
use Tests\TestCase;

class AIEvaluationGateTest extends TestCase
{
    public function test_skips_evaluation_for_category_in_skip_list(): void
    {
        $gate = new AIEvaluationGate(['safety']);

        $this->assertFalse($gate->shouldEvaluate($this->eventWithCategory('safety')));
    }

    public function test_evaluates_category_not_in_skip_list(): void
    {
        $gate = new AIEvaluationGate(['safety']);

        $this->assertTrue($gate->shouldEvaluate($this->eventWithCategory('emergency')));
    }

    public function test_evaluates_when_event_has_no_category(): void
    {
        $gate = new AIEvaluationGate(['safety']);

        $event = new NormalizedEvent;
        $event->setRelation('eventCategory', null);

        $this->assertTrue($gate->shouldEvaluate($event));
    }

    public function test_empty_skip_list_evaluates_everything(): void
    {
        $gate = new AIEvaluationGate([]);

        $this->assertTrue($gate->shouldEvaluate($this->eventWithCategory('safety')));
    }

    private function eventWithCategory(string $code): NormalizedEvent
    {
        $event = new NormalizedEvent;
        $event->setRelation('eventCategory', new EventCategory(['code' => $code]));

        return $event;
    }
}
