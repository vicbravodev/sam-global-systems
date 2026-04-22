<?php

namespace App\Domains\Drivers\Enums;

enum DocumentType: string
{
    case License = 'license';
    case Identification = 'identification';
    case MedicalCert = 'medical_cert';
    case InternalDoc = 'internal_doc';
    case SpecialPermit = 'special_permit';
}
