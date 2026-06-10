<?php

namespace App\Domains\Context\Enums;

enum IncidentRelationType: string
{
    case SameAssetOpenIncident = 'same_asset_open_incident';
    case SameDriverRecentIncident = 'same_driver_recent_incident';
    case SameLocationCluster = 'same_location_cluster';
    case ProbableFollowup = 'probable_followup';
    case DuplicateOperationalCase = 'duplicate_operational_case';
    case PriorSimilarIncident = 'prior_similar_incident';
}
