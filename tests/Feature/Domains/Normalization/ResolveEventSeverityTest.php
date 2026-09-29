<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Normalization\Actions\ResolveEventSeverity;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ResolveEventSeverityTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_severity_falls_back_to_type_default(): void
    {
        $highSeverity = EventSeverity::factory()->high()->create();
        $category = EventCategory::factory()->safety()->create();

        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => $highSeverity->id,
        ]);

        $rule = EventMappingRule::factory()->create([
            'mapped_event_type_id' => $eventType->id,
            'mapped_severity_id' => null,
        ]);

        $action = app(ResolveEventSeverity::class);
        $resolved = $action->execute($rule, $eventType);

        $this->assertEquals(
            $highSeverity->id,
            $resolved->id,
            'When mapping rule has no severity override, severity should fall back to the event type default_severity_id',
        );

        $c = $this->assertSystemLogged('normalization.severity.resolved');
        $this->assertSame('type_default', $c['calc']['severity_source']);
        $this->assertSame($rule->id, $c['input']['mapping_rule_id']);
        $this->assertSame('high', $c['result']['severity_code']);
    }

    public function test_severity_falls_back_to_medium_when_no_default(): void
    {
        EventSeverity::factory()->medium()->create();
        $category = EventCategory::factory()->safety()->create();

        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => null,
        ]);

        $rule = EventMappingRule::factory()->create([
            'mapped_event_type_id' => $eventType->id,
            'mapped_severity_id' => null,
        ]);

        $action = app(ResolveEventSeverity::class);
        $resolved = $action->execute($rule, $eventType);

        $this->assertEquals(
            'medium',
            $resolved->code,
            'When neither mapping rule nor event type defines severity, the system should fall back to medium severity',
        );

        $c = $this->assertSystemLogged('normalization.severity.resolved');
        $this->assertSame('medium_fallback', $c['calc']['severity_source']);
        $this->assertSame('medium', $c['result']['severity_code']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_severity_uses_rule_override_when_set(): void
    {
        $criticalSeverity = EventSeverity::factory()->critical()->create();
        $mediumSeverity = EventSeverity::factory()->medium()->create();
        $category = EventCategory::factory()->safety()->create();

        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => $mediumSeverity->id,
        ]);

        $rule = EventMappingRule::factory()->create([
            'mapped_event_type_id' => $eventType->id,
            'mapped_severity_id' => $criticalSeverity->id,
        ]);

        $action = app(ResolveEventSeverity::class);
        $resolved = $action->execute($rule, $eventType);

        $this->assertEquals(
            $criticalSeverity->id,
            $resolved->id,
            'When mapping rule has a severity override, it should take precedence over event type default',
        );

        $c = $this->assertSystemLogged('normalization.severity.resolved');
        $this->assertSame('rule_override', $c['calc']['severity_source']);
        $this->assertSame('critical', $c['result']['severity_code']);
    }
}
