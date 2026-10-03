import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import type { PermissionGroups } from '@/components/sam/settings/roles/lib';
import { PermissionPicker } from '@/components/sam/settings/roles/permission-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { putJson, readErrorMessage } from '@/lib/sam-fetch';
import type { RoleRow } from '@/types/sam';

interface EditRoleDialogProps {
    role: RoleRow | null;
    onOpenChange: (open: boolean) => void;
    groups: PermissionGroups;
    teamSlug: string | null;
}

export function EditRoleDialog({
    role,
    onOpenChange,
    groups,
    teamSlug,
}: EditRoleDialogProps) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [permissions, setPermissions] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [hydratedId, setHydratedId] = useState<number | null>(null);

    // Sync local form state when a different role is opened.
    if (role !== null && role.id !== hydratedId) {
        setHydratedId(role.id);
        setName(role.name);
        setDescription(role.description ?? '');
        setPermissions(role.permissions);
    }

    const toggle = useCallback((permissionCode: string, checked: boolean) => {
        setPermissions((current) =>
            checked
                ? [...current, permissionCode]
                : current.filter((c) => c !== permissionCode),
        );
    }, []);

    const submit = useCallback(async () => {
        if (role === null || teamSlug === null) {
            return;
        }

        if (permissions.length === 0) {
            toast.error('Selecciona al menos un permiso.');

            return;
        }

        // System roles cannot be renamed: the backend rejects any payload
        // containing the `name` key, so it is only sent for custom roles.
        const body: Record<string, unknown> = {
            description: description.trim() || null,
            permissions,
        };

        if (!role.isSystem) {
            body.name = name.trim();
        }

        setSubmitting(true);

        const response = await putJson(
            `/${teamSlug}/settings/roles/${role.id}`,
            body,
        );

        setSubmitting(false);

        if (response.ok || response.redirected) {
            toast.success('Rol actualizado.');
            onOpenChange(false);
            router.reload({ only: ['roles'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para editar roles.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo actualizar el rol.',
        );
    }, [role, teamSlug, name, description, permissions, onOpenChange]);

    // D-17: al cerrar se descarta el borrador local (incluido `hydratedId`),
    // de modo que reabrir el mismo rol vuelve a hidratar desde el estado del
    // servidor en lugar de mostrar cambios sin guardar.
    const handleClose = () => {
        setHydratedId(null);
        setName('');
        setDescription('');
        setPermissions([]);
        onOpenChange(false);
    };

    return (
        <Dialog
            open={role !== null}
            onOpenChange={(next) => {
                if (!next) {
                    handleClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Editar rol</DialogTitle>
                    <DialogDescription>
                        Ajusta el nombre, la descripción y los permisos del rol.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-role-name">Nombre</Label>
                        <Input
                            id="edit-role-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            disabled={role?.isSystem}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-role-description">
                            Descripción
                        </Label>
                        <Input
                            id="edit-role-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Permisos</Label>
                        <PermissionPicker
                            groups={groups}
                            selected={permissions}
                            onToggle={toggle}
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={handleClose}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? <Spinner className="size-3.5" /> : null}
                        Guardar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
