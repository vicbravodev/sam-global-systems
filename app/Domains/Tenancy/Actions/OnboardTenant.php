<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TenantAccessInvitation;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de un cliente desde la consola del super-admin, de punta a punta y
 * atómica: dueño (existente o nuevo), tenant con su paquete por defecto
 * (`CreateTenant` → `TenantCreated`), auditoría con actor y, si el dueño aún
 * no activó su cuenta, el correo de bienvenida con su enlace (sale sólo tras
 * el commit: si algo falla no queda un usuario huérfano con correo enviado).
 */
class OnboardTenant
{
    public function __construct(
        private readonly ProvisionTenantUser $provisionUser,
        private readonly CreateTenant $createTenant,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * @param  array{name: string, owner_email: string, owner_name?: string|null, plan_code?: string|null, timezone?: string|null}  $data
     */
    public function execute(array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Team
    {
        [$team, $owner, $ownerCreated] = DB::transaction(function () use ($data, $actor, $ip, $userAgent): array {
            ['user' => $owner, 'created' => $ownerCreated] = $this->provisionUser->execute(
                $data['owner_email'],
                $data['owner_name'] ?? null,
            );

            $team = $this->createTenant->execute(
                name: $data['name'],
                owner: $owner,
                planCode: $data['plan_code'] ?? null,
                timezone: $data['timezone'] ?? null,
            );

            // Un dueño recién creado aterriza en su empresa, no en su team
            // personal vacío. A un usuario existente no se le mueve.
            if ($ownerCreated) {
                $owner->switchTeam($team);
            }

            $this->audit->execute(
                actorType: AuditActorType::User,
                actorId: $actor->id,
                action: 'tenant.created',
                category: AuditCategory::Security,
                entityType: Team::class,
                entityId: $team->id,
                summary: "Tenant {$team->name} creado; dueño {$owner->email}".($ownerCreated ? ' (cuenta nueva).' : ' (cuenta existente).'),
                teamId: $team->id,
                metadata: [
                    'actor_email' => $actor->email,
                    'owner_email' => $owner->email,
                    'owner_created' => $ownerCreated,
                    'plan_code' => $data['plan_code'] ?? null,
                ],
                signature: 'tenant.created:'.$team->id.':'.Str::uuid()->toString(),
                ipAddress: $ip,
                userAgent: $userAgent,
            );

            return [$team, $owner, $ownerCreated];
        });

        $needsAccess = $owner->email_verified_at === null;

        if ($needsAccess) {
            $owner->notify(new TenantAccessInvitation($team->id, $actor->id));
        }

        TenantContext::for($team->id, fn () => SystemLog::ok('tenancy.tenant.onboarded',
            input: ['team_id' => $team->id, 'actor_id' => $actor->id, 'plan_code' => $data['plan_code'] ?? null],
            result: ['owner_id' => $owner->id, 'owner_created' => $ownerCreated, 'access_link_queued' => $needsAccess],
        ));

        return $team;
    }
}
