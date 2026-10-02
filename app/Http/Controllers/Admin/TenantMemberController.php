<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\UpdateTeamMemberRole;
use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\ProvisionTenantUser;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TenantAccessInvitation;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cross-tenant member management for the SaaS operator. Reuses the Team
 * membership primitives; every mutation is audited under the security category.
 */
class TenantMemberController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function store(Request $request, Team $team, ProvisionTenantUser $provisionUser, #[CurrentUser] User $actor): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($request->input('email'))]);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        $existing = User::findByEmail($data['email']);

        if ($existing !== null && $team->members()->where('users.id', $existing->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya es miembro de este tenant.',
            ]);
        }

        // Un correo desconocido ya no se rechaza: se da de alta la cuenta y
        // recibe su enlace de acceso (como el dueño al crear el cliente).
        ['user' => $user, 'created' => $created] = DB::transaction(function () use ($team, $data, $provisionUser, $request, $actor): array {
            $result = $provisionUser->execute($data['email'], $data['name'] ?? null);
            $team->members()->attach($result['user'], ['role' => $data['role']]);

            if ($result['created']) {
                $result['user']->switchTeam($team);
            }

            $this->record($request, $actor, $team, 'tenant.member_added',
                "{$result['user']->email} añadido al tenant {$team->name} como {$data['role']}".($result['created'] ? ' (cuenta nueva).' : '.'),
                ['member_email' => $result['user']->email, 'role' => $data['role'], 'user_created' => $result['created']]);

            return $result;
        });

        app(AuthorizeAction::class)->invalidateCache($user->id, $team->id);

        if ($user->email_verified_at === null) {
            $user->notify(new TenantAccessInvitation($team->id, $actor->id));

            return $this->back($team, $created
                ? "Cuenta creada para {$user->email}; le enviamos su enlace de acceso."
                : "{$user->email} añadido; aún no activa su cuenta, le reenviamos el enlace.");
        }

        return $this->back($team, 'Miembro añadido.');
    }

    /**
     * Reenvía el correo de bienvenida con un enlace NUEVO (el anterior queda
     * inválido). Sólo tiene sentido para quien aún no activó su cuenta.
     */
    public function sendAccess(Request $request, Team $team, User $user, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->ensureMember($team, $user);

        if ($user->email_verified_at !== null) {
            throw ValidationException::withMessages([
                'member' => "{$user->email} ya activó su cuenta; si olvidó su contraseña puede recuperarla desde el inicio de sesión.",
            ]);
        }

        $user->notify(new TenantAccessInvitation($team->id, $actor->id));

        $this->record($request, $actor, $team, 'tenant.member_access_resent',
            "Enlace de acceso reenviado a {$user->email} ({$team->name}).",
            ['member_email' => $user->email]);

        return $this->back($team, "Enlace de acceso reenviado a {$user->email}.");
    }

    public function update(Request $request, Team $team, User $user, UpdateTeamMemberRole $updateTeamMemberRole, #[CurrentUser] User $actor): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        $this->ensureMember($team, $user);

        if ($team->owner()?->is($user) === true) {
            throw ValidationException::withMessages([
                'role' => 'Usa "hacer propietario" para reasignar al owner.',
            ]);
        }

        $updateTeamMemberRole->handle($team, $user, TeamRole::from($data['role']));
        app(AuthorizeAction::class)->invalidateCache($user->id, $team->id);

        $this->record($request, $actor, $team, 'tenant.member_role_changed',
            "Rol de {$user->email} en {$team->name} cambiado a {$data['role']}.",
            ['member_email' => $user->email, 'role' => $data['role']]);

        return $this->back($team, 'Rol actualizado.');
    }

    public function destroy(Request $request, Team $team, User $user, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->ensureMember($team, $user);

        if ($team->owner()?->is($user) === true) {
            throw ValidationException::withMessages([
                'member' => 'No se puede quitar al propietario del tenant.',
            ]);
        }

        DB::transaction(function () use ($team, $user) {
            $team->memberships()->where('user_id', $user->id)->delete();

            $user->switchAwayFrom($team);
        });

        app(AuthorizeAction::class)->invalidateCache($user->id, $team->id);

        $this->record($request, $actor, $team, 'tenant.member_removed',
            "{$user->email} removido del tenant {$team->name}.",
            ['member_email' => $user->email]);

        return $this->back($team, 'Miembro removido.');
    }

    public function makeOwner(Request $request, Team $team, User $user, UpdateTeamMemberRole $updateTeamMemberRole, #[CurrentUser] User $actor): RedirectResponse
    {
        $membership = $team->memberships()->where('user_id', $user->id)->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'member' => 'El usuario no es miembro de este tenant.',
            ]);
        }

        $previousOwner = DB::transaction(function () use ($team, $user, $updateTeamMemberRole): ?User {
            $currentOwner = $team->owner();

            if ($currentOwner !== null && ! $currentOwner->is($user)) {
                $updateTeamMemberRole->handle($team, $currentOwner, TeamRole::Admin);
            }

            $updateTeamMemberRole->handle($team, $user, TeamRole::Owner);

            return $currentOwner;
        });

        app(AuthorizeAction::class)->invalidateCache($user->id, $team->id);

        if ($previousOwner !== null) {
            app(AuthorizeAction::class)->invalidateCache($previousOwner->id, $team->id);
        }

        $this->record($request, $actor, $team, 'tenant.owner_reassigned',
            "Propiedad del tenant {$team->name} reasignada a {$user->email}.",
            ['member_email' => $user->email]);

        return $this->back($team, 'Propietario reasignado.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(Request $request, User $actor, Team $team, string $action, string $summary, array $metadata): void
    {
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $actor->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $team->id,
            summary: $summary,
            teamId: $team->id,
            metadata: ['actor_email' => $actor->email] + $metadata,
            signature: $action.':'.$team->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    private function ensureMember(Team $team, User $user): void
    {
        if (! $team->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'El usuario no es miembro de este tenant.',
            ]);
        }
    }

    private function back(Team $team, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('admin.tenants.show', $team);
    }
}
