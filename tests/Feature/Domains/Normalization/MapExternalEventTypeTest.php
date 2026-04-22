<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\MapExternalEventType;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapExternalEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationProvider $provider;

    private EventType $panicType;

    private EventType $speedingType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->medium()->create();

        $emergencyCategory = EventCategory::factory()->emergency()->create();
        $criticalSeverity = EventSeverity::factory()->critical()->create();

        $this->panicType = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $emergencyCategory->id,
            'default_severity_id' => $criticalSeverity->id,
        ]);

        $this->speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);
    }

    public function test_mapping_rule_with_conditions_applies_correctly(): void
    {
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => ['data.conditions.0.description' => 'Panic Button'],
            'mapped_event_type_id' => $this->panicType->id,
            'priority' => 10,
        ]);

        $payload = [
            'eventType' => 'AlertIncident',
            'data' => [
                'conditions' => [['description' => 'Panic Button']],
            ],
        ];

        $action = app(MapExternalEventType::class);
        $rule = $action->execute($this->provider->id, 'AlertIncident', $payload);

        $this->assertNotNull(
            $rule,
            'MapExternalEventType should find a matching rule when payload conditions match the rule conditions',
        );

        $this->assertEquals(
            $this->panicType->id,
            $rule->mapped_event_type_id,
            'Matched rule should map to panic_button event type when conditions specify Panic Button description',
        );

        $nonMatchingPayload = [
            'eventType' => 'AlertIncident',
            'data' => [
                'conditions' => [['description' => 'Some Other Alert']],
            ],
        ];

        $noRule = $action->execute($this->provider->id, 'AlertIncident', $nonMatchingPayload);

        $this->assertNull(
            $noRule,
            'MapExternalEventType should return null when payload conditions do not match any rule conditions',
        );
    }

    public function test_mapping_rule_priority_selects_highest(): void
    {
        $otherType = EventType::factory()->create([
            'code' => 'generic_alert',
            'category_id' => $this->speedingType->category_id,
            'default_severity_id' => $this->speedingType->default_severity_id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => ['data.conditions.0.description' => 'Panic Button'],
            'mapped_event_type_id' => $otherType->id,
            'priority' => 1,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => ['data.conditions.0.description' => 'Panic Button'],
            'mapped_event_type_id' => $this->panicType->id,
            'priority' => 10,
        ]);

        $payload = [
            'eventType' => 'AlertIncident',
            'data' => [
                'conditions' => [['description' => 'Panic Button']],
            ],
        ];

        $action = app(MapExternalEventType::class);
        $rule = $action->execute($this->provider->id, 'AlertIncident', $payload);

        $this->assertNotNull(
            $rule,
            'MapExternalEventType should return a rule when multiple rules match',
        );

        $this->assertEquals(
            $this->panicType->id,
            $rule->mapped_event_type_id,
            'When multiple rules match the same provider/event_type, the rule with the highest priority value should win',
        );
    }

    public function test_direct_behavior_label_mapping_without_conditions(): void
    {
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'MaxSpeed',
            'external_conditions_json' => null,
            'mapped_event_type_id' => $this->speedingType->id,
            'priority' => 0,
        ]);

        $action = app(MapExternalEventType::class);
        $rule = $action->execute($this->provider->id, 'MaxSpeed');

        $this->assertNotNull(
            $rule,
            'Behavior label mapping without conditions should match when external_event_type equals the label exactly',
        );

        $this->assertEquals(
            $this->speedingType->id,
            $rule->mapped_event_type_id,
            'MaxSpeed behavior label should map to the speeding event type via direct match (no conditions)',
        );
    }
}
