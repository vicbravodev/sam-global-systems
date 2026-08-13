<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_is_e164_accepts_valid_and_rejects_invalid(): void
    {
        $this->assertTrue(PhoneNumber::isE164('+5215555550123'));
        $this->assertTrue(PhoneNumber::isE164('+14155551234'));
        $this->assertFalse(PhoneNumber::isE164(''));
        $this->assertFalse(PhoneNumber::isE164('4155551234'));
        $this->assertFalse(PhoneNumber::isE164('+12 34'));
        $this->assertFalse(PhoneNumber::isE164('+1234567'));
        $this->assertFalse(PhoneNumber::isE164('+1234567890123456'));
    }

    public function test_normalize_strips_separators_and_returns_e164_or_null(): void
    {
        $this->assertSame('+14155551234', PhoneNumber::normalize('+1 (415) 555-1234'));
        $this->assertSame('+5215555550123', PhoneNumber::normalize(' +52 1 5555 550123 '));
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('415-555-1234'));
        $this->assertNull(PhoneNumber::normalize('not a phone'));
    }
}
