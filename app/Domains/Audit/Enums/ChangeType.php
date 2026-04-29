<?php

namespace App\Domains\Audit\Enums;

enum ChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case StatusChanged = 'status_changed';
    case Reassigned = 'reassigned';
    case Reclassified = 'reclassified';
    case ConfigChanged = 'config_changed';
    case OverrideApplied = 'override_applied';
}
