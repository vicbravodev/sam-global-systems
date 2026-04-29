<?php

namespace App\Domains\Incidents\Enums;

enum EventRelationType: string
{
    case RootTrigger = 'root_trigger';
    case SupportingEvent = 'supporting_event';
    case RepeatedSignal = 'repeated_signal';
    case FollowupEvent = 'followup_event';
}
