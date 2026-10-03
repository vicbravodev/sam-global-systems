import { Head, Link, router, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import CancelInvitationModal from '@/components/cancel-invitation-modal';
import DeleteTeamModal from '@/components/delete-team-modal';
import InviteMemberModal from '@/components/invite-member-modal';
import RemoveMemberModal from '@/components/remove-member-modal';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { InvitationRow } from '@/components/sam/teams/invitation-row';
import { MemberRow } from '@/components/sam/teams/member-row';
import { TeamClosureSections } from '@/components/sam/teams/team-closure-sections';
import { TeamDetailsSection } from '@/components/sam/teams/team-details-section';
import { Button } from '@/components/ui/button';
import roleRoutes from '@/routes/access/roles';
import { edit, index } from '@/routes/teams';
import { update as updateMember } from '@/routes/teams/members';
import type {
    RoleOption,
    Team,
    TeamInvitation,
    TeamMember,
    TeamPermissions,
} from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
};

export default function TeamEdit({
    team,
    members,
    invitations,
    permissions,
    availableRoles,
}: Props) {
    const page = usePage();
    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [removeMemberDialogOpen, setRemoveMemberDialogOpen] = useState(false);
    const [memberToRemove, setMemberToRemove] = useState<TeamMember | null>(
        null,
    );
    const [cancelInvitationDialogOpen, setCancelInvitationDialogOpen] =
        useState(false);
    const [invitationToCancel, setInvitationToCancel] =
        useState<TeamInvitation | null>(null);

    // Enlace a los roles de acceso sólo si se pueden abrir (permiso de roles)
    // y el equipo que se mira es el activo.
    const currentSlug = page.props.currentTeam?.slug ?? null;
    const rolesHref =
        page.props.nav?.roles && currentSlug === team.slug
            ? roleRoutes.index.url(team.slug)
            : null;

    const updateMemberRole = (member: TeamMember, newRole: string) => {
        router.visit(updateMember([team.slug, member.id]), {
            data: { role: newRole },
            preserveScroll: true,
        });
    };

    const confirmRemoveMember = (member: TeamMember) => {
        setMemberToRemove(member);
        setRemoveMemberDialogOpen(true);
    };

    const confirmCancelInvitation = (invitation: TeamInvitation) => {
        setInvitationToCancel(invitation);
        setCancelInvitationDialogOpen(true);
    };

    return (
        <>
            <Head title={team.name} />
            <SettingsPage
                title={team.name}
                description="Quién pertenece a este equipo y quién puede administrarlo."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {members.length}
                        </span>{' '}
                        {members.length === 1 ? 'persona' : 'personas'}
                        {invitations.length > 0
                            ? ` · ${invitations.length} ${invitations.length === 1 ? 'invitación' : 'invitaciones'}`
                            : ''}
                    </span>
                }
                actions={
                    permissions.canCreateInvitation ? (
                        <Button
                            size="sm"
                            data-test="invite-member-button"
                            onClick={() => setInviteDialogOpen(true)}
                        >
                            <UserPlus /> Invitar persona
                        </Button>
                    ) : null
                }
            >
                {permissions.canUpdateTeam ? (
                    <TeamDetailsSection team={team} />
                ) : null}

                <SettingsSection
                    title="Personas"
                    description="El papel en el equipo sólo decide quién invita, quita personas o cambia el nombre."
                >
                    <p className="text-xs text-fg-3">
                        Qué puede ver y hacer cada persona en la operación
                        (incidentes, flota, facturación…) lo define su rol de
                        acceso
                        {rolesHref ? (
                            <>
                                {' en '}
                                <Link
                                    href={rolesHref}
                                    className="text-fg-1 underline underline-offset-4"
                                >
                                    Equipo y roles
                                </Link>
                            </>
                        ) : (
                            ' en Ajustes › Equipo y roles'
                        )}
                        .
                    </p>
                    <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface-1">
                        {members.map((member) => (
                            <MemberRow
                                key={member.id}
                                member={member}
                                permissions={permissions}
                                availableRoles={availableRoles}
                                onChangeRole={updateMemberRole}
                                onRemove={confirmRemoveMember}
                            />
                        ))}
                    </ul>
                </SettingsSection>

                {invitations.length > 0 ? (
                    <SettingsSection
                        title="Invitaciones pendientes"
                        description="Personas invitadas que aún no aceptan. La invitación les llegó por correo."
                    >
                        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface-1">
                            {invitations.map((invitation) => (
                                <InvitationRow
                                    key={invitation.id}
                                    invitation={invitation}
                                    canCancel={permissions.canCancelInvitation}
                                    onCancel={confirmCancelInvitation}
                                />
                            ))}
                        </ul>
                    </SettingsSection>
                ) : null}

                <TeamClosureSections
                    team={team}
                    permissions={permissions}
                    onDelete={() => setDeleteDialogOpen(true)}
                />
            </SettingsPage>

            {permissions.canCreateInvitation ? (
                <InviteMemberModal
                    team={team}
                    availableRoles={availableRoles}
                    open={inviteDialogOpen}
                    onOpenChange={setInviteDialogOpen}
                />
            ) : null}

            <RemoveMemberModal
                team={team}
                member={memberToRemove}
                open={removeMemberDialogOpen}
                onOpenChange={setRemoveMemberDialogOpen}
            />

            <CancelInvitationModal
                team={team}
                invitation={invitationToCancel}
                open={cancelInvitationDialogOpen}
                onOpenChange={setCancelInvitationDialogOpen}
            />

            {permissions.canDeleteTeam && !team.isPersonal ? (
                <DeleteTeamModal
                    team={team}
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                />
            ) : null}
        </>
    );
}

TeamEdit.layout = (props: { team: { name: string; slug: string } }) => ({
    breadcrumbs: [
        {
            title: 'Mis equipos',
            href: index(),
        },
        {
            title: props.team.name,
            href: edit(props.team.slug),
        },
    ],
});
