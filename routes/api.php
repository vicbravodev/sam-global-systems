<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Drivers\DriverController;
use App\Http\Controllers\Ingestion\RawEventController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\WebhookController;
use App\Http\Controllers\Normalization\EventTypeController;
use App\Http\Controllers\Normalization\MappingRuleController;
use App\Http\Controllers\Normalization\NormalizedEventController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::prefix('{current_team}')
    ->middleware(['auth', EnsureTeamMembership::class, 'throttle:api'])
    ->group(function () {
        Route::get('integrations', [IntegrationController::class, 'index'])->name('api.integrations.index');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('api.integrations.store');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->name('api.integrations.update');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->name('api.integrations.destroy');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->name('api.integrations.test');

        Route::get('assets', [AssetController::class, 'index'])->name('api.assets.index');
        Route::get('assets/{asset}', [AssetController::class, 'show'])->name('api.assets.show');
        Route::get('assets/{asset}/location-history', [AssetController::class, 'locationHistory'])->name('api.assets.location-history');
        Route::get('assets/{asset}/telemetry', [AssetController::class, 'telemetry'])->name('api.assets.telemetry');

        Route::get('drivers', [DriverController::class, 'index'])->name('api.drivers.index');
        Route::get('drivers/{driver}', [DriverController::class, 'show'])->name('api.drivers.show');
        Route::get('drivers/{driver}/assignments', [DriverController::class, 'assignments'])->name('api.drivers.assignments');
        Route::get('drivers/{driver}/risk-profile', [DriverController::class, 'riskProfile'])->name('api.drivers.risk-profile');
        Route::put('drivers/{driver}/contacts', [DriverController::class, 'updateContacts'])->name('api.drivers.update-contacts');
        Route::put('drivers/{driver}/documents', [DriverController::class, 'updateDocuments'])->name('api.drivers.update-documents');

        Route::get('events/raw', [RawEventController::class, 'index'])->name('api.events.raw.index');

        Route::get('events/normalized', [NormalizedEventController::class, 'index'])->name('api.events.normalized.index');
        Route::get('events/normalized/{normalizedEvent}', [NormalizedEventController::class, 'show'])->name('api.events.normalized.show');
        Route::get('normalization/unmapped', [NormalizedEventController::class, 'unmapped'])->name('api.normalization.unmapped');
        Route::get('normalization/mapping-rules', [MappingRuleController::class, 'index'])->name('api.normalization.mapping-rules.index');
        Route::post('normalization/mapping-rules', [MappingRuleController::class, 'store'])->name('api.normalization.mapping-rules.store');
        Route::put('normalization/mapping-rules/{mappingRule}', [MappingRuleController::class, 'update'])->name('api.normalization.mapping-rules.update');
        Route::delete('normalization/mapping-rules/{mappingRule}', [MappingRuleController::class, 'destroy'])->name('api.normalization.mapping-rules.destroy');
        Route::get('normalization/event-types', [EventTypeController::class, 'index'])->name('api.normalization.event-types.index');
    });

Route::post('webhooks/{endpoint_url}', [WebhookController::class, 'handle'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.handle');
