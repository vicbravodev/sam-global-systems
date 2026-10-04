<?php

namespace Tests\Unit\Domains\Normalization;

use App\Domains\Normalization\Enums\SamsaraAlertTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SamsaraAlertTriggerTest extends TestCase
{
    public function test_trigger_ids_are_read_from_every_condition(): void
    {
        $payload = ['data' => ['conditions' => [
            ['triggerId' => 5016],
            ['description' => 'sin id'],
            ['triggerId' => '1034'],
            ['triggerId' => 'abc'],
            ['triggerId' => 10.5],
            ['triggerId' => -3],
        ]]];

        $this->assertSame([5016, 1034], SamsaraAlertTrigger::fromPayload($payload));
    }

    public function test_payload_without_conditions_has_no_trigger_ids(): void
    {
        $this->assertSame([], SamsaraAlertTrigger::fromPayload([]));
        $this->assertSame([], SamsaraAlertTrigger::fromPayload(['data' => ['conditions' => 'x']]));
        $this->assertSame([], SamsaraAlertTrigger::fromPayload(['data' => ['conditions' => [['triggerId' => null]]]]));
    }

    /**
     * @return array<string, array{list<int>, string}>
     */
    public static function classifications(): array
    {
        return [
            'nada legible' => [[], SamsaraAlertTrigger::CLASS_UNREADABLE],
            'pánico' => [[1034], SamsaraAlertTrigger::CLASS_EMERGENCY],
            'geocerca' => [[5016], SamsaraAlertTrigger::CLASS_RECOGNIZED],
            'tampering' => [[1045], SamsaraAlertTrigger::CLASS_RECOGNIZED],
            'geocerca y pánico' => [[5016, 1034], SamsaraAlertTrigger::CLASS_EMERGENCY],
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    #[DataProvider('classifications')]
    public function test_classify_fails_toward_the_emergency(array $ids, string $expected): void
    {
        $this->assertSame($expected, SamsaraAlertTrigger::classify($ids));
    }

    public function test_only_the_panic_button_is_an_emergency_trigger(): void
    {
        $this->assertTrue(SamsaraAlertTrigger::PanicButton->isEmergency());
        $this->assertFalse(SamsaraAlertTrigger::TamperingDetected->isEmergency());
        $this->assertSame(1034, SamsaraAlertTrigger::PanicButton->value);
    }
}
