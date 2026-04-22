<?php

namespace App\Domains\Ingestion\Enums;

enum AttachmentType: string
{
    case Snapshot = 'snapshot';
    case Image = 'image';
    case Clip = 'clip';
    case Document = 'document';
}
