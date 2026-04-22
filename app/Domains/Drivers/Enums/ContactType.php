<?php

namespace App\Domains\Drivers\Enums;

enum ContactType: string
{
    case MobilePhone = 'mobile_phone';
    case Email = 'email';
    case EmergencyContact = 'emergency_contact';
    case SupervisorContact = 'supervisor_contact';
}
