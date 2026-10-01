<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Teams\AcceptTeamInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\AcceptTeamInvitationRequest;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrada de usuarios por invitación. Con el auto-registro cerrado, es la
 * única forma de que alguien nuevo cree su cuenta: el enlace del correo prueba
 * la posesión del buzón, por eso el alta deja el email verificado.
 *
 * GET muestra la página (nunca cambia estado); aceptar y registrarse son POST
 * con CSRF.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, TeamInvitation $invitation): Response
    {
        $user = $request->user();
        $problem = AcceptTeamInvitation::problem($invitation);

        $mode = match (true) {
            $problem !== null => 'invalid',
            $user !== null && User::normalizeEmail($user->email) === User::normalizeEmail($invitation->email) => 'accept',
            $user !== null => 'wrong_account',
            User::findByEmail($invitation->email) !== null => 'login',
            default => 'register',
        };

        if ($mode === 'login') {
            // Tras iniciar sesión, Fortify vuelve aquí para aceptar con POST.
            $request->session()->put('url.intended', route('invitations.show', $invitation));
        }

        return Inertia::render('auth/accept-invitation', [
            'mode' => $mode,
            'code' => $invitation->code,
            'email' => $invitation->email,
            'teamName' => $invitation->team?->name,
            'roleLabel' => $invitation->role->label(),
            'inviterName' => $invitation->inviter?->name,
            'problem' => $problem,
        ]);
    }

    public function accept(
        AcceptTeamInvitationRequest $request,
        TeamInvitation $invitation,
        AcceptTeamInvitation $acceptTeamInvitation,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        $team = $acceptTeamInvitation->handle($user, $invitation);

        return to_route('dashboard', ['current_team' => $team->slug]);
    }

    public function register(
        Request $request,
        TeamInvitation $invitation,
        CreateNewUser $createNewUser,
        AcceptTeamInvitation $acceptTeamInvitation,
    ): RedirectResponse {
        if ($request->user() !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Ya tienes una sesión iniciada. Cierra sesión para crear una cuenta nueva.',
            ]);
        }

        if (($problem = AcceptTeamInvitation::problem($invitation)) !== null) {
            throw ValidationException::withMessages(['invitation' => $problem]);
        }

        if (User::findByEmail($invitation->email) !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Ya existe una cuenta con este correo. Inicia sesión para aceptar la invitación.',
            ]);
        }

        [$user, $team] = DB::transaction(function () use ($request, $invitation, $createNewUser, $acceptTeamInvitation) {
            // El email SIEMPRE sale de la invitación, nunca del formulario.
            $user = $createNewUser->create([
                'name' => (string) $request->input('name'),
                'email' => $invitation->email,
                'password' => (string) $request->input('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            return [$user, $acceptTeamInvitation->handle($user, $invitation)];
        });

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard', ['current_team' => $team->slug]);
    }
}
