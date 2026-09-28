import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus, UsersRound } from 'lucide-react';
import CreateTeamModal from '@/components/create-team-modal';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { edit, index } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    teams: Team[];
    canCreateTeam: boolean;
};

export default function TeamsIndex({ teams, canCreateTeam }: Props) {
    return (
        <>
            <Head title="Mis equipos" />
            <SettingsPage
                title="Mis equipos"
                description="Las cuentas de SAM a las que perteneces y tu papel en cada una."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {teams.length}
                        </span>{' '}
                        {teams.length === 1 ? 'equipo' : 'equipos'}
                    </span>
                }
                actions={
                    // C3: crear equipos (Team = cuenta) es exclusivo del
                    // superadmin; el resto sólo ve sus membresías.
                    canCreateTeam ? (
                        <CreateTeamModal>
                            <Button size="sm" data-test="teams-new-team-button">
                                <Plus /> Nuevo equipo
                            </Button>
                        </CreateTeamModal>
                    ) : null
                }
            >
                <SettingsSection
                    title="Equipos"
                    description="Entra a un equipo para ver a sus personas e invitaciones."
                >
                    {teams.length === 0 ? (
                        <div className="rounded-lg border border-border bg-surface-1">
                            <EmptyState
                                icon={UsersRound}
                                title="Todavía no perteneces a ningún equipo"
                                description="Pide a un administrador de tu empresa que te invite; recibirás un correo para unirte."
                            />
                        </div>
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface-1">
                            {teams.map((team) => (
                                <li key={team.id} data-test="team-row">
                                    <Link
                                        href={edit(team.slug)}
                                        data-test={
                                            team.role === 'member'
                                                ? 'team-view-button'
                                                : 'team-edit-button'
                                        }
                                        className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-surface-2"
                                    >
                                        <div className="grid size-9 shrink-0 place-items-center rounded-md bg-surface-3 text-xs font-semibold text-fg-2">
                                            {team.name
                                                .slice(0, 2)
                                                .toUpperCase()}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate text-sm font-medium text-fg-1">
                                                    {team.name}
                                                </span>
                                                {team.isPersonal ? (
                                                    <span className="rounded-sm bg-surface-3 px-1.5 py-0.5 text-2xs text-fg-3">
                                                        Personal
                                                    </span>
                                                ) : null}
                                            </div>
                                            <span className="text-xs text-fg-3">
                                                {team.roleLabel}
                                            </span>
                                        </div>
                                        <span className="hidden text-xs text-fg-3 sm:inline">
                                            {team.role === 'member'
                                                ? 'Ver'
                                                : 'Administrar'}
                                        </span>
                                        <ChevronRight
                                            className="size-4 shrink-0 text-fg-3"
                                            aria-hidden
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

TeamsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Mis equipos',
            href: index(),
        },
    ],
};
