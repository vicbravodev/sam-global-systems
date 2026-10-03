import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { deleteJson, readErrorMessage } from '@/lib/sam-fetch';
import type { RoleRow } from '@/types/sam';

interface DeleteRoleDialogProps {
    role: RoleRow | null;
    onOpenChange: (open: boolean) => void;
    teamSlug: string | null;
}

export function DeleteRoleDialog({
    role,
    onOpenChange,
    teamSlug,
}: DeleteRoleDialogProps) {
    const confirm = async () => {
        if (role === null || teamSlug === null) {
            return;
        }

        const response = await deleteJson(
            `/${teamSlug}/settings/roles/${role.id}`,
        );

        if (response.ok || response.redirected) {
            toast.success('Rol eliminado.');
            onOpenChange(false);
            router.reload({ only: ['roles', 'members'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para eliminar roles.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ?? 'No se pudo eliminar el rol.',
        );
    };

    return (
        <ConfirmDialog
            open={role !== null}
            onOpenChange={onOpenChange}
            title="Eliminar rol"
            description={
                role
                    ? `¿Seguro que deseas eliminar el rol "${role.name}"? Los miembros que lo tengan asignado perderán sus permisos.`
                    : ''
            }
            onConfirm={confirm}
        />
    );
}
