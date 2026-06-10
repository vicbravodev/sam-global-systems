<?php

declare(strict_types=1);

const TIER1_FILES = [
    'app/Concerns/BelongsToTenant.php',
    'app/Http/Middleware/EnsureTeamMembership.php',
    'app/Http/Middleware/SetTeamUrlDefaults.php',
    'app/Support/helpers.php',
    'app/Domains/Tenancy/Actions/RecordUsageEvent.php',
    'app/Domains/Tenancy/Actions/RegisterUsageEvent.php',
    'app/Domains/Tenancy/Actions/ResolveTenantContext.php',
    'app/Domains/Tenancy/Actions/CreateTenant.php',
    'app/Domains/Tenancy/Jobs/AggregateUsageJob.php',
    'app/Domains/Tenancy/Jobs/GenerateInvoiceSnapshotJob.php',
    'app/Domains/Access/Actions/AuthorizeAction.php',
    'app/Domains/Access/Actions/AssignRoleToMember.php',
    'app/Domains/Access/Actions/SyncRolePermissions.php',
    'app/Domains/Drivers/Policies/DriverPolicy.php',
    'app/Domains/Integrations/Policies/TenantIntegrationPolicy.php',
    'app/Domains/Integrations/Actions/ValidateWebhookSignature.php',
    'app/Domains/Integrations/Actions/HandleWebhook.php',
    'app/Domains/Ingestion/Actions/ValidateIncomingSignature.php',
    'app/Domains/Ingestion/Actions/DetectDuplicateEvent.php',
    'app/Domains/Ingestion/Actions/StoreRawEvent.php',
    'app/Http/Controllers/Integrations/WebhookController.php',
];

const TIER2_GLOBS = [
    'app/Domains/*/Actions/*.php',
    'app/Domains/*/Services/*.php',
    'app/Domains/*/Jobs/*.php',
    'app/Domains/*/Policies/*.php',
    'app/Domains/*/Commands/*.php',
];

const THRESHOLDS = [
    'ci' => ['tier1' => 95.0, 'tier2' => 85.0, 'global' => 80.0],
    'local' => ['tier1' => 95.0, 'tier2' => 80.0, 'global' => 75.0],
];

function coverage_tier_of(string $relPath): string
{
    if (in_array($relPath, TIER1_FILES, true)) {
        return 'tier1';
    }
    foreach (TIER2_GLOBS as $glob) {
        if (fnmatch($glob, $relPath)) {
            return 'tier2';
        }
    }

    return 'global';
}
