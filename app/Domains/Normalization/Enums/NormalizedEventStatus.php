<?php

namespace App\Domains\Normalization\Enums;

enum NormalizedEventStatus: string
{
    case Normalized = 'normalized';
    case EnrichmentPending = 'enrichment_pending';
    case Enriched = 'enriched';
    case Failed = 'failed';
    case Unmapped = 'unmapped';
}
