<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use Tests\TestCase;

class ClefQuestionSchemaTest extends TestCase
{
    public function test_classification_options_are_valid_enum_cases(): void
    {
        $criteria = ClefQuestionSchema::for(false)['classification']['criteria'];

        $this->assertSame(ClefQuestionSchema::CLASSIFICATIONS, array_keys($criteria));
        foreach (array_keys($criteria) as $option) {
            $this->assertNotNull(EventClassification::tryFrom($option));
        }
    }

    public function test_text_only_schema_has_no_media_questions(): void
    {
        $this->assertSame(['classification', 'severity', 'needs_human_now'], array_keys(ClefQuestionSchema::for(false)));
    }

    public function test_image_schema_adds_media_result_signals_and_person_count(): void
    {
        $questions = ClefQuestionSchema::for(true);

        foreach (MediaAssessmentResult::cases() as $case) {
            $this->assertArrayHasKey($case->value, $questions['media_result']['criteria']);
        }
        foreach (ClefQuestionSchema::MEDIA_SIGNALS as $signal) {
            $this->assertSame(['si', 'no', 'no_visible'], array_keys($questions[$signal]['criteria']));
        }
        $this->assertSame(['0', '1', '2', '3_o_mas'], array_map('strval', array_keys($questions['persons_visible']['criteria'])));
        $this->assertLessThanOrEqual(64, count($questions));
    }

    public function test_severity_has_five_ordered_levels(): void
    {
        $this->assertCount(ClefQuestionSchema::SEVERITY_LEVELS, ClefQuestionSchema::for(false)['severity']['criteria']);
    }
}
