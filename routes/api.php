<?php

use App\Http\Controllers\AI\AIEvaluationController;
use App\Http\Controllers\AI\AIStreamController;
use App\Http\Controllers\Analytics\AiPerformanceController;
use App\Http\Controllers\Analytics\AnalyticsDashboardController;
use App\Http\Controllers\Analytics\AnalyticsSnapshotController;
use App\Http\Controllers\Analytics\KpiController;
use App\Http\Controllers\Analytics\ReportController;
use App\Http\Controllers\Analytics\ReportExecutionController;
use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Audit\ChangeHistoryController;
use App\Http\Controllers\Audit\DomainEventLogController;
use App\Http\Controllers\Audit\SystemTraceController;
use App\Http\Controllers\Automation\ActionExecutionController;
use App\Http\Controllers\Automation\ActionTemplateController;
use App\Http\Controllers\Automation\AutomationWorkflowController;
use App\Http\Controllers\Context\EventContextController;
use App\Http\Controllers\Context\EventMediaController;
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
use App\Http\Controllers\Notifications\NotificationChannelController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Notifications\NotificationTemplateController;
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
        Route::get('ai/tasks/{taskId}/stream', [AIStreamController::class, 'stream'])->name('api.ai.tasks.stream');

        Route::get('events/raw', [RawEventController::class, 'index'])->name('api.events.raw.index');

        Route::get('events/{normalizedEvent}/context', [EventContextController::class, 'show'])->name('api.events.context.show');
        Route::get('events/{normalizedEvent}/media', [EventMediaController::class, 'index'])->name('api.events.media.index');
        Route::post('events/{normalizedEvent}/media/request', [EventMediaController::class, 'requestMedia'])->name('api.events.media.request');

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

        Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('notifications/send', [NotificationController::class, 'send'])->name('api.notifications.send');

        Route::get('notifications/templates', [NotificationTemplateController::class, 'index'])->name('api.notifications.templates.index');
        Route::post('notifications/templates', [NotificationTemplateController::class, 'store'])->name('api.notifications.templates.store');
        Route::put('notifications/templates/{template}', [NotificationTemplateController::class, 'update'])->name('api.notifications.templates.update');

        Route::get('notifications/channels', [NotificationChannelController::class, 'index'])->name('api.notifications.channels.index');
        Route::put('notifications/channels/{channel}', [NotificationChannelController::class, 'update'])->name('api.notifications.channels.update');

        Route::get('notifications/preferences', [NotificationPreferenceController::class, 'index'])->name('api.notifications.preferences.index');
        Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update'])->name('api.notifications.preferences.update');

        Route::get('notifications/{notification}', [NotificationController::class, 'show'])
            ->whereNumber('notification')
            ->name('api.notifications.show');

        Route::get('automation/workflows', [AutomationWorkflowController::class, 'index'])->name('api.automation.workflows.index');
        Route::post('automation/workflows', [AutomationWorkflowController::class, 'store'])->name('api.automation.workflows.store');
        Route::get('automation/workflows/{workflow}', [AutomationWorkflowController::class, 'show'])->name('api.automation.workflows.show');
        Route::put('automation/workflows/{workflow}', [AutomationWorkflowController::class, 'update'])->name('api.automation.workflows.update');
        Route::delete('automation/workflows/{workflow}', [AutomationWorkflowController::class, 'destroy'])->name('api.automation.workflows.destroy');
        Route::post('automation/workflows/{workflow}/trigger', [AutomationWorkflowController::class, 'trigger'])->name('api.automation.workflows.trigger');

        Route::get('automation/executions', [ActionExecutionController::class, 'index'])->name('api.automation.executions.index');
        Route::get('automation/executions/{execution}', [ActionExecutionController::class, 'show'])->name('api.automation.executions.show');
        Route::post('automation/executions/{execution}/retry', [ActionExecutionController::class, 'retry'])->name('api.automation.executions.retry');
        Route::post('automation/executions/{execution}/confirm', [ActionExecutionController::class, 'confirm'])->name('api.automation.executions.confirm');
        Route::post('automation/executions/{execution}/cancel', [ActionExecutionController::class, 'cancel'])->name('api.automation.executions.cancel');

        Route::get('automation/templates', [ActionTemplateController::class, 'index'])->name('api.automation.templates.index');
        Route::post('automation/templates', [ActionTemplateController::class, 'store'])->name('api.automation.templates.store');

        // Audit (read-only).
        Route::get('audit/logs', [AuditLogController::class, 'index'])->name('api.audit.logs.index');
        Route::get('audit/logs/{auditLog}', [AuditLogController::class, 'show'])->name('api.audit.logs.show');
        Route::get('audit/events', [DomainEventLogController::class, 'index'])->name('api.audit.events.index');
        Route::get('audit/changes', [ChangeHistoryController::class, 'index'])->name('api.audit.changes.index');
        Route::get('audit/traces/{traceId}', [SystemTraceController::class, 'show'])->name('api.audit.traces.show');

        Route::get('analytics/dashboard', [AnalyticsDashboardController::class, 'index'])->name('api.analytics.dashboard');
        Route::get('analytics/kpis', [KpiController::class, 'index'])->name('api.analytics.kpis.index');
        Route::get('analytics/snapshots/{type}', [AnalyticsSnapshotController::class, 'show'])->name('api.analytics.snapshots.show');
        Route::get('analytics/ai-performance', [AiPerformanceController::class, 'index'])->name('api.analytics.ai-performance');
        Route::get('analytics/reports', [ReportController::class, 'index'])->name('api.analytics.reports.index');
        Route::post('analytics/reports/{report}/generate', [ReportController::class, 'generate'])->name('api.analytics.reports.generate');
        Route::get('analytics/reports/executions', [ReportExecutionController::class, 'index'])->name('api.analytics.reports.executions.index');
        Route::get('analytics/reports/executions/{execution}', [ReportExecutionController::class, 'show'])->name('api.analytics.reports.executions.show');
        Route::get('analytics/reports/executions/{execution}/download', [ReportExecutionController::class, 'download'])->name('api.analytics.reports.executions.download');
    });

Route::post('webhooks/{endpoint_url}', [WebhookController::class, 'handle'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.handle');
