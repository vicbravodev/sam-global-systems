<?php

use App\Http\Controllers\Access\MemberRoleController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\AI\AIEvaluationController;
use App\Http\Controllers\Incidents\IncidentAssignmentController;
use App\Http\Controllers\Incidents\IncidentCommentController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentInboxController;
use App\Http\Controllers\Incidents\IncidentResolutionController;
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

        Route::get('settings/roles', [RoleController::class, 'index'])->name('access.roles.index');
        Route::post('settings/roles', [RoleController::class, 'store'])->name('access.roles.store');
        Route::put('settings/roles/{role}', [RoleController::class, 'update'])->name('access.roles.update');
        Route::delete('settings/roles/{role}', [RoleController::class, 'destroy'])->name('access.roles.destroy');
        Route::put('settings/members/{membership}/role', [MemberRoleController::class, 'update'])->name('access.members.role.update');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

require __DIR__.'/settings.php';
