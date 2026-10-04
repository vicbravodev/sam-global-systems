<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosNoticeCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HosNoticeCopyTest extends TestCase
{
    /**
     * @return array<string, array{HosNotice}>
     */
    public static function notices(): array
    {
        $cases = [];

        foreach (HosNotice::cases() as $notice) {
            $cases[$notice->value] = [$notice];
        }

        return $cases;
    }

    #[DataProvider('notices')]
    public function test_every_notice_fits_an_sms_and_has_a_spoken_version(HosNotice $notice): void
    {
        $copy = HosNoticeCopy::for($notice, 30);

        $this->assertNotSame('', $copy['subject']);
        $this->assertStringStartsWith('SAM: ', $copy['body']);
        $this->assertLessThanOrEqual(160, mb_strlen($copy['body']));
        $this->assertStringStartsWith('Hola, te llama SAM. ', $copy['spoken']);
        // La voz no lee abreviaturas ni inglés.
        $this->assertDoesNotMatchRegularExpression('/\b(min|h|break|HOS)\b/u', $copy['spoken']);
    }

    public function test_minutes_and_hours_read_naturally(): void
    {
        $this->assertStringContainsString('Te quedan 15 min para tu break obligatorio de 30 min', HosNoticeCopy::for(HosNotice::BreakLead, 15)['body']);
        $this->assertStringContainsString('Te quedan 15 minutos', HosNoticeCopy::for(HosNotice::BreakLead, 15)['spoken']);
        $this->assertStringContainsString('en 1 minuto.', HosNoticeCopy::for(HosNotice::DriveLead, 1)['spoken']);
        $this->assertStringContainsString('Te quedan 5 h en tu ciclo de 70 h', HosNoticeCopy::for(HosNotice::CycleLead, 5)['body']);
        $this->assertStringContainsString('Te quedan 1 hora en tu ciclo', HosNoticeCopy::for(HosNotice::CycleLead, 1)['spoken']);
    }

    public function test_the_notice_follows_the_situation_and_the_ladder_position(): void
    {
        $this->assertSame(HosNotice::BreakLead, HosNotice::lead(HosSituation::BreakDue));
        $this->assertSame(HosNotice::ShiftLead, HosNotice::lead(HosSituation::ShiftLimit));
        $this->assertSame(HosNotice::DriveLimit, HosNotice::limit(HosSituation::DriveLimit, insist: false));
        $this->assertSame(HosNotice::DriveInsist, HosNotice::limit(HosSituation::DriveLimit, insist: true));
        $this->assertSame(HosNotice::BreakInsist, HosNotice::limit(HosSituation::BreakDue, insist: true));
    }

    public function test_every_situation_has_a_correction_line_for_the_incident(): void
    {
        foreach (HosSituation::cases() as $situation) {
            $this->assertNotSame('', HosNoticeCopy::corrected($situation));
            $this->assertStringEndsWith('.', HosNoticeCopy::corrected($situation));
        }

        $this->assertSame('El chofer ya tomó su break de 30 min.', HosNoticeCopy::corrected(HosSituation::BreakDue));
    }
}
