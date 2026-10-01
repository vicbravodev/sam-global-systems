<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\UpdateTeamMemberRole;
use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cross-tenant member management for the SaaS operator. Reuses the Team
 * membership primitives; every mutation is audited under the security category.
 */
class TenantMemberController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function store(Request $request, Team $team, #[CurrentUser] User $actor): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($request->input('email'))]);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        $user = User::findByEmail($data['email']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => __('validation.exists', ['attribute' => 'email']),
            ]);
        }

        if ($team->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya es miembro de este tenant.',
            ]);
        }

        $team->members()->attach($user, ['role' => $data['role']]);

        $this->record($request, $actor, $team, 'tenant.member_added',
            "{$user->email} añadido al tenant {$team->name} como {$data['role']}.",
            ['member_email' => $user->email, 'role' => $data['role']]);

        return $this->back($team, 'Miembro añadido.');
    }

    public function update(Request $request, Team $team, User $user, UpdateTeamMemberRole $updateTeamMemberRole, #[CurrentUser] User $actor): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        if ($team->owner()?->is($user) === true) {
            throw ValidationException::withMessages([
                'role' => 'Usa "hacer propietario" para reasignar al owner.',
            ]);
        }

        $updateTeamMemberRole->handle($team, $user, TeamRole::from($data['role']));

        $this->record($request, $actor, $team, 'tenant.member_role_changed',
            "Rol de {$user->email} en {$team->name} cambiado a {$data['role']}.",
            ['member_email' => $user->email, 'role' => $data['role']]);

        return $this->back($team, 'Rol actualizado.');
    }

    public function destroy(Request $request, Team $team, User $user, #[CurrentUser] User $actor): RedirectResponse
    {
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

        DB::transaction(function () use ($team, $user, $updateTeamMemberRole) {
            $currentOwner = $team->owner();

            if ($currentOwner !== null && ! $currentOwner->is($user)) {
                $updateTeamMemberRole->handle($team, $currentOwner, TeamRole::Admin);
            }

            $updateTeamMemberRole->handle($team, $user, TeamRole::Owner);
        });

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

    private function back(Team $team, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.tenants.show', $team)
            ->with('status', $message);
    }
}
