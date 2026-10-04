<?php

use App\Http\Controllers\Access\MemberRoleController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DemoRequestController as AdminDemoRequestController;
use App\Http\Controllers\Admin\GlobalChannelController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OperatorController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\TenantBillingTermsController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantFeatureController;
use App\Http\Controllers\Admin\TenantInvoiceController;
use App\Http\Controllers\Admin\TenantMemberController;
use App\Http\Controllers\Admin\TenantSubscriptionController;
use App\Http\Controllers\AI\AIEvaluationController;
use App\Http\Controllers\Analytics\AnalyticsPageController;
use App\Http\Controllers\Analytics\ReportController;
use App\Http\Controllers\Analytics\ReportExecutionController;
use App\Http\Controllers\Assets\AssetMonitoringController;
use App\Http\Controllers\Assets\AssetPageController;
use App\Http\Controllers\Audit\AuditPageController;
use App\Http\Controllers\Automation\ActionExecutionController;
use App\Http\Controllers\Automation\AutomationPageController;
use App\Http\Controllers\Automation\AutomationWorkflowController;
use App\Http\Controllers\Copilot\CopilotCatalogController;
use App\Http\Controllers\Copilot\CopilotConversationController;
use App\Http\Controllers\Copilot\CopilotMessageController;
use App\Http\Controllers\Copilot\CopilotPageController;
use App\Http\Controllers\Copilot\CopilotStreamController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Decisions\DecisionRuleController;
use App\Http\Controllers\Decisions\RulesPageController;
use App\Http\Controllers\Decisions\RuleTestController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\Drivers\DriverPageController;
use App\Http\Controllers\Incidents\IncidentAIVerdictController;
use App\Http\Controllers\Incidents\IncidentAssignmentController;
use App\Http\Controllers\Incidents\IncidentCommentController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentInboxController;
use App\Http\Controllers\Incidents\IncidentMediaRequestController;
use App\Http\Controllers\Incidents\IncidentResolutionController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\IntegrationPageController;
use App\Http\Controllers\Integrations\WebhookSecretController;
use App\Http\Controllers\Integrations\WebhookSetupController;
use App\Http\Controllers\Normalization\EventsPageController;
use App\Http\Controllers\Normalization\MappingRuleController;
use App\Http\Controllers\Notifications\NotificationChannelController;
use App\Http\Controllers\Notifications\NotificationPageController;
use App\Http\Controllers\Search\CommandPaletteController;
use App\Http\Controllers\Teams\InvitationAcceptanceController;
use App\Http\Controllers\Teams\TenantAccessController;
use App\Http\Controllers\Tenancy\BillingPageController;
use App\Http\Controllers\Tenancy\BrandingController;
use App\Http\Controllers\Tenancy\InvoiceReceiptController;
use App\Http\Controllers\TenantConfig\HosMonitoringConfigController;
use App\Http\Controllers\TenantConfig\IncidentSlaController;
use App\Http\Controllers\TenantConfig\TenantAIProfileController;
use App\Http\Controllers\TenantConfig\TenantConfigController;
use App\Http\Controllers\TenantConfig\TenantConfigPageController;
use App\Http\Controllers\TenantConfig\TenantEscalationConfigController;
use App\Http\Controllers\TenantConfig\TenantNotificationPolicyController;
use App\Http\Controllers\TenantConfig\TenantRuleOverrideController;
use App\Http\Controllers\TenantConfig\TenantScheduleProfileController;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureTenantNotSuspended;
use App\Http\Middleware\RendersErrorsAsJson;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Formulario público "Pedir una demo": se guarda para la consola de
// super-admin y se avisa por correo a los operadores. Antes del grupo
// {current_team} para que el comodín no lo capture.
Route::get('demo', [DemoRequestController::class, 'create'])->name('demo-request.create');
Route::post('demo', [DemoRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('demo-request.store');

// User-level settings routes are registered BEFORE the {current_team} group:
// their literal `settings/...` paths must win over the team-slug wildcard
// (otherwise `/settings/notifications` would bind current_team = "settings").
require __DIR__.'/settings.php';

// Invitaciones: única puerta de entrada de usuarios nuevos (el auto-registro
// está cerrado). El GET sólo muestra la página; aceptar y registrarse son POST.
// Van ANTES del grupo {current_team} para que el comodín no las capture.
Route::get('invitations/{invitation}', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('invitations.show');
Route::post('invitations/{invitation}/register', [InvitationAcceptanceController::class, 'register'])
    ->middleware('throttle:6,1')
    ->name('invitations.register');
Route::post('invitations/{invitation}/accept', [InvitationAcceptanceController::class, 'accept'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('invitations.accept');

// Primer acceso de usuarios dados de alta por el super-admin (dueño de un
// cliente nuevo o miembro añadido desde la consola): define su contraseña con
// el enlace de bienvenida (broker `onboarding`, 7 días) y entra a su empresa.
Route::get('bienvenida/{token}', [TenantAccessController::class, 'show'])
    ->middleware(['guest', 'throttle:30,1'])
    ->name('onboarding.show');
Route::post('bienvenida', [TenantAccessController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('onboarding.store');

// The super-admin console is declared BEFORE the tenant wildcard group so
// `/admin/...` never gets swallowed by `/{current_team}/...` routes.
// SaaS operator (super-admin) control panel. Lives OUTSIDE the {current_team}
// group because it is cross-tenant: it lists/creates tenants and starts
// impersonation. Guarded by the global-role check in EnsureSuperAdmin and by
// RequireSuperAdminTwoFactor (2FA confirmado obligatorio para operar).
Route::prefix('admin')
    ->middleware(['auth', 'verified', 'ensure.super_admin', 'super_admin.two_factor'])
    ->name('admin.')
    ->group(function () {
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{team}', [TenantController::class, 'show'])->name('tenants.show');
        Route::put('tenants/{team}', [TenantController::class, 'update'])->name('tenants.update');
        Route::delete('tenants/{team}', [TenantController::class, 'destroy'])->name('tenants.destroy');

        // Subscription / plan controls for a single tenant (internal billing state).
        Route::put('tenants/{team}/subscription', [TenantSubscriptionController::class, 'update'])->name('tenants.subscription.update');
        Route::post('tenants/{team}/subscription/suspend', [TenantSubscriptionController::class, 'suspend'])->name('tenants.subscription.suspend');
        Route::post('tenants/{team}/subscription/reactivate', [TenantSubscriptionController::class, 'reactivate'])->name('tenants.subscription.reactivate');
        Route::post('tenants/{team}/subscription/cancel', [TenantSubscriptionController::class, 'cancel'])->name('tenants.subscription.cancel');

        // Términos comerciales por tenant (cobro por tracto-día, sin planes).
        Route::put('tenants/{team}/billing-terms', [TenantBillingTermsController::class, 'update'])->name('tenants.billing-terms.update');

        Route::post('tenants/{team}/invoices/generate', [TenantInvoiceController::class, 'generate'])->name('tenants.invoices.generate');
        Route::post('tenants/{team}/invoices/{invoice}/mark-paid', [TenantInvoiceController::class, 'markPaid'])->name('tenants.invoices.mark-paid');
        Route::post('tenants/{team}/invoices/{invoice}/void', [TenantInvoiceController::class, 'void'])->name('tenants.invoices.void');

        // Manual feature overrides for a tenant.
        Route::put('tenants/{team}/features/{featureKey}', [TenantFeatureController::class, 'update'])->name('tenants.features.update');

        // Tenant member management.
        Route::post('tenants/{team}/members', [TenantMemberController::class, 'store'])->name('tenants.members.store');
        Route::put('tenants/{team}/members/{user}', [TenantMemberController::class, 'update'])->name('tenants.members.update');
        Route::delete('tenants/{team}/members/{user}', [TenantMemberController::class, 'destroy'])->name('tenants.members.destroy');
        Route::post('tenants/{team}/members/{user}/make-owner', [TenantMemberController::class, 'makeOwner'])->name('tenants.members.make-owner');
        Route::post('tenants/{team}/members/{user}/send-access', [TenantMemberController::class, 'sendAccess'])
            ->middleware('throttle:10,1')
            ->name('tenants.members.send-access');

        // Plan catalog: tune per-meter allowances (incl. the asset cap).
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

        // SAM platform notification channels (Roadmap V2-B1).
        Route::get('channels', [GlobalChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [GlobalChannelController::class, 'store'])->name('channels.store');
        Route::put('channels/{channel}', [GlobalChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [GlobalChannelController::class, 'destroy'])->name('channels.destroy');

        // SaaS operators (global super-admins).
        Route::get('operators', [OperatorController::class, 'index'])->name('operators.index');
        Route::post('operators', [OperatorController::class, 'store'])->name('operators.store');
        Route::delete('operators/{user}', [OperatorController::class, 'destroy'])->name('operators.destroy');

        // Solicitudes de demo del sitio público (seguimiento comercial).
        Route::get('demo-requests', [AdminDemoRequestController::class, 'index'])->name('demo-requests.index');
        Route::put('demo-requests/{demoRequest}', [AdminDemoRequestController::class, 'update'])->name('demo-requests.update');

        // Cross-tenant audit viewer.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        Route::post('impersonate/{team}', [ImpersonationController::class, 'store'])->name('impersonate.store');
        Route::delete('impersonate', [ImpersonationController::class, 'destroy'])->name('impersonate.destroy');
    });

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureTenantNotSuspended::class])
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('events', [EventsPageController::class, 'index'])->name('events.index');
        Route::get('events/{normalizedEvent}', [EventsPageController::class, 'show'])->name('events.show');

        Route::get('palette-search', CommandPaletteController::class)->name('palette.search');
        Route::get('incidents', [IncidentInboxController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentInboxController::class, 'show'])->name('incidents.show');

        // Incident inbox actions. These mirror the routes/api.php endpoints but
        // live in the web group so the Inertia inbox can call them with the
        // session cookie (the `api` group does not start a session).
        Route::post('incidents/{incident}/assign', [IncidentAssignmentController::class, 'store'])->name('incidents.assign');
        Route::post('incidents/{incident}/comments', [IncidentCommentController::class, 'store'])->name('incidents.comments.store');
        Route::post('incidents/{incident}/resolve', [IncidentResolutionController::class, 'resolve'])->name('incidents.resolve');
        Route::post('incidents/{incident}/ai-verdict', [IncidentAIVerdictController::class, 'store'])->name('incidents.ai-verdict');
        Route::post('incidents/{incident}/close', [IncidentResolutionController::class, 'close'])->name('incidents.close');
        Route::post('incidents/{incident}/reclassify', [IncidentController::class, 'reclassify'])->name('incidents.reclassify');
        Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])->name('incidents.reopen');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('incidents/{incident}/claim', [IncidentController::class, 'claim'])->name('incidents.claim');
        Route::post('incidents/{incident}/release', [IncidentController::class, 'release'])->name('incidents.release');
        Route::post('incidents/{incident}/media/request', [IncidentMediaRequestController::class, 'store'])->name('incidents.media.request');
        Route::post('incidents/{incident}/escalate', [IncidentController::class, 'escalate'])->name('incidents.escalate');
        Route::post('ai/evaluations/{evaluation}/reevaluate', [AIEvaluationController::class, 'reevaluate'])->name('ai.evaluations.reevaluate');

        // Fleet pages. Asset inventory is managed by integration sync (spec
        // 04 §9); the only thing the tenant edits is WHICH units SAM watches.
        Route::get('assets', [AssetPageController::class, 'index'])->name('assets.index');
        // Literal segments BEFORE the {asset} binding so they never hit it.
        Route::get('assets/map', [AssetPageController::class, 'map'])->name('assets.map');
        Route::put('assets/monitoring', [AssetMonitoringController::class, 'bulk'])->name('assets.monitoring.bulk');
        Route::get('assets/{asset}', [AssetPageController::class, 'show'])->name('assets.show');
        Route::put('assets/{asset}/monitoring', [AssetMonitoringController::class, 'update'])->name('assets.monitoring.update');

        // SAM Copilot: conversational assistant over the tenant's fleet data.
        // CopilotConversationPolicy gates every route (`copilot.use`, which
        // also honours the `copilot` tenant feature and subscription state).
        Route::get('copilot', [CopilotPageController::class, 'index'])->name('copilot.index');
        Route::get('copilot/usage', [CopilotPageController::class, 'usage'])->name('copilot.usage');
        Route::get('copilot/catalog', CopilotCatalogController::class)->name('copilot.catalog');
        Route::post('copilot/messages', [CopilotMessageController::class, 'store'])->middleware('throttle:copilot')->name('copilot.messages.store');
        Route::post('copilot/stream', CopilotStreamController::class)->middleware([RendersErrorsAsJson::class, 'throttle:copilot'])->name('copilot.stream');
        Route::put('copilot/messages/{message}/feedback', [CopilotMessageController::class, 'feedback'])->name('copilot.messages.feedback');
        Route::get('copilot/conversations/{conversation}', [CopilotConversationController::class, 'show'])->name('copilot.conversations.show');
        Route::patch('copilot/conversations/{conversation}', [CopilotConversationController::class, 'update'])->name('copilot.conversations.update');
        Route::delete('copilot/conversations/{conversation}', [CopilotConversationController::class, 'destroy'])->name('copilot.conversations.destroy');

        // Driver pages (read-only; DriverPolicy gates access).
        Route::get('drivers', [DriverPageController::class, 'index'])->name('drivers.index');
        Route::get('drivers/{driver}', [DriverPageController::class, 'show'])->name('drivers.show');

        // Integrations management page + actions. The GET renders the Inertia
        // page; the mutating actions reuse the same IntegrationController as the
        // routes/api.php endpoints but live in the web group so the React UI can
        // call them with the session cookie (the `api` group has no session).
        // Notification center: tenant-wide outbound notifications with
        // per-user read markers (NotificationPolicy gates access).
        Route::get('notifications', [NotificationPageController::class, 'index'])->name('notifications.index');
        Route::get('notifications/{notification}', [NotificationPageController::class, 'show'])->name('notifications.show');
        Route::post('notifications/{notification}/read', [NotificationPageController::class, 'read'])->name('notifications.read');

        Route::get('integrations', [IntegrationPageController::class, 'index'])->name('integrations.index');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('integrations.store');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->name('integrations.update');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->name('integrations.test');
        Route::put('integrations/{integration}/webhook-secret', [WebhookSecretController::class, 'update'])->name('integrations.webhook-secret.update');
        Route::post('integrations/{integration}/webhook/provision', [WebhookSetupController::class, 'provision'])->name('integrations.webhook.provision');
        Route::post('integrations/{integration}/webhook/rotate', [WebhookSetupController::class, 'rotate'])->name('integrations.webhook.rotate');

        Route::get('audit', [AuditPageController::class, 'show'])->name('audit.show');

        Route::get('billing', [BillingPageController::class, 'show'])->name('billing.show');
        Route::post('billing/invoices/{invoice}/receipt', [InvoiceReceiptController::class, 'store'])->name('billing.invoices.receipt');
        Route::put('settings/tenant-config/branding', [BrandingController::class, 'update'])->name('tenant-config.branding.update');
        Route::post('settings/tenant-config/branding/logo', [BrandingController::class, 'uploadLogo'])->name('tenant-config.branding.logo');

        Route::get('analytics', [AnalyticsPageController::class, 'show'])->name('analytics.show');
        Route::post('analytics/reports/{report}/generate', [ReportController::class, 'generate'])->name('analytics.reports.generate');
        Route::get('analytics/executions/{execution}/download', [ReportExecutionController::class, 'download'])->name('analytics.executions.download');

        Route::get('automation', [AutomationPageController::class, 'show'])->name('automation.show');
        Route::post('automation/workflows', [AutomationWorkflowController::class, 'store'])->name('automation.workflows.store');
        Route::put('automation/workflows/{workflow}', [AutomationWorkflowController::class, 'update'])->name('automation.workflows.update');
        Route::delete('automation/workflows/{workflow}', [AutomationWorkflowController::class, 'destroy'])->name('automation.workflows.destroy');
        Route::post('automation/workflows/{workflow}/trigger', [AutomationWorkflowController::class, 'trigger'])->name('automation.workflows.trigger');
        Route::post('automation/executions/{execution}/retry', [ActionExecutionController::class, 'retry'])->name('automation.executions.retry');
        Route::post('automation/executions/{execution}/confirm', [ActionExecutionController::class, 'confirm'])->name('automation.executions.confirm');
        Route::post('automation/executions/{execution}/cancel', [ActionExecutionController::class, 'cancel'])->name('automation.executions.cancel');

        Route::get('rules', [RulesPageController::class, 'show'])->name('rules.show');
        Route::post('rules/decision', [DecisionRuleController::class, 'store'])->name('rules.decision.store');
        Route::put('rules/decision/{rule}', [DecisionRuleController::class, 'update'])->name('rules.decision.update');
        Route::delete('rules/decision/{rule}', [DecisionRuleController::class, 'destroy'])->name('rules.decision.destroy');
        Route::post('rules/mapping', [MappingRuleController::class, 'store'])->name('rules.mapping.store');
        Route::put('rules/mapping/{mappingRule}', [MappingRuleController::class, 'update'])->name('rules.mapping.update');
        Route::delete('rules/mapping/{mappingRule}', [MappingRuleController::class, 'destroy'])->name('rules.mapping.destroy');
        Route::post('rules/test-decision', [RuleTestController::class, 'testDecision'])->name('rules.test.decision');
        Route::post('rules/test-mapping', [RuleTestController::class, 'testMapping'])->name('rules.test.mapping');
        Route::post('rules/overrides', [TenantRuleOverrideController::class, 'store'])->name('rules.overrides.store');
        Route::put('rules/overrides/{override}', [TenantRuleOverrideController::class, 'update'])->name('rules.overrides.update');
        Route::delete('rules/overrides/{override}', [TenantRuleOverrideController::class, 'destroy'])->name('rules.overrides.destroy');

        Route::get('settings/tenant-config', [TenantConfigPageController::class, 'show'])->name('tenant-config.show');
        Route::post('settings/tenant-config/apply-sam-defaults', [TenantConfigPageController::class, 'applySamDefaults'])->name('tenant-config.apply-sam-defaults');
        Route::put('settings/tenant-config/settings', [TenantConfigController::class, 'update'])->name('tenant-config.settings.update');
        Route::put('settings/tenant-config/ai-profile', [TenantAIProfileController::class, 'update'])->name('tenant-config.ai-profile.update');
        Route::put('settings/tenant-config/notifications', [TenantNotificationPolicyController::class, 'update'])->name('tenant-config.notifications.update');
        Route::post('settings/tenant-config/escalation', [TenantEscalationConfigController::class, 'store'])->name('tenant-config.escalation.store');
        Route::put('settings/tenant-config/escalation/{escalationConfig}', [TenantEscalationConfigController::class, 'update'])->name('tenant-config.escalation.update');
        Route::put('settings/tenant-config/schedule/{scheduleProfile}', [TenantScheduleProfileController::class, 'update'])->name('tenant-config.schedule.update');
        // La mensajería la opera SAM con credenciales de plataforma (env);
        // los tenants no configuran canales — sólo los apagan/encienden (V2-B1).
        Route::post('settings/tenant-config/channels/{channel}/toggle', [NotificationChannelController::class, 'toggle'])->name('tenant-config.channels.toggle');

        // Tarea 7: pantalla de tiempos de respuesta (SLA) por prioridad — consume
        // el resolver de la Tarea 6 (ResolveIncidentSla / TenantIncidentSla).
        Route::get('settings/tenant-config/slas', [IncidentSlaController::class, 'index'])->name('tenant-config.slas.index');
        Route::put('settings/tenant-config/slas', [IncidentSlaController::class, 'update'])->name('tenant-config.slas.update');

        // Monitoreo HOS (EE. UU.): sección `?seccion=hos`. HosMonitoringPolicy
        // exige la feature `hos_monitoring` además del permiso.
        Route::put('settings/tenant-config/hos', [HosMonitoringConfigController::class, 'update'])->name('tenant-config.hos.update');
        Route::get('settings/tenant-config/hos/tags', [HosMonitoringConfigController::class, 'tags'])->middleware('throttle:hos-preview')->name('tenant-config.hos.tags');
        Route::post('settings/tenant-config/hos/preview', [HosMonitoringConfigController::class, 'preview'])->middleware('throttle:hos-preview')->name('tenant-config.hos.preview');

        Route::get('settings/roles', [RoleController::class, 'index'])->name('access.roles.index');
        Route::post('settings/roles', [RoleController::class, 'store'])->name('access.roles.store');
        Route::put('settings/roles/{role}', [RoleController::class, 'update'])->name('access.roles.update');
        Route::delete('settings/roles/{role}', [RoleController::class, 'destroy'])->name('access.roles.destroy');
        Route::put('settings/members/{membership}/role', [MemberRoleController::class, 'update'])->name('access.members.role.update');
    });
