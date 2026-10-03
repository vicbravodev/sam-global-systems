import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { slugifyCode } from '@/components/sam/settings/roles/lib';
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
import { postJson, readErrorMessage } from '@/lib/sam-fetch';

interface CreateRoleDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: PermissionGroups;
    teamSlug: string | null;
}

export function CreateRoleDialog({
    open,
    onOpenChange,
    groups,
    teamSlug,
}: CreateRoleDialogProps) {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    // El identificador se deriva del nombre hasta que alguien lo edita a mano.
    const [codeTouched, setCodeTouched] = useState(false);
    const [description, setDescription] = useState('');
    const [permissions, setPermissions] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);

    const reset = useCallback(() => {
        setName('');
        setCode('');
        setCodeTouched(false);
        setDescription('');
        setPermissions([]);
    }, []);

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            reset();
        }

        onOpenChange(next);
    };

    const toggle = useCallback((permissionCode: string, checked: boolean) => {
        setPermissions((current) =>
            checked
                ? [...current, permissionCode]
                : current.filter((c) => c !== permissionCode),
        );
    }, []);

    const submit = useCallback(async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        if (name.trim() === '' || code.trim() === '') {
            toast.error('Escribe un nombre para el rol.');

            return;
        }

        if (permissions.length === 0) {
            toast.error('Selecciona al menos un permiso.');

            return;
        }

        setSubmitting(true);

        const response = await postJson(`/${teamSlug}/settings/roles`, {
            name: name.trim(),
            code: code.trim(),
            description: description.trim() || null,
            permissions,
        });

        setSubmitting(false);

        if (response.ok || response.redirected) {
            toast.success('Rol creado.');
            reset();
            onOpenChange(false);
            router.reload({ only: ['roles'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para crear roles.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ?? 'No se pudo crear el rol.',
        );
    }, [teamSlug, name, code, description, permissions, reset, onOpenChange]);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Crear rol</DialogTitle>
                    <DialogDescription>
                        Define un rol personalizado para este equipo y asigna
                        sus permisos.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="role-name">Nombre</Label>
                        <Input
                            id="role-name"
                            value={name}
                            onChange={(e) => {
                                setName(e.target.value);

                                if (!codeTouched) {
                                    setCode(slugifyCode(e.target.value));
                                }
                            }}
                            placeholder="Turno noche"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="role-code">Identificador interno</Label>
                        <Input
                            id="role-code"
                            value={code}
                            onChange={(e) => {
                                setCodeTouched(true);
                                setCode(e.target.value);
                            }}
                            placeholder="turno_noche"
                            className="font-mono text-xs"
                        />
                        <p className="text-2xs text-fg-3">
                            Se genera a partir del nombre; sólo lo usan las
                            integraciones. No se puede cambiar después.
                        </p>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="role-description">
                            Descripción (opcional)
                        </Label>
                        <Input
                            id="role-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Operadores del turno nocturno"
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
                        onClick={() => handleOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? <Spinner className="size-3.5" /> : null}
                        Crear rol
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
