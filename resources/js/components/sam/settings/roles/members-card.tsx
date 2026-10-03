import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { fallbackRoleLabel } from '@/components/sam/settings/roles/lib';
import { SettingsSection } from '@/components/sam/settings/settings-page';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { teamRoleLabel } from '@/lib/labels';
import { putJson, readErrorMessage } from '@/lib/sam-fetch';
import { update as updateMemberRole } from '@/routes/access/members/role';
import type { RoleRow, TeamMemberRow } from '@/types/sam';

interface MembersCardProps {
    members: TeamMemberRow[];
    roles: RoleRow[];
    canManage: boolean;
    teamSlug: string | null;
}

export function MembersCard({
    members,
    roles,
    canManage,
    teamSlug,
}: MembersCardProps) {
    const [updatingId, setUpdatingId] = useState<number | null>(null);

    const changeRole = useCallback(
        async (member: TeamMemberRow, roleCode: string) => {
            if (teamSlug === null) {
                toast.error('No hay equipo activo.');

                return;
            }

            setUpdatingId(member.id);

            const response = await putJson(
                updateMemberRole.url([teamSlug, member.id]),
                { role_code: roleCode },
            );

            setUpdatingId(null);

            if (response.ok || response.redirected) {
                toast.success(`Rol de ${member.userName} actualizado.`);
                router.reload({ only: ['members'] });

                return;
            }

            if (response.status === 403) {
                toast.error(
                    'No tienes permisos para cambiar roles de miembros.',
                );

                return;
            }

            toast.error(
                (await readErrorMessage(response)) ??
                    'No se pudo actualizar el rol del miembro.',
            );
        },
        [teamSlug],
    );

    return (
        <SettingsSection
            title="Personas"
            description="El rol de acceso decide qué puede ver y hacer cada persona. Para invitar o quitar personas, ve a «Mis equipos»."
            actions={
                <span className="text-xs text-fg-3">
                    {members.length}{' '}
                    {members.length === 1 ? 'persona' : 'personas'}
                </span>
            }
        >
            <div className="overflow-hidden rounded-lg border border-border bg-surface-1">
                <ul className="divide-y divide-border">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 px-5 py-3"
                        >
                            <div className="min-w-0 flex-1 basis-52">
                                <div className="truncate text-sm font-medium text-fg-1">
                                    {member.userName}
                                </div>
                                <div className="sam-meta truncate">
                                    {member.userEmail}
                                    {member.legacyRole &&
                                    member.legacyRole !== 'member' ? (
                                        <span title="Administra las personas e invitaciones del equipo">
                                            {' · '}
                                            {teamRoleLabel(
                                                member.legacyRole,
                                            )}{' '}
                                            del equipo
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                            {canManage && !member.locked ? (
                                <div className="flex items-center gap-2">
                                    {updatingId === member.id ? (
                                        <Spinner className="size-3.5 text-fg-3" />
                                    ) : null}
                                    <Select
                                        value={member.roleCode ?? ''}
                                        onValueChange={(value) =>
                                            void changeRole(member, value)
                                        }
                                        disabled={updatingId === member.id}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-full sm:w-56"
                                            aria-label={`Rol de ${member.userName}`}
                                        >
                                            <SelectValue
                                                placeholder={fallbackRoleLabel(
                                                    member.legacyRole,
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {roles.map((role) => (
                                                <SelectItem
                                                    key={role.code}
                                                    value={role.code}
                                                >
                                                    {role.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : (
                                <Badge variant="secondary">
                                    {roles.find(
                                        (role) => role.code === member.roleCode,
                                    )?.name ??
                                        member.roleName ??
                                        fallbackRoleLabel(member.legacyRole)}
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </SettingsSection>
    );
}
