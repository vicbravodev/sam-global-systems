<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosUrgency;
use Tests\TestCase;

/** Tests\TestCase (no PHPUnit pelón): instancia modelos Eloquent con casts. */
class HosUrgencyTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function state(array $attributes = []): HosDriverState
    {
        return new HosDriverState($attributes + [
            'duty_status' => HosDutyStatus::Driving,
            'break_remaining_s' => 28800,
            'drive_remaining_s' => 39600,
            'shift_remaining_s' => 50400,
            'cycle_remaining_s' => 252000,
            'violation_s' => 0,
        ]);
    }

    public function test_a_violation_wins_over_everything(): void
    {
        $this->assertSame(HosUrgency::VIOLATION, HosUrgency::level($this->state(['violation_s' => 60, 'duty_status' => HosDutyStatus::OffDuty]), [], false));
        $this->assertSame(HosUrgency::VIOLATION, HosUrgency::level($this->state(), [HosSituation::Violation], false));
    }

    public function test_at_the_limit_only_while_working_or_once_escalated(): void
    {
        $this->assertSame(HosUrgency::AT_LIMIT, HosUrgency::level($this->state(['break_remaining_s' => 0]), [HosSituation::BreakDue], false));
        // Parado con el manejo en 0: está descansando, no en el límite.
        $this->assertSame(HosUrgency::OK, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0]), [], false));
        $this->assertSame(HosUrgency::AT_LIMIT, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty]), [HosSituation::DriveLimit], true));
    }

    public function test_an_open_warning_but_not_a_rest_complete(): void
    {
        $this->assertSame(HosUrgency::WARNING, HosUrgency::level($this->state(['break_remaining_s' => 900]), [HosSituation::BreakDue], false));
        $this->assertSame(HosUrgency::OK, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty]), [HosSituation::RestComplete], false));
    }

    public function test_remaining_and_sort_seconds(): void
    {
        $this->assertSame(900, HosUrgency::minRemaining($this->state(['break_remaining_s' => 900])));
        $this->assertNull(HosUrgency::minRemaining(null));
        $this->assertSame(900, HosUrgency::sortSeconds($this->state(['break_remaining_s' => 900])));
        $this->assertNull(HosUrgency::sortSeconds($this->state(['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0])));
        $this->assertLessThan(HosUrgency::rank(HosUrgency::OK), HosUrgency::rank(HosUrgency::VIOLATION));
    }
}
