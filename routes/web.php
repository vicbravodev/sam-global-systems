<?php

use App\Http\Controllers\Access\MemberRoleController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantFeatureController;
use App\Http\Controllers\Admin\TenantMemberController;
use App\Http\Controllers\Admin\TenantSubscriptionController;
use App\Http\Controllers\AI\AIEvaluationController;
use App\Http\Controllers\Incidents\IncidentAssignmentController;
use App\Http\Controllers\Incidents\IncidentCommentController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentInboxController;
use App\Http\Controllers\Incidents\IncidentResolutionController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\IntegrationPageController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::inertia('dashboard', 'dashboard')->name('dashboard');
        Route::get('incidents', [IncidentInboxController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentInboxController::class, 'show'])->name('incidents.show');

        // Incident inbox actions. These mirror the routes/api.php endpoints but
        // live in the web group so the Inertia inbox can call them with the
        // session cookie (the `api` group does not start a session).
        Route::post('incidents/{incident}/assign', [IncidentAssignmentController::class, 'store'])->name('incidents.assign');
        Route::post('incidents/{incident}/comments', [IncidentCommentController::class, 'store'])->name('incidents.comments.store');
        Route::post('incidents/{incident}/resolve', [IncidentResolutionController::class, 'resolve'])->name('incidents.resolve');
        Route::post('incidents/{incident}/close', [IncidentResolutionController::class, 'close'])->name('incidents.close');
        Route::post('incidents/{incident}/reclassify', [IncidentController::class, 'reclassify'])->name('incidents.reclassify');
        Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])->name('incidents.reopen');
        Route::post('incidents/{incident}/escalate', [IncidentController::class, 'escalate'])->name('incidents.escalate');
        Route::post('ai/evaluations/{evaluation}/reevaluate', [AIEvaluationController::class, 'reevaluate'])->name('ai.evaluations.reevaluate');

        // Integrations management page + actions. The GET renders the Inertia
        // page; the mutating actions reuse the same IntegrationController as the
        // routes/api.php endpoints but live in the web group so the React UI can
        // call them with the session cookie (the `api` group has no session).
        Route::get('integrations', [IntegrationPageController::class, 'index'])->name('integrations.index');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('integrations.store');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->name('integrations.update');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->name('integrations.test');

        Route::get('settings/roles', [RoleController::class, 'index'])->name('access.roles.index');
        Route::post('settings/roles', [RoleController::class, 'store'])->name('access.roles.store');
        Route::put('settings/roles/{role}', [RoleController::class, 'update'])->name('access.roles.update');
        Route::delete('settings/roles/{role}', [RoleController::class, 'destroy'])->name('access.roles.destroy');
        Route::put('settings/members/{membership}/role', [MemberRoleController::class, 'update'])->name('access.members.role.update');
    });

// SaaS operator (super-admin) control panel. Lives OUTSIDE the {current_team}
// group because it is cross-tenant: it lists/creates tenants and starts
// impersonation. Guarded by the global-role check in EnsureSuperAdmin.
Route::prefix('admin')
    ->middleware(['auth', 'verified', 'ensure.super_admin'])
    ->name('admin.')
    ->group(function () {
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{team}', [TenantController::class, 'show'])->name('tenants.show');

        // Subscription / plan controls for a single tenant (internal billing state).
        Route::put('tenants/{team}/subscription', [TenantSubscriptionController::class, 'update'])->name('tenants.subscription.update');
        Route::post('tenants/{team}/subscription/suspend', [TenantSubscriptionController::class, 'suspend'])->name('tenants.subscription.suspend');
        Route::post('tenants/{team}/subscription/reactivate', [TenantSubscriptionController::class, 'reactivate'])->name('tenants.subscription.reactivate');
        Route::post('tenants/{team}/subscription/cancel', [TenantSubscriptionController::class, 'cancel'])->name('tenants.subscription.cancel');
        Route::post('tenants/{team}/subscription/extend-trial', [TenantSubscriptionController::class, 'extendTrial'])->name('tenants.subscription.extend-trial');

        // Manual feature overrides for a tenant.
        Route::put('tenants/{team}/features/{featureKey}', [TenantFeatureController::class, 'update'])->name('tenants.features.update');

        // Tenant member management.
        Route::post('tenants/{team}/members', [TenantMemberController::class, 'store'])->name('tenants.members.store');
        Route::put('tenants/{team}/members/{user}', [TenantMemberController::class, 'update'])->name('tenants.members.update');
        Route::delete('tenants/{team}/members/{user}', [TenantMemberController::class, 'destroy'])->name('tenants.members.destroy');
        Route::post('tenants/{team}/members/{user}/make-owner', [TenantMemberController::class, 'makeOwner'])->name('tenants.members.make-owner');

        // Plan catalog: tune per-meter allowances (incl. the asset cap).
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

        Route::post('impersonate/{team}', [ImpersonationController::class, 'store'])->name('impersonate.store');
        Route::delete('impersonate', [ImpersonationController::class, 'destroy'])->name('impersonate.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

require __DIR__.'/settings.php';
