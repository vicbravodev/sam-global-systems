import { Head, router, usePage } from '@inertiajs/react';
import {
    Loader2,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    deleteJson,
    postJson,
    putJson,
    readErrorMessage,
} from '@/lib/sam-fetch';
import type { RolePermissionOption, RoleRow, TeamMemberRow } from '@/types/sam';

type PermissionGroups = Record<string, RolePermissionOption[]>;

interface RolesIndexProps {
    roles: RoleRow[];
    permissions: PermissionGroups;
    members: TeamMemberRow[];
}

const LEGACY_ROLE_LABEL: Record<string, string> = {
    owner: 'Propietario (heredado)',
    admin: 'Admin (heredado)',
    member: 'Miembro (heredado)',
};

// ---- Permission checkbox tree ----

interface PermissionPickerProps {
    groups: PermissionGroups;
    selected: string[];
    onToggle: (code: string, checked: boolean) => void;
    disabled?: boolean;
}

function PermissionPicker({
    groups,
    selected,
    onToggle,
    disabled,
}: PermissionPickerProps) {
    return (
        <div className="grid max-h-72 gap-3 overflow-y-auto rounded-md border border-border bg-surface-2 p-3">
            {Object.entries(groups).map(([module, options]) => (
                <div key={module}>
                    <div className="sam-caps mb-1.5">{module}</div>
                    <div className="grid gap-1.5">
                        {options.map((option) => (
                            <label
                                key={option.code}
                                className="flex items-start gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={selected.includes(option.code)}
                                    onCheckedChange={(checked) =>
                                        onToggle(option.code, checked === true)
                                    }
                                    disabled={disabled}
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="block leading-tight">
                                        {option.name}
                                    </span>
                                    <span className="block font-mono text-[10px] text-fg-3">
                                        {option.code}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ---- Create role dialog ----

interface CreateRoleDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: PermissionGroups;
    teamSlug: string | null;
}

function CreateRoleDialog({
    open,
    onOpenChange,
    groups,
    teamSlug,
}: CreateRoleDialogProps) {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [description, setDescription] = useState('');
    const [permissions, setPermissions] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);

    const reset = useCallback(() => {
        setName('');
        setCode('');
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
            toast.error('Nombre y código son obligatorios.');

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
                            onChange={(e) => setName(e.target.value)}
                            placeholder="Turno noche"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="role-code">Código</Label>
                        <Input
                            id="role-code"
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            placeholder="night_shift"
                            className="font-mono"
                        />
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
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Crear rol
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Edit role dialog ----

interface EditRoleDialogProps {
    role: RoleRow | null;
    onOpenChange: (open: boolean) => void;
    groups: PermissionGroups;
    teamSlug: string | null;
}

function EditRoleDialog({
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

    return (
        <Dialog
            open={role !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Editar rol</DialogTitle>
                    <DialogDescription>
                        {role?.isSystem
                            ? 'Los roles de sistema no se pueden renombrar; solo puedes ajustar sus permisos.'
                            : 'Ajusta el nombre, la descripción y los permisos del rol.'}
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
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Guardar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Delete role dialog ----

interface DeleteRoleDialogProps {
    role: RoleRow | null;
    onOpenChange: (open: boolean) => void;
    teamSlug: string | null;
}

function DeleteRoleDialog({
    role,
    onOpenChange,
    teamSlug,
}: DeleteRoleDialogProps) {
    const [submitting, setSubmitting] = useState(false);

    const confirm = useCallback(async () => {
        if (role === null || teamSlug === null) {
            return;
        }

        setSubmitting(true);

        const response = await deleteJson(
            `/${teamSlug}/settings/roles/${role.id}`,
        );

        setSubmitting(false);

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
    }, [role, teamSlug, onOpenChange]);

    return (
        <Dialog
            open={role !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Eliminar rol</DialogTitle>
                    <DialogDescription>
                        {role
                            ? `¿Seguro que deseas eliminar el rol "${role.name}"? Los miembros que lo tengan asignado perderán sus permisos.`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirm}
                        disabled={submitting}
                    >
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Role card ----

interface RoleCardProps {
    role: RoleRow;
    canManage: boolean;
    onEdit: () => void;
    onDelete: () => void;
}

function RoleCard({ role, canManage, onEdit, onDelete }: RoleCardProps) {
    return (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-surface-1 p-4">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                        {role.isSystem ? (
                            <Lock size={12} className="shrink-0 text-fg-3" />
                        ) : null}
                        <span className="truncate text-sm font-semibold">
                            {role.name}
                        </span>
                    </div>
                    <div className="font-mono text-[10px] text-fg-3">
                        {role.code}
                    </div>
                </div>
                <Badge variant={role.isSystem ? 'secondary' : 'outline'}>
                    {role.isSystem ? 'Sistema' : 'Personalizado'}
                </Badge>
                {canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7 shrink-0"
                                aria-label={`Acciones del rol ${role.name}`}
                            >
                                <MoreHorizontal size={14} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil size={13} /> Editar
                            </DropdownMenuItem>
                            {!role.isSystem ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={onDelete}
                                    >
                                        <Trash2 size={13} /> Eliminar
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            {role.description ? (
                <p className="text-xs text-fg-2">{role.description}</p>
            ) : null}

            <div className="sam-meta">
                {role.permissions.length}{' '}
                {role.permissions.length === 1 ? 'permiso' : 'permisos'}
            </div>
        </div>
    );
}

// ---- Members section ----

interface MembersCardProps {
    members: TeamMemberRow[];
    roles: RoleRow[];
    canManage: boolean;
    teamSlug: string | null;
}

function MembersCard({
    members,
    roles,
    canManage,
    teamSlug,
}: MembersCardProps) {
    const [updatingId, setUpdatingId] = useState<number | null>(null);

    const changeRole = useCallback(
        async (member: TeamMemberRow, roleCode: string) => {
            if (teamSlug === null) {
                toast.error('No hay equipo activo.');

                return;
            }

            setUpdatingId(member.id);

            const response = await putJson(
                `/${teamSlug}/settings/members/${member.id}/role`,
                { role_code: roleCode },
            );

            setUpdatingId(null);

            if (response.ok || response.redirected) {
                toast.success(`Rol de ${member.userName} actualizado.`);
                router.reload({ only: ['members'] });

                return;
            }

            if (response.status === 403) {
                toast.error(
                    'No tienes permisos para cambiar roles de miembros.',
                );

                return;
            }

            toast.error(
                (await readErrorMessage(response)) ??
                    'No se pudo actualizar el rol del miembro.',
            );
        },
        [teamSlug],
    );

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Users size={15} /> Miembros del equipo
                </CardTitle>
                <span className="sam-meta">
                    {members.length}{' '}
                    {members.length === 1 ? 'miembro' : 'miembros'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                <ul className="divide-y divide-border">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {member.userName}
                                </div>
                                <div className="sam-meta truncate">
                                    {member.userEmail}
                                </div>
                            </div>
                            {canManage ? (
                                <div className="flex items-center gap-2">
                                    {updatingId === member.id ? (
                                        <Loader2
                                            size={14}
                                            className="animate-spin text-fg-3"
                                        />
                                    ) : null}
                                    <Select
                                        value={member.roleCode ?? ''}
                                        onValueChange={(value) =>
                                            void changeRole(member, value)
                                        }
                                        disabled={updatingId === member.id}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-44"
                                            aria-label={`Rol de ${member.userName}`}
                                        >
                                            <SelectValue
                                                placeholder={
                                                    LEGACY_ROLE_LABEL[
                                                        member.legacyRole ?? ''
                                                    ] ?? 'Sin rol'
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {roles.map((role) => (
                                                <SelectItem
                                                    key={role.code}
                                                    value={role.code}
                                                >
                                                    {role.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : (
                                <Badge variant="secondary">
                                    {member.roleName ??
                                        LEGACY_ROLE_LABEL[
                                            member.legacyRole ?? ''
                                        ] ??
                                        'Sin rol'}
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

// ---- Page ----

export default function RolesIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as RolesIndexProps;
    const roles = useMemo(() => pageProps.roles ?? [], [pageProps.roles]);
    const groups = pageProps.permissions ?? {};
    const members = pageProps.members ?? [];
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('users.manage');

    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<RoleRow | null>(null);
    const [deleting, setDeleting] = useState<RoleRow | null>(null);

    const customCount = roles.filter((role) => !role.isSystem).length;

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Equipo y roles" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Equipo y roles</h1>
                    <span className="sam-meta">
                        {roles.length} roles · {customCount} personalizados
                    </span>
                </div>
                {canManage ? (
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus size={14} /> Crear rol
                    </Button>
                ) : null}
            </header>

            <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-5">
                <section>
                    <div className="mb-2 flex items-center gap-2">
                        <ShieldCheck size={15} className="text-fg-3" />
                        <h2 className="sam-h3 m-0">Roles</h2>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {roles.map((role) => (
                            <RoleCard
                                key={role.id}
                                role={role}
                                canManage={canManage}
                                onEdit={() => setEditing(role)}
                                onDelete={() => setDeleting(role)}
                            />
                        ))}
                    </div>
                </section>

                <MembersCard
                    members={members}
                    roles={roles}
                    canManage={canManage}
                    teamSlug={teamSlug}
                />
            </div>

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
        </div>
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
