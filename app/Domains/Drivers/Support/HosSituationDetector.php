<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Data\HosDetection;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonInterface;

/**
 * Pure HOS rule evaluation for one driver: given the previous and current
 * clock readings and the situations already open, which open and which end.
 *
 * Limit situations (break, drive, shift) end when their clock RESETS, not
 * when the driver goes off duty: a 5-minute stop must not close and reopen
 * the episode (duplicate reminders). The reminder ladder (PR 2) pauses while
 * the driver is not working instead. A disconnected driver app (null status)
 * freezes everything: an unknown state is neither a breach nor a fix.
 */
class HosSituationDetector
{
    public const int FULL_BREAK_SECONDS = 28800;

    public const int FULL_DRIVE_SECONDS = 39600;

    public const int FULL_SHIFT_SECONDS = 50400;

    /**
     * @param  array<string, CarbonInterface>  $openSituations  situation value → opened_at
     */
    public function detect(
        ?HosClockReading $previous,
        HosClockReading $current,
        HosMonitoringConfig $config,
        array $openSituations,
        CarbonInterface $now,
    ): HosDetection {
        $status = HosDutyStatus::tryFrom((string) $current->dutyStatus);

        if ($status === null) {
            return new HosDetection([], []);
        }

        $driving = $status === HosDutyStatus::Driving;
        $lead = $config->leadSeconds();
        $open = [];
        $resolve = [];

        $rules = [
            HosSituation::Violation->value => [
                'opens' => $current->violationSeconds > 0 || ($driving && $current->driveRemainingSeconds === 0),
                'ends' => $current->violationSeconds === 0 && ! ($driving && $current->driveRemainingSeconds === 0),
            ],
            HosSituation::BreakDue->value => [
                'opens' => $driving && $this->atOrBelow($current->breakRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->breakRemainingSeconds, self::FULL_BREAK_SECONDS),
            ],
            HosSituation::DriveLimit->value => [
                'opens' => $driving && $this->atOrBelow($current->driveRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->driveRemainingSeconds, self::FULL_DRIVE_SECONDS),
            ],
            HosSituation::ShiftLimit->value => [
                'opens' => $status->isWorking() && $this->atOrBelow($current->shiftRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->shiftRemainingSeconds, self::FULL_SHIFT_SECONDS),
            ],
            HosSituation::CycleLimit->value => [
                'opens' => $this->atOrBelow($current->cycleRemainingSeconds, $config->cycleLeadSeconds()),
                'ends' => $current->cycleRemainingSeconds !== null && $current->cycleRemainingSeconds > $config->cycleLeadSeconds(),
            ],
        ];

        foreach ($rules as $situation => $rule) {
            if (isset($openSituations[$situation])) {
                if ($rule['ends']) {
                    $resolve[$situation] = HosEpisodeResolution::Corrected;
                }

                continue;
            }

            if ($rule['opens'] && $config->enabled(HosSituation::from($situation))) {
                $open[] = HosSituation::from($situation);
            }
        }

        $rest = HosSituation::RestComplete->value;

        if (isset($openSituations[$rest])) {
            if ($driving) {
                $resolve[$rest] = HosEpisodeResolution::Corrected;
            } elseif ($openSituations[$rest]->diffInSeconds($now) >= $config->restCompleteExpireSeconds()) {
                $resolve[$rest] = HosEpisodeResolution::Expired;
            }
        } elseif (
            $previous !== null
            && ! $driving
            && $config->enabled(HosSituation::RestComplete)
            && $this->canDrive($current, $config)
            && (
                $this->reset($previous->breakRemainingSeconds, $current->breakRemainingSeconds, self::FULL_BREAK_SECONDS)
                || $this->reset($previous->driveRemainingSeconds, $current->driveRemainingSeconds, self::FULL_DRIVE_SECONDS)
            )
        ) {
            $open[] = HosSituation::RestComplete;
        }

        return new HosDetection($open, $resolve);
    }

    private function atOrBelow(?int $seconds, int $threshold): bool
    {
        return $seconds !== null && $seconds <= $threshold;
    }

    private function above(?int $seconds, int $threshold): bool
    {
        return $seconds !== null && $seconds > $threshold;
    }

    /**
     * Rest is only "complete" if the driver can actually resume: hours left
     * on drive, shift and cycle beyond their warning thresholds. A null clock
     * means we can't tell, so no "you can go" reminder.
     */
    private function canDrive(HosClockReading $current, HosMonitoringConfig $config): bool
    {
        $lead = $config->leadSeconds();

        return $this->above($current->driveRemainingSeconds, $lead)
            && $this->above($current->shiftRemainingSeconds, $lead)
            && $this->above($current->cycleRemainingSeconds, $config->cycleLeadSeconds());
    }

    private function atOrAbove(?int $seconds, int $threshold): bool
    {
        return $seconds !== null && $seconds >= $threshold;
    }

    /** The clock went from partially used back to full: the pause was served. */
    private function reset(?int $before, ?int $after, int $full): bool
    {
        return $before !== null && $before < $full && $this->atOrAbove($after, $full);
    }
}
