<?php

use App\Domains\Access\AccessServiceProvider;
use App\Domains\AI\AIServiceProvider;
use App\Domains\Assets\AssetsServiceProvider;
use App\Domains\Context\ContextServiceProvider;
use App\Domains\Decisions\DecisionsServiceProvider;
use App\Domains\Drivers\DriversServiceProvider;
use App\Domains\Ingestion\IngestionServiceProvider;
use App\Domains\Integrations\IntegrationsServiceProvider;
use App\Domains\Normalization\NormalizationServiceProvider;
use App\Domains\Tenancy\TenancyServiceProvider;
use App\Domains\TenantConfig\TenantConfigServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AccessServiceProvider::class,
    AIServiceProvider::class,
    AssetsServiceProvider::class,
    ContextServiceProvider::class,
    DecisionsServiceProvider::class,
    DriversServiceProvider::class,
    IngestionServiceProvider::class,
    IntegrationsServiceProvider::class,
    NormalizationServiceProvider::class,
    TenancyServiceProvider::class,
    TenantConfigServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
