import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { destroy as destroyMember } from '@/routes/teams/members';
import type { Team, TeamMember } from '@/types';

type Props = {
    team: Team;
    member: TeamMember | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RemoveMemberModal({
    team,
    member,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const removeMember = () => {
        if (!member) {
            return;
        }

        router.visit(destroyMember([team.slug, member.id]), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Quitar miembro del equipo"
            description={
                <>
                    ¿Seguro que quieres quitar a <strong>{member?.name}</strong>{' '}
                    de este equipo?
                </>
            }
            confirmLabel="Quitar miembro"
            cancelLabel="Cancelar"
            onConfirm={removeMember}
            processing={processing}
            confirmTestId="remove-member-confirm"
        />
    );
}
