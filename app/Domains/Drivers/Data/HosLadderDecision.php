<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use Carbon\CarbonImmutable;

final readonly class HosLadderDecision
{
    /**
     * @param  non-negative-int  $nextStep  ladder_step to store after this move
     * @param  CarbonImmutable|null  $nextNudgeAt  next_nudge_at to store after this move
     * @param  int|null  $step  step executed now (Notify/Escalate): the event_key suffix
     * @param  list<string>  $channels  ChannelType values for this step's notice
     * @param  int|null  $amount  minutes (or cycle hours) left, for the copy
     */
    public function __construct(
        public HosLadderMove $move,
        public string $reason,
        public int $nextStep,
        public ?CarbonImmutable $nextNudgeAt,
        public ?int $step = null,
        public array $channels = [],
        public ?HosNotice $notice = null,
        public ?int $amount = null,
    ) {}
}
