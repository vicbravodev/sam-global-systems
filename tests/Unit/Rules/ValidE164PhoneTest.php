<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidE164Phone;
use PHPUnit\Framework\TestCase;

class ValidE164PhoneTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        $failed = false;
        (new ValidE164Phone)->validate('phone', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_accepts_valid_e164(): void
    {
        $this->assertFalse($this->fails('+5215555550123'));
    }

    public function test_rejects_malformed(): void
    {
        $this->assertTrue($this->fails(''));
        $this->assertTrue($this->fails('4155551234'));
        $this->assertTrue($this->fails('+12 34'));
        $this->assertTrue($this->fails('+1234567'));
        $this->assertTrue($this->fails('+1234567890123456'));
    }
}
