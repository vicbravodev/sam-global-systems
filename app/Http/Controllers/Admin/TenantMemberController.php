<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
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

    public function store(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($team->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya es miembro de este tenant.',
            ]);
        }

        $team->members()->attach($user, ['role' => $data['role']]);

        $this->record($request, $team, 'tenant.member_added',
            "{$user->email} añadido al tenant {$team->name} como {$data['role']}.",
            ['member_email' => $user->email, 'role' => $data['role']]);

        return $this->back($team, 'Miembro añadido.');
    }

    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([TeamRole::Admin->value, TeamRole::Member->value])],
        ]);

        if ($team->owner()?->is($user)) {
            throw ValidationException::withMessages([
                'role' => 'Usa "hacer propietario" para reasignar al owner.',
            ]);
        }

        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => TeamRole::from($data['role'])]);

        $this->record($request, $team, 'tenant.member_role_changed',
            "Rol de {$user->email} en {$team->name} cambiado a {$data['role']}.",
            ['member_email' => $user->email, 'role' => $data['role']]);

        return $this->back($team, 'Rol actualizado.');
    }

    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        if ($team->owner()?->is($user)) {
            throw ValidationException::withMessages([
                'member' => 'No se puede quitar al propietario del tenant.',
            ]);
        }

        $team->memberships()->where('user_id', $user->id)->delete();

        if ($user->isCurrentTeam($team) && $user->personalTeam()) {
            $user->switchTeam($user->personalTeam());
        }

        $this->record($request, $team, 'tenant.member_removed',
            "{$user->email} removido del tenant {$team->name}.",
            ['member_email' => $user->email]);

        return $this->back($team, 'Miembro removido.');
    }

    public function makeOwner(Request $request, Team $team, User $user): RedirectResponse
    {
        $membership = $team->memberships()->where('user_id', $user->id)->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'member' => 'El usuario no es miembro de este tenant.',
            ]);
        }

        DB::transaction(function () use ($team, $user) {
            $currentOwner = $team->owner();

            if ($currentOwner && ! $currentOwner->is($user)) {
                $team->memberships()
                    ->where('user_id', $currentOwner->id)
                    ->update(['role' => TeamRole::Admin]);
            }

            $team->memberships()
                ->where('user_id', $user->id)
                ->update(['role' => TeamRole::Owner]);
        });

        $this->record($request, $team, 'tenant.owner_reassigned',
            "Propiedad del tenant {$team->name} reasignada a {$user->email}.",
            ['member_email' => $user->email]);

        return $this->back($team, 'Propietario reasignado.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(Request $request, Team $team, string $action, string $summary, array $metadata): void
    {
        $actor = $request->user();

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $actor->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: $summary,
            teamId: (int) $team->id,
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
