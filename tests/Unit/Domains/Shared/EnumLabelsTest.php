<?php

namespace Tests\Unit\Domains\Shared;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Drivers\Enums\DriverStatus;
use PHPUnit\Framework\TestCase;

final class EnumLabelsTest extends TestCase
{
    public function test_decision_outcome_labels_are_spanish(): void
    {
        $this->assertSame('Revisión humana', DecisionOutcomeCode::RequireHumanReview->label());
        $this->assertSame('Solo registro', DecisionOutcomeCode::LogOnly->label());
        $this->assertSame('Incidente', DecisionOutcomeCode::Incident->label());
    }

    public function test_event_classification_labels_are_spanish(): void
    {
        $this->assertSame('Sin determinar', EventClassification::Unclear->label());
        $this->assertSame('Evento real', EventClassification::RealEvent->label());
        $this->assertSame('Falso positivo', EventClassification::FalsePositive->label());
    }

    public function test_driver_status_labels_are_spanish(): void
    {
        $this->assertSame('Activo', DriverStatus::Active->label());
        $this->assertSame('En revisión', DriverStatus::UnderReview->label());
    }

    public function test_asset_category_labels_are_spanish(): void
    {
        $this->assertSame('Vehículo', AssetCategory::Vehicle->label());
        $this->assertSame('Dispositivo GPS', AssetCategory::GpsDevice->label());
    }

    public function test_every_case_has_a_non_empty_label(): void
    {
        foreach ([
            ...DecisionOutcomeCode::cases(),
            ...EventClassification::cases(),
            ...DriverStatus::cases(),
            ...AssetCategory::cases(),
        ] as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame($case->value, $case->label());
        }
    }
}
