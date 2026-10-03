import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Mail, UserPlus, X } from 'lucide-react';
import { useState } from 'react';
import CancelInvitationModal from '@/components/cancel-invitation-modal';
import DeleteTeamModal from '@/components/delete-team-modal';
import InputError from '@/components/input-error';
import InviteMemberModal from '@/components/invite-member-modal';
import RemoveMemberModal from '@/components/remove-member-modal';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/lib/initials';
import roleRoutes from '@/routes/access/roles';
import { edit, index, update } from '@/routes/teams';
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
                    <SettingsSection
                        title="Datos del equipo"
                        description="El nombre se muestra en el selector de equipos y en los correos de invitación."
                    >
                        <Form {...update.form(team.slug)}>
                            {({ errors, processing }) => (
                                <FormCard>
                                    <Field
                                        label="Nombre del equipo"
                                        htmlFor="name"
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            data-test="team-name-input"
                                            defaultValue={team.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </Field>
                                    <FormActions>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            data-test="team-save-button"
                                            disabled={processing}
                                        >
                                            Guardar cambios
                                        </Button>
                                    </FormActions>
                                </FormCard>
                            )}
                        </Form>
                    </SettingsSection>
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
                            <li
                                key={member.id}
                                data-test="member-row"
                                className="flex flex-wrap items-center gap-3 px-5 py-3"
                            >
                                <Avatar className="size-9">
                                    {member.avatar ? (
                                        <AvatarImage
                                            src={member.avatar}
                                            alt={member.name}
                                        />
                                    ) : null}
                                    <AvatarFallback>
                                        {getInitials(member.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium text-fg-1">
                                        {member.name}
                                    </div>
                                    <div className="truncate text-xs text-fg-3">
                                        {member.email}
                                    </div>
                                </div>
                                <div className="flex items-center gap-1">
                                    {member.role !== 'owner' &&
                                    permissions.canUpdateMember ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    data-test="member-role-trigger"
                                                >
                                                    {member.role_label}
                                                    <ChevronDown className="size-4 opacity-50" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {availableRoles.map((role) => (
                                                    <DropdownMenuItem
                                                        key={role.value}
                                                        data-test="member-role-option"
                                                        onSelect={() =>
                                                            updateMemberRole(
                                                                member,
                                                                role.value,
                                                            )
                                                        }
                                                    >
                                                        {role.label}
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : (
                                        <span className="rounded-sm bg-surface-3 px-2 py-1 text-xs text-fg-2">
                                            {member.role_label}
                                        </span>
                                    )}
                                    {member.role !== 'owner' &&
                                    permissions.canRemoveMember ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-fg-3 hover:text-severity-critical"
                                                    aria-label={`Quitar a ${member.name}`}
                                                    data-test="member-remove-button"
                                                    onClick={() =>
                                                        confirmRemoveMember(
                                                            member,
                                                        )
                                                    }
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>Quitar del equipo</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : null}
                                </div>
                            </li>
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
                                <li
                                    key={invitation.id}
                                    data-test="invitation-row"
                                    className="flex items-center gap-3 px-5 py-3"
                                >
                                    <div className="grid size-9 shrink-0 place-items-center rounded-full bg-surface-3">
                                        <Mail className="size-4 text-fg-3" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium text-fg-1">
                                            {invitation.email}
                                        </div>
                                        <div className="text-xs text-fg-3">
                                            {invitation.role_label}
                                        </div>
                                    </div>
                                    {permissions.canCancelInvitation ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-fg-3 hover:text-severity-critical"
                                                    aria-label={`Cancelar la invitación de ${invitation.email}`}
                                                    data-test="invitation-cancel-button"
                                                    onClick={() =>
                                                        confirmCancelInvitation(
                                                            invitation,
                                                        )
                                                    }
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>Cancelar invitación</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </SettingsSection>
                ) : null}

                {permissions.canDeleteTeam && !team.isPersonal ? (
                    <SettingsSection
                        title="Eliminar equipo"
                        description="Borra el equipo para siempre. No se puede deshacer."
                        tone="danger"
                    >
                        <div className="flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-lg border border-severity-critical/30 bg-severity-critical/5 p-4">
                            <p className="text-sm text-fg-2">
                                Se pierden sus personas, invitaciones y
                                configuración.
                            </p>
                            <Button
                                variant="destructive"
                                size="sm"
                                data-test="delete-team-button"
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                Eliminar equipo
                            </Button>
                        </div>
                    </SettingsSection>
                ) : null}

                {!permissions.canDeleteTeam &&
                !team.isPersonal &&
                team.role === 'owner' ? (
                    <SettingsSection
                        title="Dar de baja la cuenta"
                        description="Este equipo es una cuenta activa de SAM."
                    >
                        <p className="max-w-3xl text-sm text-fg-3">
                            Dar de baja la cuenta elimina la operación completa
                            (incidentes, integraciones e historial de
                            facturación), así que no se hace desde aquí.
                            Contacta al equipo de SAM para cancelar el servicio.
                        </p>
                    </SettingsSection>
                ) : null}
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
