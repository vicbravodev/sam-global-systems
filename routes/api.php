<?php

use App\Http\Controllers\AI\AIEvaluationController;
use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Context\EventContextController;
use App\Http\Controllers\Context\GeofenceController;
use App\Http\Controllers\Decisions\DecisionController;
use App\Http\Controllers\Decisions\DecisionRuleController;
use App\Http\Controllers\Decisions\EscalationPolicyController;
use App\Http\Controllers\Drivers\DriverController;
use App\Http\Controllers\Incidents\IncidentAssignmentController;
use App\Http\Controllers\Incidents\IncidentCommentController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentEventLinkController;
use App\Http\Controllers\Incidents\IncidentEvidenceController;
use App\Http\Controllers\Incidents\IncidentResolutionController;
use App\Http\Controllers\Ingestion\RawEventController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\WebhookController;
use App\Http\Controllers\Normalization\EventTypeController;
use App\Http\Controllers\Normalization\MappingRuleController;
use App\Http\Controllers\Normalization\NormalizedEventController;
use App\Http\Controllers\TenantConfig\TenantAIProfileController;
use App\Http\Controllers\TenantConfig\TenantConfigController;
use App\Http\Controllers\TenantConfig\TenantConfigVersionController;
use App\Http\Controllers\TenantConfig\TenantEscalationConfigController;
use App\Http\Controllers\TenantConfig\TenantNotificationPolicyController;
use App\Http\Controllers\TenantConfig\TenantRuleOverrideController;
use App\Http\Controllers\TenantConfig\TenantScheduleProfileController;
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

        Route::get('ai/evaluations', [AIEvaluationController::class, 'index'])->name('api.ai.evaluations.index');
        Route::get('ai/evaluations/{evaluation}', [AIEvaluationController::class, 'show'])->name('api.ai.evaluations.show');
        Route::post('ai/evaluations/{evaluation}/reevaluate', [AIEvaluationController::class, 'reevaluate'])->name('api.ai.evaluations.reevaluate');

        Route::get('events/raw', [RawEventController::class, 'index'])->name('api.events.raw.index');

        Route::get('events/{normalizedEvent}/context', [EventContextController::class, 'show'])->name('api.events.context.show');

        Route::get('geofences', [GeofenceController::class, 'index'])->name('api.geofences.index');
        Route::post('geofences', [GeofenceController::class, 'store'])->name('api.geofences.store');
        Route::put('geofences/{geofence}', [GeofenceController::class, 'update'])->name('api.geofences.update');
        Route::delete('geofences/{geofence}', [GeofenceController::class, 'destroy'])->name('api.geofences.destroy');

        Route::get('events/normalized', [NormalizedEventController::class, 'index'])->name('api.events.normalized.index');
        Route::get('events/normalized/{normalizedEvent}', [NormalizedEventController::class, 'show'])->name('api.events.normalized.show');
        Route::get('normalization/unmapped', [NormalizedEventController::class, 'unmapped'])->name('api.normalization.unmapped');
        Route::get('normalization/mapping-rules', [MappingRuleController::class, 'index'])->name('api.normalization.mapping-rules.index');
        Route::post('normalization/mapping-rules', [MappingRuleController::class, 'store'])->name('api.normalization.mapping-rules.store');
        Route::put('normalization/mapping-rules/{mappingRule}', [MappingRuleController::class, 'update'])->name('api.normalization.mapping-rules.update');
        Route::delete('normalization/mapping-rules/{mappingRule}', [MappingRuleController::class, 'destroy'])->name('api.normalization.mapping-rules.destroy');
        Route::get('normalization/event-types', [EventTypeController::class, 'index'])->name('api.normalization.event-types.index');

        Route::get('settings/config', [TenantConfigController::class, 'index'])->name('api.tenant-config.settings.index');
        Route::put('settings/config', [TenantConfigController::class, 'update'])->name('api.tenant-config.settings.update');

        Route::get('settings/rules', [TenantRuleOverrideController::class, 'index'])->name('api.tenant-config.rules.index');
        Route::post('settings/rules', [TenantRuleOverrideController::class, 'store'])->name('api.tenant-config.rules.store');
        Route::put('settings/rules/{override}', [TenantRuleOverrideController::class, 'update'])->name('api.tenant-config.rules.update');
        Route::delete('settings/rules/{override}', [TenantRuleOverrideController::class, 'destroy'])->name('api.tenant-config.rules.destroy');

        Route::get('settings/notifications', [TenantNotificationPolicyController::class, 'index'])->name('api.tenant-config.notifications.index');
        Route::put('settings/notifications', [TenantNotificationPolicyController::class, 'update'])->name('api.tenant-config.notifications.update');

        Route::get('settings/ai-profile', [TenantAIProfileController::class, 'show'])->name('api.tenant-config.ai-profile.show');
        Route::put('settings/ai-profile', [TenantAIProfileController::class, 'update'])->name('api.tenant-config.ai-profile.update');

        Route::get('settings/escalation', [TenantEscalationConfigController::class, 'index'])->name('api.tenant-config.escalation.index');
        Route::post('settings/escalation', [TenantEscalationConfigController::class, 'store'])->name('api.tenant-config.escalation.store');
        Route::put('settings/escalation/{escalationConfig}', [TenantEscalationConfigController::class, 'update'])->name('api.tenant-config.escalation.update');

        Route::get('settings/schedule', [TenantScheduleProfileController::class, 'index'])->name('api.tenant-config.schedule.index');
        Route::put('settings/schedule/{scheduleProfile}', [TenantScheduleProfileController::class, 'update'])->name('api.tenant-config.schedule.update');

        Route::get('settings/versions', [TenantConfigVersionController::class, 'index'])->name('api.tenant-config.versions.index');
        Route::get('settings/versions/{configVersion}', [TenantConfigVersionController::class, 'show'])->name('api.tenant-config.versions.show');

        Route::get('decisions/rules', [DecisionRuleController::class, 'index'])->name('api.decisions.rules.index');
        Route::post('decisions/rules', [DecisionRuleController::class, 'store'])->name('api.decisions.rules.store');
        Route::put('decisions/rules/{rule}', [DecisionRuleController::class, 'update'])->name('api.decisions.rules.update');
        Route::delete('decisions/rules/{rule}', [DecisionRuleController::class, 'destroy'])->name('api.decisions.rules.destroy');
        Route::get('decisions/escalation-policies', [EscalationPolicyController::class, 'index'])->name('api.decisions.escalation-policies.index');
        Route::post('decisions/escalation-policies', [EscalationPolicyController::class, 'store'])->name('api.decisions.escalation-policies.store');
        Route::put('decisions/escalation-policies/{policy}', [EscalationPolicyController::class, 'update'])->name('api.decisions.escalation-policies.update');
        Route::get('decisions', [DecisionController::class, 'index'])->name('api.decisions.index');
        Route::get('decisions/{decision}', [DecisionController::class, 'show'])->name('api.decisions.show');
        Route::post('decisions/{decision}/override', [DecisionController::class, 'override'])->name('api.decisions.override');

        Route::get('incidents', [IncidentController::class, 'index'])->name('api.incidents.index');
        Route::post('incidents', [IncidentController::class, 'store'])->name('api.incidents.store');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('api.incidents.show');
        Route::put('incidents/{incident}', [IncidentController::class, 'update'])->name('api.incidents.update');
        Route::post('incidents/{incident}/assign', [IncidentAssignmentController::class, 'store'])->name('api.incidents.assign');
        Route::post('incidents/{incident}/evidence', [IncidentEvidenceController::class, 'store'])->name('api.incidents.evidence.store');
        Route::post('incidents/{incident}/comments', [IncidentCommentController::class, 'store'])->name('api.incidents.comments.store');
        Route::post('incidents/{incident}/resolve', [IncidentResolutionController::class, 'resolve'])->name('api.incidents.resolve');
        Route::post('incidents/{incident}/close', [IncidentResolutionController::class, 'close'])->name('api.incidents.close');
        Route::post('incidents/{incident}/reclassify', [IncidentController::class, 'reclassify'])->name('api.incidents.reclassify');
        Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])->name('api.incidents.reopen');
        Route::post('incidents/{incident}/link-event', [IncidentEventLinkController::class, 'store'])->name('api.incidents.link-event');
    });

Route::post('webhooks/{endpoint_url}', [WebhookController::class, 'handle'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.handle');
