<?php

namespace Tests\Unit\Support;

use App\Support\Http\PerPage;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PerPageTest extends TestCase
{
    public function test_it_uses_the_default_when_absent(): void
    {
        $this->assertSame(25, PerPage::from(Request::create('/'), 25));
    }

    public function test_it_caps_huge_values(): void
    {
        $this->assertSame(PerPage::MAX, PerPage::from(Request::create('/', 'GET', ['per_page' => 1000000]), 25));
    }

    public function test_it_floors_zero_and_negative_values(): void
    {
        $this->assertSame(1, PerPage::from(Request::create('/', 'GET', ['per_page' => 0]), 25));
        $this->assertSame(1, PerPage::from(Request::create('/', 'GET', ['per_page' => -5]), 25));
    }
}
