import { SettingsSection } from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import type { Team, TeamPermissions } from '@/types';

export interface TeamClosureSectionsProps {
    team: Team;
    permissions: TeamPermissions;
    onDelete: () => void;
}

/**
 * End of the team page: delete the team (when allowed and not personal) or,
 * for the owner of an operating account, how to cancel the service.
 */
export function TeamClosureSections({
    team,
    permissions,
    onDelete,
}: TeamClosureSectionsProps) {
    return (
        <>
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
                            onClick={onDelete}
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
                        (incidentes, integraciones e historial de facturación),
                        así que no se hace desde aquí. Contacta al equipo de SAM
                        para cancelar el servicio.
                    </p>
                </SettingsSection>
            ) : null}
        </>
    );
}
