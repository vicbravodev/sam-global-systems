<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosLadderPlanner;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HosLadderPlannerTest extends TestCase
{
    private const string NOW = '2026-10-04 12:00:00';

    private function config(array $overrides = []): HosMonitoringConfig
    {
        $defaults = require __DIR__.'/../../../../config/hos.php';

        return HosMonitoringConfig::fromArray($overrides, $defaults['defaults']);
    }

    private function reading(?string $status = 'driving', ?int $break = 28800, ?int $drive = 39600, ?int $shift = 50400, ?int $cycle = 252000): HosClockReading
    {
        return new HosClockReading('1', '100', $status, $break, $drive, $shift, $cycle, 0);
    }

    private function plan(
        HosSituation $situation,
        int $step,
        ?HosClockReading $current,
        ?string $nextNudgeAt = null,
        string $at = self::NOW,
        bool $escalated = false,
        string $openedAt = '2026-10-04 11:50:00',
        array $config = [],
    ): HosLadderDecision {
        return (new HosLadderPlanner)->plan(
            $situation,
            $step,
            $nextNudgeAt !== null ? CarbonImmutable::parse($nextNudgeAt) : null,
            CarbonImmutable::parse($openedAt),
            $escalated,
            $current,
            $this->config($config),
            CarbonImmutable::parse($at),
        );
    }

    private function at(?CarbonImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s');
    }

    public function test_warnings_before_the_limit_go_to_the_driver_app_once_per_threshold(): void
    {
        $first = $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 26 * 60));
        $this->assertSame(HosLadderMove::Notify, $first->move);
        $this->assertSame('lead_notice', $first->reason);
        $this->assertSame(0, $first->step);
        $this->assertSame(1, $first->nextStep);
        $this->assertSame(['samsara_driver_app'], $first->channels);
        $this->assertSame(HosNotice::BreakLead, $first->notice);
        $this->assertSame(26, $first->amount);

        $this->assertSame(HosLadderMove::Wait, $this->plan(HosSituation::BreakDue, 1, $this->reading(break: 20 * 60))->move);

        $second = $this->plan(HosSituation::BreakDue, 1, $this->reading(break: 14 * 60 + 30));
        $this->assertSame(1, $second->step);
        $this->assertSame(15, $second->amount);
        $this->assertSame(2, $second->nextStep);
    }

    public function test_a_late_first_reading_skips_straight_to_the_most_urgent_warning(): void
    {
        $decision = $this->plan(HosSituation::DriveLimit, 0, $this->reading(drive: 10 * 60));

        $this->assertSame(1, $decision->step);
        $this->assertSame(HosNotice::DriveLead, $decision->notice);
    }

    public function test_reaching_the_limit_walks_the_ladder_then_escalates(): void
    {
        $atLimit = $this->reading(break: 0);

        $app = $this->plan(HosSituation::BreakDue, 2, $atLimit);
        $this->assertSame(HosLadderMove::Notify, $app->move);
        $this->assertSame('ladder_step', $app->reason);
        $this->assertSame(2, $app->step);
        $this->assertSame(['samsara_driver_app'], $app->channels);
        $this->assertSame(HosNotice::BreakLimit, $app->notice);
        $this->assertSame('2026-10-04 12:05:00', $this->at($app->nextNudgeAt));

        $notYet = $this->plan(HosSituation::BreakDue, 3, $atLimit, '2026-10-04 12:05:00', '2026-10-04 12:03:00');
        $this->assertSame(HosLadderMove::Wait, $notYet->move);
        $this->assertSame('not_due', $notYet->reason);

        $whatsapp = $this->plan(HosSituation::BreakDue, 3, $atLimit, '2026-10-04 12:05:00', '2026-10-04 12:05:00');
        $this->assertSame(['samsara_driver_app', 'whatsapp'], $whatsapp->channels);
        $this->assertSame(HosNotice::BreakInsist, $whatsapp->notice);
        $this->assertSame('2026-10-04 12:10:00', $this->at($whatsapp->nextNudgeAt));

        $voice = $this->plan(HosSituation::BreakDue, 4, $atLimit, '2026-10-04 12:10:00', '2026-10-04 12:10:30');
        $this->assertSame(['voice'], $voice->channels);
        $this->assertSame('2026-10-04 12:15:30', $this->at($voice->nextNudgeAt));

        $incident = $this->plan(HosSituation::BreakDue, 5, $atLimit, '2026-10-04 12:15:30', '2026-10-04 12:16:00');
        $this->assertSame(HosLadderMove::Escalate, $incident->move);
        $this->assertSame('ladder_exhausted', $incident->reason);
        $this->assertSame(5, $incident->step);
        $this->assertSame(6, $incident->nextStep);
        $this->assertSame([], $incident->channels);
        $this->assertNull($incident->notice);
        $this->assertNull($incident->nextNudgeAt);

        // Tras escalar, el episodio queda marcado y ya no se mueve.
        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::BreakDue, 6, $atLimit, escalated: true)->move);
    }

    public function test_a_shrunk_ladder_mid_episode_still_raises_the_pending_incident(): void
    {
        // El episodio iba en el escalón 5 (incidente pendiente con la escalera por defecto)
        // y el tenant acortó la escalera a dos escalones: se re-basa y escala igual.
        $config = ['lead_minutes' => [15], 'ladder' => [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'escalate' => 'incident'],
        ]];
        $atLimit = $this->reading(break: 0);

        $notYet = $this->plan(HosSituation::BreakDue, 5, $atLimit, '2026-10-04 12:15:30', '2026-10-04 12:10:00', config: $config);
        $this->assertSame(HosLadderMove::Wait, $notYet->move);
        $this->assertSame('not_due', $notYet->reason);

        $incident = $this->plan(HosSituation::BreakDue, 5, $atLimit, '2026-10-04 12:15:30', '2026-10-04 12:16:00', config: $config);
        $this->assertSame(HosLadderMove::Escalate, $incident->move);
        $this->assertSame('ladder_exhausted', $incident->reason);
        $this->assertSame(5, $incident->step);
        $this->assertSame(6, $incident->nextStep);
        $this->assertSame([], $incident->channels);
        $this->assertNull($incident->nextNudgeAt);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::BreakDue, 6, $atLimit, escalated: true, config: $config)->move);
    }

    public function test_reaching_the_limit_before_the_warnings_starts_the_ladder(): void
    {
        $decision = $this->plan(HosSituation::ShiftLimit, 0, $this->reading('driving', shift: 0));

        $this->assertSame(2, $decision->step);
        $this->assertSame(HosNotice::ShiftLimit, $decision->notice);
    }

    public function test_past_the_shift_limit_the_ladder_only_moves_while_driving(): void
    {
        // Con el turno de 14 h agotado, trabajar sin manejar es legal: no se insiste.
        $onDuty = $this->plan(HosSituation::ShiftLimit, 2, $this->reading('onDuty', shift: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Hold, $onDuty->move);
        $this->assertSame('not_working', $onDuty->reason);
        $this->assertSame(2, $onDuty->nextStep);
        $this->assertSame('2026-10-04 11:58:00', $this->at($onDuty->nextNudgeAt));
        $this->assertSame(HosLadderMove::Hold, $this->plan(HosSituation::ShiftLimit, 0, $this->reading('onDuty', shift: 0))->move);

        $driving = $this->plan(HosSituation::ShiftLimit, 2, $this->reading('driving', shift: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Notify, $driving->move);
        $this->assertSame(2, $driving->step);

        // Antes del límite (avisos previos) cuenta cualquier trabajo, como siempre.
        $this->assertSame(HosLadderMove::Notify, $this->plan(HosSituation::ShiftLimit, 0, $this->reading('onDuty', shift: 20 * 60))->move);
    }

    public function test_the_ladder_pauses_while_the_driver_is_not_working(): void
    {
        $stopped = $this->plan(HosSituation::BreakDue, 3, $this->reading('offDuty', break: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Hold, $stopped->move);
        $this->assertSame('not_working', $stopped->reason);
        $this->assertSame(3, $stopped->nextStep);
        $this->assertSame('2026-10-04 11:58:00', $this->at($stopped->nextNudgeAt));

        // Vuelve a manejar sin cumplir la pausa: el escalón vencido sale ese minuto.
        $resumed = $this->plan(HosSituation::BreakDue, 3, $this->reading('driving', break: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Notify, $resumed->move);
        $this->assertSame(3, $resumed->step);

        // La ventana de 14 h corre también trabajando sin manejar; el manejo no.
        $this->assertSame(HosLadderMove::Notify, $this->plan(HosSituation::ShiftLimit, 0, $this->reading('onDuty', shift: 20 * 60))->move);
        $this->assertSame(HosLadderMove::Hold, $this->plan(HosSituation::DriveLimit, 0, $this->reading('onDuty', drive: 20 * 60))->move);
    }

    public function test_without_a_reading_only_a_violation_moves(): void
    {
        $this->assertSame('no_reading', $this->plan(HosSituation::BreakDue, 2, null)->reason);
        $this->assertSame(HosLadderMove::Hold, $this->plan(HosSituation::CycleLimit, 0, null)->move);
        $this->assertSame(HosLadderMove::Escalate, $this->plan(HosSituation::Violation, 0, null)->move);
    }

    public function test_a_violation_tells_the_driver_and_escalates_once(): void
    {
        $decision = $this->plan(HosSituation::Violation, 0, $this->reading());

        $this->assertSame(HosLadderMove::Escalate, $decision->move);
        $this->assertSame('violation', $decision->reason);
        $this->assertSame(['samsara_driver_app'], $decision->channels);
        $this->assertSame(HosNotice::Violation, $decision->notice);
        $this->assertSame(1, $decision->nextStep);
        // The notice says the team was already told: the incident goes in the same cycle.
        $this->assertSame(HosLadderMove::Escalate, $decision->move);
        $this->assertSame(0, $decision->step);
        $this->assertSame('2026-10-04 12:05:00', $this->at($decision->nextNudgeAt));
    }

    public function test_a_violation_while_driving_insists_on_the_next_ladder_channels_without_a_second_incident(): void
    {
        $early = $this->plan(HosSituation::Violation, 1, $this->reading(drive: 0), '2026-10-04 12:05:00', at: '2026-10-04 12:03:00', escalated: true);
        $this->assertSame(HosLadderMove::Wait, $early->move);
        $this->assertSame('not_due', $early->reason);

        $whatsapp = $this->plan(HosSituation::Violation, 1, $this->reading(drive: 0), '2026-10-04 12:05:00', at: '2026-10-04 12:05:00', escalated: true);
        $this->assertSame(HosLadderMove::Notify, $whatsapp->move);
        $this->assertSame('violation_insist', $whatsapp->reason);
        $this->assertSame(1, $whatsapp->step);
        $this->assertSame(['samsara_driver_app', 'whatsapp'], $whatsapp->channels);
        $this->assertSame(HosNotice::Violation, $whatsapp->notice);
        $this->assertSame(2, $whatsapp->nextStep);
        $this->assertSame('2026-10-04 12:10:00', $this->at($whatsapp->nextNudgeAt));

        $voice = $this->plan(HosSituation::Violation, 2, $this->reading(drive: 0), '2026-10-04 12:10:00', at: '2026-10-04 12:10:00', escalated: true);
        $this->assertSame(HosLadderMove::Notify, $voice->move);
        $this->assertSame(['voice'], $voice->channels);
        $this->assertSame(3, $voice->nextStep);
        $this->assertNull($voice->nextNudgeAt);

        // El escalón de incidente de la escalera no aplica: el de la infracción ya existe.
        $done = $this->plan(HosSituation::Violation, 3, $this->reading(drive: 0), null, at: '2026-10-04 12:15:00', escalated: true);
        $this->assertSame(HosLadderMove::Done, $done->move);
        $this->assertSame('violation_raised', $done->reason);
    }

    public function test_violation_insistence_pauses_while_the_driver_is_not_driving(): void
    {
        foreach (['offDuty', 'onDuty', 'sleeperBed'] as $status) {
            $held = $this->plan(HosSituation::Violation, 1, $this->reading($status, drive: 0), '2026-10-04 11:58:00', escalated: true);
            $this->assertSame(HosLadderMove::Hold, $held->move, $status);
            $this->assertSame('not_working', $held->reason);
            $this->assertSame(1, $held->nextStep);
            $this->assertSame('2026-10-04 11:58:00', $this->at($held->nextNudgeAt));
        }

        $this->assertSame('no_reading', $this->plan(HosSituation::Violation, 1, null, '2026-10-04 11:58:00', escalated: true)->reason);
    }

    public function test_a_violation_already_raised_without_a_pending_step_sends_nothing(): void
    {
        // Infracción abierta por el PR 1 (escalón 1 por migración, sin escalar ni siguiente aviso).
        $decision = $this->plan(HosSituation::Violation, 1, $this->reading(drive: 0));

        $this->assertSame(HosLadderMove::Done, $decision->move);
        $this->assertSame('violation_raised', $decision->reason);
    }

    public function test_violation_insistence_skips_the_ladder_incident_entries(): void
    {
        $ladder = [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'escalate' => 'incident'],
            ['after_minutes' => 12, 'channels' => ['voice']],
        ];

        $first = $this->plan(HosSituation::Violation, 0, $this->reading(drive: 0), config: ['ladder' => $ladder]);
        $this->assertSame('2026-10-04 12:12:00', $this->at($first->nextNudgeAt));

        $voice = $this->plan(HosSituation::Violation, 1, $this->reading(drive: 0), '2026-10-04 12:12:00', at: '2026-10-04 12:12:00', escalated: true, config: ['ladder' => $ladder]);
        $this->assertSame(HosLadderMove::Notify, $voice->move);
        $this->assertSame(['voice'], $voice->channels);
        $this->assertNull($voice->nextNudgeAt);
    }

    public function test_an_escalated_episode_never_moves_again(): void
    {
        $decision = $this->plan(HosSituation::BreakDue, 6, $this->reading(break: 0), escalated: true);

        $this->assertSame(HosLadderMove::Done, $decision->move);
        $this->assertSame('escalated', $decision->reason);
    }

    public function test_cycle_notices_are_one_per_threshold_without_a_ladder(): void
    {
        $five = $this->plan(HosSituation::CycleLimit, 0, $this->reading('offDuty', cycle: 4 * 3600 + 1800));
        $this->assertSame(HosNotice::CycleLead, $five->notice);
        $this->assertSame(5, $five->amount);
        $this->assertSame(0, $five->step);
        $this->assertNull($five->nextNudgeAt);

        $this->assertSame(HosLadderMove::Wait, $this->plan(HosSituation::CycleLimit, 1, $this->reading('offDuty', cycle: 3 * 3600))->move);

        $one = $this->plan(HosSituation::CycleLimit, 1, $this->reading('driving', cycle: 50 * 60));
        $this->assertSame(1, $one->step);
        $this->assertSame(1, $one->amount);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::CycleLimit, 2, $this->reading(cycle: 10 * 60))->move);
    }

    public function test_a_cycle_at_zero_plans_no_notice_that_would_read_one_hour(): void
    {
        $decision = $this->plan(HosSituation::CycleLimit, 0, $this->reading('driving', cycle: 0));

        $this->assertSame(HosLadderMove::Wait, $decision->move);
        $this->assertNull($decision->notice);
        $this->assertNull($decision->amount);
    }

    public function test_amounts_are_the_real_remaining_time_rounded_up(): void
    {
        $this->assertSame(29, $this->plan(HosSituation::DriveLimit, 0, $this->reading(drive: 28 * 60 + 1))->amount);
        $this->assertSame(2, $this->plan(HosSituation::CycleLimit, 0, $this->reading(cycle: 3600 + 1))->amount);
    }

    public function test_rest_complete_reminds_at_15_and_30_minutes(): void
    {
        $opened = '2026-10-04 12:00:00';
        $rested = $this->reading('offDuty');

        $early = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:10:00', openedAt: $opened);
        $this->assertSame(HosLadderMove::Wait, $early->move);
        $this->assertSame('2026-10-04 12:15:00', $this->at($early->nextNudgeAt));

        $first = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:15:00', openedAt: $opened);
        $this->assertSame(HosLadderMove::Notify, $first->move);
        $this->assertSame(0, $first->step);
        $this->assertSame(HosNotice::RestComplete, $first->notice);
        $this->assertSame('2026-10-04 12:30:00', $this->at($first->nextNudgeAt));

        // Un sondeo perdido no manda dos avisos: sólo el más reciente.
        $missed = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:31:00', openedAt: $opened);
        $this->assertSame(1, $missed->step);
        $this->assertSame(2, $missed->nextStep);
        $this->assertNull($missed->nextNudgeAt);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::RestComplete, 2, $rested, at: '2026-10-04 12:34:00', openedAt: $opened)->move);
    }

    public function test_a_ladder_that_starts_later_waits_for_its_first_step(): void
    {
        $config = ['ladder' => [
            ['after_minutes' => 2, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 7, 'escalate' => 'incident'],
        ]];

        $scheduled = $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 0), config: $config);
        $this->assertSame(HosLadderMove::Wait, $scheduled->move);
        $this->assertSame('ladder_scheduled', $scheduled->reason);
        $this->assertSame(2, $scheduled->nextStep);
        $this->assertSame('2026-10-04 12:02:00', $this->at($scheduled->nextNudgeAt));

        $due = $this->plan(HosSituation::BreakDue, 2, $this->reading(break: 0), '2026-10-04 12:02:00', '2026-10-04 12:02:00', config: $config);
        $this->assertSame(HosLadderMove::Notify, $due->move);
        $this->assertSame(2, $due->step);
        $this->assertSame('2026-10-04 12:07:00', $this->at($due->nextNudgeAt));
    }

    public function test_a_ladder_without_an_incident_step_finishes_without_escalating(): void
    {
        $config = ['ladder' => [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'channels' => ['voice']],
        ]];
        $atLimit = $this->reading(break: 0);

        $last = $this->plan(HosSituation::BreakDue, 3, $atLimit, '2026-10-04 12:00:00', config: $config);
        $this->assertSame(HosLadderMove::Notify, $last->move);
        $this->assertNull($last->nextNudgeAt);

        $done = $this->plan(HosSituation::BreakDue, 4, $atLimit, config: $config);
        $this->assertSame(HosLadderMove::Done, $done->move);
        $this->assertSame('ladder_finished', $done->reason);
    }

    public function test_unknown_channels_in_the_tenant_ladder_are_ignored(): void
    {
        $config = ['ladder' => [
            ['after_minutes' => 0, 'channels' => ['carrier_pigeon', 'whatsapp']],
            ['after_minutes' => 5, 'channels' => ['carrier_pigeon']],
            ['after_minutes' => 10, 'escalate' => 'incident'],
            'basura',
        ]];

        $decision = $this->plan(HosSituation::BreakDue, 2, $this->reading(break: 0), config: $config);
        $this->assertSame(['whatsapp'], $decision->channels);
        // El escalón sin canales válidos desaparece: el siguiente es el incidente.
        $this->assertSame('2026-10-04 12:10:00', $this->at($decision->nextNudgeAt));
        // Los avisos informativos usan los canales del primer escalón.
        $this->assertSame(['whatsapp'], $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 1200), config: $config)->channels);
    }
}
