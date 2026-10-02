import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { destroy as destroyInvitation } from '@/routes/teams/invitations';
import type { Team, TeamInvitation } from '@/types';

type Props = {
    team: Team;
    invitation: TeamInvitation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function CancelInvitationModal({
    team,
    invitation,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const cancelInvitation = () => {
        if (!invitation) {
            return;
        }

        router.visit(destroyInvitation([team.slug, invitation.id]), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Cancelar invitación"
            description={
                <>
                    ¿Seguro que quieres cancelar la invitación para{' '}
                    <strong>{invitation?.email}</strong>?
                </>
            }
            confirmLabel="Cancelar invitación"
            cancelLabel="Mantener invitación"
            onConfirm={cancelInvitation}
            processing={processing}
            confirmTestId="cancel-invitation-confirm"
        />
    );
}
