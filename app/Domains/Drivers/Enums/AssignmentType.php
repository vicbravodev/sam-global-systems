<?php

namespace App\Domains\Drivers\Enums;

enum AssignmentType: string
{
    case PrimaryDriver = 'primary_driver';
    case SecondaryDriver = 'secondary_driver';
    case TemporaryOperator = 'temporary_operator';
    case ResponsibleParty = 'responsible_party';
}
