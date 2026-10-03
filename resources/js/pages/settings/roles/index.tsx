import { Head, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CreateRoleDialog } from '@/components/sam/settings/roles/create-role-dialog';
import { DeleteRoleDialog } from '@/components/sam/settings/roles/delete-role-dialog';
import { EditRoleDialog } from '@/components/sam/settings/roles/edit-role-dialog';
import { humanizeRole } from '@/components/sam/settings/roles/lib';
import type { PermissionGroups } from '@/components/sam/settings/roles/lib';
import { MembersCard } from '@/components/sam/settings/roles/members-card';
import { RoleCard } from '@/components/sam/settings/roles/role-card';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import type { RoleRow, TeamMemberRow } from '@/types/sam';

interface RolesIndexProps {
    roles: RoleRow[];
    permissions: PermissionGroups;
    members: TeamMemberRow[];
}

export default function RolesIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as RolesIndexProps;
    const roles = useMemo(
        () => (pageProps.roles ?? []).map(humanizeRole),
        [pageProps.roles],
    );
    const groups = pageProps.permissions ?? {};
    const members = useMemo(() => pageProps.members ?? [], [pageProps.members]);
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('users.manage');

    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<RoleRow | null>(null);
    const [deleting, setDeleting] = useState<RoleRow | null>(null);

    const customCount = roles.filter((role) => !role.isSystem).length;
    const membersByRole = useMemo(() => {
        const counts = new Map<string, number>();

        for (const member of members) {
            if (member.roleCode) {
                counts.set(
                    member.roleCode,
                    (counts.get(member.roleCode) ?? 0) + 1,
                );
            }
        }

        return counts;
    }, [members]);

    return (
        <>
            <Head title="Equipo y roles" />
            <SettingsPage
                title="Equipo y roles"
                description="Quién forma parte de tu equipo y qué puede hacer cada persona en SAM."
                width="wide"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {roles.length}
                        </span>{' '}
                        roles · {customCount}{' '}
                        {customCount === 1 ? 'propio' : 'propios'}
                    </span>
                }
                actions={
                    canManage ? (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus size={14} /> Crear rol
                        </Button>
                    ) : null
                }
            >
                <MembersCard
                    members={members}
                    roles={roles}
                    canManage={canManage}
                    teamSlug={teamSlug}
                />

                <SettingsSection
                    title="Roles"
                    description="Cada rol agrupa permisos. Los predefinidos no se pueden borrar; crea uno propio si ninguno encaja."
                >
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {roles.map((role) => (
                            <RoleCard
                                key={role.id}
                                role={role}
                                memberCount={membersByRole.get(role.code) ?? 0}
                                canManage={canManage}
                                onEdit={() => setEditing(role)}
                                onDelete={() => setDeleting(role)}
                            />
                        ))}
                    </div>
                </SettingsSection>
            </SettingsPage>

            <CreateRoleDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                groups={groups}
                teamSlug={teamSlug}
            />
            <EditRoleDialog
                role={editing}
                onOpenChange={() => setEditing(null)}
                groups={groups}
                teamSlug={teamSlug}
            />
            <DeleteRoleDialog
                role={deleting}
                onOpenChange={() => setDeleting(null)}
                teamSlug={teamSlug}
            />
        </>
    );
}

RolesIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Equipo y roles',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/settings/roles`
                : '/settings/roles',
        },
    ],
});
