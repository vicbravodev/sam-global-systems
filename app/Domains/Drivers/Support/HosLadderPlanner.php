<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure reminder-ladder evaluation for one open HOS episode: what to do this
 * minute (spec 2026-10-04 §3.9).
 *
 * `ladder_step` is the next step to execute, and the suffix of its
 * notification `event_key` (`hos:{episode}:{step}`):
 *  - break/drive/shift: steps 0..L-1 are the warnings before the limit
 *    (`lead_minutes` > 0, largest first, only the most urgent crossed one is
 *    sent); reaching the limit starts the ladder at step L (L..L+N-1, one per
 *    `ladder` entry, spaced by their `after_minutes`). The ladder PAUSES while
 *    the driver is not driving and resumes where it was if they start again
 *    without the clock resetting (shift warnings also count on-duty work);
 *  - cycle_limit: one notice per `cycle_lead_hours` threshold, no ladder;
 *  - rest_complete: one notice per `rest_complete_nudge_minutes` after it
 *    opened (the detector expires it);
 *  - violation: a notice plus the incident at once (step 0); then, only
 *    while the driver keeps DRIVING, the ladder's next channel steps at
 *    their offsets (step k = k-th ladder entry with channels; `escalate`
 *    entries are skipped: a violation never raises a second incident).
 *    `ladder_step` ≥ 1 with no `next_nudge_at` = nothing left to send
 *    (also the open violations PR 1 left behind, migrated to step 1).
 *
 * shift_limit: before the limit (warnings) any work counts; past it, working
 * without driving is legal, so its ladder only moves while driving.
 *
 * Informational notices use the first ladder step's channels (the free
 * driver app by default).
 */
class HosLadderPlanner
{
    /**
     * @param  non-negative-int  $ladderStep
     */
    public function plan(
        HosSituation $situation,
        int $ladderStep,
        ?CarbonInterface $nextNudgeAt,
        CarbonInterface $openedAt,
        bool $escalated,
        ?HosClockReading $current,
        HosMonitoringConfig $config,
        CarbonInterface $now,
    ): HosLadderDecision {
        $now = CarbonImmutable::instance($now);
        $nextNudgeAt = $nextNudgeAt !== null ? CarbonImmutable::instance($nextNudgeAt) : null;

        // La infracción escala en su escalón 0 y luego sigue insistiendo.
        if ($escalated && $situation !== HosSituation::Violation) {
            return $this->keep(HosLadderMove::Done, 'escalated', $ladderStep, $nextNudgeAt);
        }

        return match ($situation) {
            HosSituation::Violation => $this->violation($ladderStep, $nextNudgeAt, $current, $config, $now),
            HosSituation::CycleLimit => $this->cycle($ladderStep, $nextNudgeAt, $current, $config),
            HosSituation::RestComplete => $this->rest($ladderStep, CarbonImmutable::instance($openedAt), $current, $config, $now),
            HosSituation::BreakDue, HosSituation::DriveLimit, HosSituation::ShiftLimit => $this->limit($situation, $ladderStep, $nextNudgeAt, $current, $config, $now),
        };
    }

    /**
     * @param  non-negative-int  $ladderStep
     */
    private function violation(int $ladderStep, ?CarbonImmutable $nextNudgeAt, ?HosClockReading $current, HosMonitoringConfig $config, CarbonImmutable $now): HosLadderDecision
    {
        // Sólo los escalones con canales: el incidente ya salió en el escalón 0.
        $steps = array_values(array_filter($config->ladderSteps(), fn (array $entry): bool => $entry['channels'] !== []));

        if ($ladderStep === 0) {
            return new HosLadderDecision(
                move: HosLadderMove::Escalate,
                reason: 'violation',
                nextStep: 1,
                nextNudgeAt: $this->violationNextNudgeAt($steps, 0, $now),
                step: 0,
                channels: $config->informationalChannels(),
                notice: HosNotice::Violation,
            );
        }

        if ($nextNudgeAt === null || $ladderStep >= count($steps)) {
            return $this->keep(HosLadderMove::Done, 'violation_raised', $ladderStep, $nextNudgeAt);
        }

        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $nextNudgeAt);
        }

        if (HosDutyStatus::tryFrom((string) $current->dutyStatus) !== HosDutyStatus::Driving) {
            // Parado ya no agrava la infracción: la insistencia se pausa sin perder su lugar.
            return $this->keep(HosLadderMove::Hold, 'not_working', $ladderStep, $nextNudgeAt);
        }

        if ($now->lt($nextNudgeAt)) {
            return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $nextNudgeAt);
        }

        return new HosLadderDecision(
            move: HosLadderMove::Notify,
            reason: 'violation_insist',
            nextStep: $ladderStep + 1,
            nextNudgeAt: $this->violationNextNudgeAt($steps, $ladderStep, $now),
            step: $ladderStep,
            channels: $steps[$ladderStep]['channels'],
            notice: HosNotice::Violation,
        );
    }

    /**
     * @param  list<array{after_minutes: int, channels: list<string>, escalate: bool}>  $steps
     */
    private function violationNextNudgeAt(array $steps, int $index, CarbonImmutable $now): ?CarbonImmutable
    {
        $current = $steps[$index] ?? null;
        $next = $steps[$index + 1] ?? null;

        if ($next === null) {
            return null;
        }

        return $now->addMinutes(max(0, $next['after_minutes'] - ($current['after_minutes'] ?? 0)));
    }

    /**
     * @param  non-negative-int  $ladderStep
     */
    private function cycle(int $ladderStep, ?CarbonImmutable $nextNudgeAt, ?HosClockReading $current, HosMonitoringConfig $config): HosLadderDecision
    {
        $thresholds = $config->cycleThresholdsHours();

        if ($ladderStep >= count($thresholds)) {
            return $this->keep(HosLadderMove::Done, 'notices_sent', $ladderStep, $nextNudgeAt);
        }

        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $nextNudgeAt);
        }

        $remaining = $current->cycleRemainingSeconds;

        if ($remaining === null) {
            return $this->keep(HosLadderMove::Wait, 'no_clock', $ladderStep, $nextNudgeAt);
        }

        // A cycle at 0 is a violation/limit case, not a warning with time left.
        if ($remaining <= 0) {
            return $this->keep(HosLadderMove::Wait, 'cycle_exhausted', $ladderStep, $nextNudgeAt);
        }

        $due = $this->deepestCrossed($remaining, array_map(fn (int $hours): int => $hours * 3600, $thresholds));

        if ($due === null || $due < $ladderStep) {
            return $this->keep(HosLadderMove::Wait, 'nothing_due', $ladderStep, $nextNudgeAt);
        }

        return new HosLadderDecision(
            move: HosLadderMove::Notify,
            reason: 'cycle_notice',
            nextStep: $due + 1,
            nextNudgeAt: null,
            step: $due,
            channels: $config->informationalChannels(),
            notice: HosNotice::CycleLead,
            amount: intdiv($remaining + 3599, 3600),
        );
    }

    /**
     * @param  non-negative-int  $ladderStep
     */
    private function rest(int $ladderStep, CarbonImmutable $openedAt, ?HosClockReading $current, HosMonitoringConfig $config, CarbonImmutable $now): HosLadderDecision
    {
        $nudges = $config->restNudgeMinutes();

        if ($ladderStep >= count($nudges)) {
            return $this->keep(HosLadderMove::Done, 'notices_sent', $ladderStep, null);
        }

        $pendingAt = $openedAt->addMinutes($nudges[$ladderStep]);

        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $pendingAt);
        }

        $due = null;

        foreach ($nudges as $index => $minutes) {
            if ($now->gte($openedAt->addMinutes($minutes))) {
                $due = $index;
            }
        }

        if ($due === null || $due < $ladderStep) {
            return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $pendingAt);
        }

        $next = $nudges[$due + 1] ?? null;

        return new HosLadderDecision(
            move: HosLadderMove::Notify,
            reason: 'rest_notice',
            nextStep: $due + 1,
            nextNudgeAt: $next !== null ? $openedAt->addMinutes($next) : null,
            step: $due,
            channels: $config->informationalChannels(),
            notice: HosNotice::RestComplete,
        );
    }

    /**
     * @param  non-negative-int  $ladderStep
     */
    private function limit(HosSituation $situation, int $ladderStep, ?CarbonImmutable $nextNudgeAt, ?HosClockReading $current, HosMonitoringConfig $config, CarbonImmutable $now): HosLadderDecision
    {
        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $nextNudgeAt);
        }

        $remaining = match ($situation) {
            HosSituation::BreakDue => $current->breakRemainingSeconds,
            HosSituation::DriveLimit => $current->driveRemainingSeconds,
            default => $current->shiftRemainingSeconds,
        };

        $status = HosDutyStatus::tryFrom((string) $current->dutyStatus);
        // Antes del límite de 14 h cuenta cualquier trabajo; pasado, trabajar sin manejar es legal.
        $working = $situation === HosSituation::ShiftLimit && ($remaining === null || $remaining > 0)
            ? $status !== null && $status->isWorking()
            : $status === HosDutyStatus::Driving;

        if (! $working) {
            // Cumplió (está parado): la escalera se pausa sin perder su lugar.
            return $this->keep(HosLadderMove::Hold, 'not_working', $ladderStep, $nextNudgeAt);
        }

        if ($remaining === null) {
            return $this->keep(HosLadderMove::Wait, 'no_clock', $ladderStep, $nextNudgeAt);
        }

        $leads = $config->leadThresholdsMinutes();
        $leadCount = count($leads);

        if ($remaining > 0) {
            $due = $this->deepestCrossed($remaining, array_map(fn (int $minutes): int => $minutes * 60, $leads));

            if ($due === null || $due < $ladderStep) {
                return $this->keep(HosLadderMove::Wait, 'nothing_due', $ladderStep, $nextNudgeAt);
            }

            return new HosLadderDecision(
                move: HosLadderMove::Notify,
                reason: 'lead_notice',
                nextStep: $due + 1,
                nextNudgeAt: null,
                step: $due,
                channels: $config->informationalChannels(),
                notice: HosNotice::lead($situation),
                amount: intdiv($remaining + 59, 60),
            );
        }

        $ladder = $config->ladderSteps();

        if ($ladder === []) {
            return $this->keep(HosLadderMove::Done, 'no_ladder', $ladderStep, $nextNudgeAt);
        }

        $index = max(0, $ladderStep - $leadCount);

        if ($index >= count($ladder)) {
            $last = $ladder[count($ladder) - 1];

            // La escalera se acortó a media marcha (el paso vive con la configuración
            // de entonces): si la de hoy termina en incidente, el incidente pendiente sale igual.
            if (! $last['escalate']) {
                return $this->keep(HosLadderMove::Done, 'ladder_finished', $ladderStep, $nextNudgeAt);
            }

            if ($nextNudgeAt !== null && $now->lt($nextNudgeAt)) {
                return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $nextNudgeAt);
            }

            return new HosLadderDecision(
                move: HosLadderMove::Escalate,
                reason: 'ladder_exhausted',
                nextStep: $ladderStep + 1,
                nextNudgeAt: null,
                step: $ladderStep,
                channels: $last['channels'],
                notice: $last['channels'] === [] ? null : HosNotice::limit($situation, insist: true),
            );
        }

        if ($ladderStep < $leadCount || ($ladderStep === $leadCount && $nextNudgeAt === null)) {
            // Llegó al límite: arranca la escalera (los avisos previos pendientes ya no salen).
            $startsAt = $now->addMinutes($ladder[0]['after_minutes']);

            if ($startsAt->gt($now)) {
                return new HosLadderDecision(HosLadderMove::Wait, 'ladder_scheduled', $leadCount, $startsAt);
            }

            $index = 0;
        } elseif ($nextNudgeAt !== null && $now->lt($nextNudgeAt)) {
            return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $nextNudgeAt);
        }

        $entry = $ladder[$index];
        $step = $leadCount + $index;
        $next = $ladder[$index + 1] ?? null;

        return new HosLadderDecision(
            move: $entry['escalate'] ? HosLadderMove::Escalate : HosLadderMove::Notify,
            reason: $entry['escalate'] ? 'ladder_exhausted' : 'ladder_step',
            nextStep: $step + 1,
            nextNudgeAt: $entry['escalate'] || $next === null
                ? null
                : $now->addMinutes(max(0, $next['after_minutes'] - $entry['after_minutes'])),
            step: $step,
            channels: $entry['channels'],
            notice: $entry['channels'] === [] ? null : HosNotice::limit($situation, insist: $index > 0),
        );
    }

    /**
     * Index of the most urgent threshold already crossed, or null.
     *
     * @param  list<int>  $thresholds  largest first
     * @return non-negative-int|null
     */
    private function deepestCrossed(int $remaining, array $thresholds): ?int
    {
        $due = null;

        foreach ($thresholds as $index => $threshold) {
            if ($remaining <= $threshold) {
                $due = $index;
            }
        }

        return $due;
    }

    /**
     * @param  non-negative-int  $ladderStep
     */
    private function keep(HosLadderMove $move, string $reason, int $ladderStep, ?CarbonImmutable $nextNudgeAt): HosLadderDecision
    {
        return new HosLadderDecision($move, $reason, $ladderStep, $nextNudgeAt);
    }
}
