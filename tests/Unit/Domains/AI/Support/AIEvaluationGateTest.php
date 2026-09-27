<?php

namespace Tests\Unit\Domains\AI\Support;

use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
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

    public function test_skips_event_type_in_skip_list_regardless_of_category(): void
    {
        $gate = new AIEvaluationGate([], ['geofence_exit', 'unmapped']);

        $this->assertFalse($gate->shouldEvaluate($this->eventWithType('geofence_exit', 'operational')));
        $this->assertFalse($gate->shouldEvaluate($this->eventWithType('unmapped', null)));
        $this->assertTrue($gate->shouldEvaluate($this->eventWithType('panic_button', 'emergency')));
    }

    public function test_default_skip_event_types_come_from_config(): void
    {
        $gate = new AIEvaluationGate([]);

        foreach (['geofence_entry', 'geofence_exit', 'vehicle_idle', 'driving_context', 'defensive_driving', 'no_seatbelt', 'hos_violation', 'smoking_drinking'] as $code) {
            $this->assertFalse($gate->shouldEvaluate($this->eventWithType($code, null)), $code);
        }

        foreach (['panic_button', 'collision', 'tampering', 'after_hours_movement', 'suspicious_stop', 'unmapped'] as $code) {
            $this->assertTrue($gate->shouldEvaluate($this->eventWithType($code, null)), $code);
        }
    }

    private function eventWithType(string $typeCode, ?string $categoryCode): NormalizedEvent
    {
        $event = new NormalizedEvent;
        $event->setRelation('eventType', new EventType(['code' => $typeCode]));
        $event->setRelation('eventCategory', $categoryCode !== null ? new EventCategory(['code' => $categoryCode]) : null);

        return $event;
    }

    private function eventWithCategory(string $code): NormalizedEvent
    {
        $event = new NormalizedEvent;
        $event->setRelation('eventCategory', new EventCategory(['code' => $code]));

        return $event;
    }
}
