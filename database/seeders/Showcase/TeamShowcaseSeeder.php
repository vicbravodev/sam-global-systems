<?php

namespace Database\Seeders\Showcase;

use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Miembros del tenant con un usuario por rol RBAC + invitaciones pendientes.
 *
 * Reutiliza al miembro que ya tenga ese rol (en ServiExpress JC:
 * admin@serviexpress.test y monitor@serviexpress.test) y sólo crea los que
 * faltan como `{rol}@{slug}.test` / `password`. Nunca cambia el rol ni el
 * team actual de un usuario existente.
 *
 * Marcador: el email. Las invitaciones se buscan por (team, email).
 */
class TeamShowcaseSeeder extends ShowcaseStep
{
    /**
     * rol lógico => [código RBAC, rol de team, nombre, teléfono]
     *
     * @var array<string, array{0: string, 1: TeamRole, 2: string, 3: string}>
     */
    private const ROLES = [
        'admin' => ['tenant_admin', TeamRole::Owner, 'Andrea Salinas', '+12025550101'],
        'supervisor' => ['supervisor', TeamRole::Admin, 'Sergio Treviño', '+12025550102'],
        'monitor' => ['monitorista', TeamRole::Member, 'María Garza', '+12025550103'],
        'monitor_night' => ['monitorista', TeamRole::Member, 'Luis Cantú', '+12025550104'],
        'analyst' => ['analyst', TeamRole::Member, 'Paola Rdz. Analista', '+12025550105'],
        'billing' => ['billing_manager', TeamRole::Member, 'Jorge Facturación', '+12025550106'],
        'viewer' => ['viewer', TeamRole::Member, 'Valeria Observadora', '+12025550107'],
    ];

    public function run(): void
    {
        $team = $this->ctx->team;
        $roles = Role::query()->whereNull('team_id')->pluck('id', 'code');

        foreach (self::ROLES as $logical => [$rbac, $teamRole, $name, $phone]) {
            if ($this->ctx->light && ! in_array($logical, ['admin', 'monitor'], true)) {
                continue;
            }

            $user = $this->existingMemberWithRole($roles[$rbac] ?? null, $logical)
                ?? $this->createMember($logical, $rbac, $teamRole, $name, $phone, $roles[$rbac] ?? null);

            $this->ctx->users[$logical] = $user;
        }

        $this->seedInvitations();

        $this->ctx->info('Equipo: '.count($this->ctx->users).' miembros por rol.');
    }

    private function existingMemberWithRole(?int $roleId, string $logical): ?User
    {
        if ($roleId === null) {
            return null;
        }

        $taken = array_map(fn (User $user) => $user->id, $this->ctx->users);

        $membership = Membership::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('role_id', $roleId)
            ->whereNotIn('user_id', $taken === [] ? [0] : $taken)
            ->orderBy('id')
            ->first();

        // El turno nocturno siempre es una persona distinta del monitorista de día.
        if ($membership === null || ($logical === 'monitor_night' && isset($this->ctx->users['monitor']) && $membership->user_id === $this->ctx->users['monitor']->id)) {
            return null;
        }

        return User::query()->find($membership->user_id);
    }

    private function createMember(string $logical, string $rbac, TeamRole $teamRole, string $name, string $phone, ?int $roleId): User
    {
        $team = $this->ctx->team;
        $email = Str::of($logical)->replace('_', '.')->append('@', $team->slug, '.test')->toString();

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
            ]);
            $user->forceFill([
                'email_verified_at' => $this->ctx->now->subDays(120),
                'phone' => $phone,
                'phone_verified_at' => $this->ctx->now->subDays(100),
                'current_team_id' => $team->id,
            ])->save();
            $this->ctx->count('users');
        }

        $isMember = Membership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            // Un team sólo tiene un owner: si ya existe, el admin del showcase entra como admin.
            $hasOwner = Membership::query()
                ->where('team_id', $team->id)
                ->where('role', TeamRole::Owner->value)
                ->exists();

            Membership::query()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => ($teamRole === TeamRole::Owner && $hasOwner ? TeamRole::Admin : $teamRole)->value,
                'role_id' => $roleId,
            ]);
            $this->ctx->count('team_members');
        }

        return $user;
    }

    private function seedInvitations(): void
    {
        if ($this->ctx->light) {
            return;
        }

        $inviter = $this->ctx->user('admin');
        $invitations = [
            ['email' => 'coordinador.nocturno', 'role' => TeamRole::Member, 'sent' => 2, 'expires' => 5],
            ['email' => 'gerente.operaciones', 'role' => TeamRole::Admin, 'sent' => 6, 'expires' => 1],
            // Vencida y sin aceptar: la pantalla de equipo la sigue listando.
            ['email' => 'auditor.externo', 'role' => TeamRole::Member, 'sent' => 12, 'expires' => -5],
        ];

        foreach ($invitations as $invitation) {
            $email = $invitation['email'].'@'.$this->ctx->team->slug.'.test';

            $exists = TeamInvitation::query()
                ->where('team_id', $this->ctx->team->id)
                ->where('email', $email)
                ->exists();

            if ($exists) {
                continue;
            }

            $created = TeamInvitation::query()->create([
                'team_id' => $this->ctx->team->id,
                'email' => $email,
                'role' => $invitation['role']->value,
                'invited_by' => $inviter->id,
                'expires_at' => $this->ctx->now->addDays($invitation['expires']),
            ]);
            $created->forceFill([
                'created_at' => $this->ctx->now->subDays($invitation['sent']),
                'updated_at' => $this->ctx->now->subDays($invitation['sent']),
            ])->save();
            $this->ctx->count('team_invitations');
        }
    }
}
