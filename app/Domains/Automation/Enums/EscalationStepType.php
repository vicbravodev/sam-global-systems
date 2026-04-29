<?php

namespace App\Domains\Automation\Enums;

enum EscalationStepType: string
{
    case Notify = 'notify';
    case Assign = 'assign';
    case Escalate = 'escalate';
    case WaitForAck = 'wait_for_ack';
    case CreateTicket = 'create_ticket';
    case RequestConfirmation = 'request_confirmation';
    case CallExternalSystem = 'call_external_system';
}
