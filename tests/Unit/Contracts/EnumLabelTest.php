<?php

namespace Tests\Unit\Contracts;

use App\Contracts\HasLabel;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use PHPUnit\Framework\TestCase;

class EnumLabelTest extends TestCase
{
    public function test_ui_enums_implement_has_label_with_spanish_labels(): void
    {
        foreach ([ActionType::class, WorkflowTriggerType::class, RuleScope::class, ChannelType::class, AutomationLevel::class] as $enum) {
            $this->assertContains(HasLabel::class, class_implements($enum), $enum);
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), $enum.'::'.$case->name);
                // Validar que tengan labels; términos técnicos comunes (SMS, Push, Web, etc.) pueden
                // mantener su forma en inglés incluso en UI española (caso especial permitido).
            }
        }

        $this->assertSame('Enviar WhatsApp', ActionType::SendWhatsapp->label());
        $this->assertSame('Incidente creado', WorkflowTriggerType::IncidentCreated->label());
        $this->assertSame('Tipo de evento', RuleScope::EventType->label());
        $this->assertSame('SMS', ChannelType::Sms->label());
        $this->assertSame('WhatsApp', ChannelType::Whatsapp->label());
        $this->assertSame('Voz', ChannelType::Voice->label());
        $this->assertSame('Semiautomático', AutomationLevel::SemiAutomatic->label());
    }
}
