import { Head, router, usePage } from '@inertiajs/react';
import {
    Loader2,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { teamRoleLabel } from '@/lib/labels';
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

/** Etiqueta de reserva cuando el miembro aún no tiene rol de acceso. */
function fallbackRoleLabel(legacyRole: string | null): string {
    return legacyRole
        ? `${teamRoleLabel(legacyRole)} (sin rol de acceso)`
        : 'Sin rol';
}

/** Nombre legible de cada módulo de permisos (clave = Permission.module). */
const MODULE_LABELS: Record<string, string> = {
    ai: 'Inteligencia artificial',
    assets: 'Flota',
    audit: 'Auditoría',
    automation: 'Automatizaciones',
    config: 'Configuración',
    context: 'Contexto operativo',
    copilot: 'SAM Copilot',
    decisions: 'Reglas y decisiones',
    drivers: 'Conductores',
    geofences: 'Zonas',
    incidents: 'Incidentes',
    integrations: 'Integraciones',
    notifications: 'Avisos',
    reports: 'Reportes y analítica',
    tenancy: 'Empresa y facturación',
    users: 'Personas y roles',
};

function moduleLabel(module: string): string {
    return MODULE_LABELS[module] ?? module;
}

/**
 * Los roles predefinidos vienen del catálogo de plataforma con la palabra
 * "tenant"; para quien opera, su cuenta es "la empresa".
 */
function dejargon(text: string): string {
    return text
        .replace(/\bdel tenant\b/gi, 'de la empresa')
        .replace(/\bel tenant\b/gi, 'la empresa')
        .replace(/\btenant\b/gi, 'empresa');
}

function humanizeRole(role: RoleRow): RoleRow {
    return role.isSystem
        ? {
              ...role,
              name: dejargon(role.name),
              description: role.description
                  ? dejargon(role.description)
                  : role.description,
          }
        : role;
}

/** Deriva un identificador interno (snake_case) a partir del nombre. */
function slugifyCode(name: string): string {
    return name
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 50);
}

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
                    <div className="sam-caps mb-1.5">{moduleLabel(module)}</div>
                    <div className="grid gap-1.5">
                        {options.map((option) => (
                            <label
                                key={option.code}
                                title={option.code}
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
                                <span className="min-w-0 leading-tight">
                                    {option.name}
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

// ---- Role card ----

interface RoleCardProps {
    role: RoleRow;
    memberCount: number;
    canManage: boolean;
    onEdit: () => void;
    onDelete: () => void;
}

function RoleCard({
    role,
    memberCount,
    canManage,
    onEdit,
    onDelete,
}: RoleCardProps) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-surface-1 p-4">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1" title={role.code}>
                    <div className="flex items-center gap-1.5">
                        {role.isSystem ? (
                            <Lock
                                size={12}
                                className="shrink-0 text-fg-3"
                                aria-label="Rol predefinido"
                            />
                        ) : null}
                        <span className="truncate text-sm font-semibold text-fg-1">
                            {role.name}
                        </span>
                    </div>
                    <span className="text-2xs text-fg-3">
                        {role.isSystem
                            ? 'Predefinido por SAM'
                            : 'Creado por tu equipo'}
                    </span>
                </div>
                {canManage && role.editable ? (
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
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onDelete}
                            >
                                <Trash2 size={13} /> Eliminar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            {role.description ? (
                <p className="text-xs text-fg-2">{role.description}</p>
            ) : null}

            <div className="mt-auto flex flex-wrap gap-x-3 gap-y-1 pt-1 text-2xs text-fg-3">
                <span>
                    <span className="font-medium text-fg-2 tabular-nums">
                        {role.permissions.length}
                    </span>{' '}
                    {role.permissions.length === 1 ? 'permiso' : 'permisos'}
                </span>
                <span>
                    <span className="font-medium text-fg-2 tabular-nums">
                        {memberCount}
                    </span>{' '}
                    {memberCount === 1 ? 'persona' : 'personas'}
                </span>
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
        <SettingsSection
            title="Personas"
            description="El rol de acceso decide qué puede ver y hacer cada persona. Para invitar o quitar personas, ve a «Mis equipos»."
            actions={
                <span className="text-xs text-fg-3">
                    {members.length}{' '}
                    {members.length === 1 ? 'persona' : 'personas'}
                </span>
            }
        >
            <div className="overflow-hidden rounded-lg border border-border bg-surface-1">
                <ul className="divide-y divide-border">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 px-5 py-3"
                        >
                            <div className="min-w-0 flex-1 basis-52">
                                <div className="truncate text-sm font-medium text-fg-1">
                                    {member.userName}
                                </div>
                                <div className="sam-meta truncate">
                                    {member.userEmail}
                                    {member.legacyRole &&
                                    member.legacyRole !== 'member' ? (
                                        <span title="Administra las personas e invitaciones del equipo">
                                            {' · '}
                                            {teamRoleLabel(
                                                member.legacyRole,
                                            )}{' '}
                                            del equipo
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                            {canManage && !member.locked ? (
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
                                            className="w-full sm:w-56"
                                            aria-label={`Rol de ${member.userName}`}
                                        >
                                            <SelectValue
                                                placeholder={fallbackRoleLabel(
                                                    member.legacyRole,
                                                )}
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
                                    {roles.find(
                                        (role) => role.code === member.roleCode,
                                    )?.name ??
                                        member.roleName ??
                                        fallbackRoleLabel(member.legacyRole)}
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </SettingsSection>
    );
}

// ---- Page ----

export default function RolesIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as RolesIndexProps;
    const roles = useMemo(
        () => (pageProps.roles ?? []).map(humanizeRole),
        [pageProps.roles],
    );
    const groups = pageProps.permissions ?? {};
    const members = useMemo(() => pageProps.members ?? [], [pageProps.members]);
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('users.manage');

    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<RoleRow | null>(null);
    const [deleting, setDeleting] = useState<RoleRow | null>(null);

    const customCount = roles.filter((role) => !role.isSystem).length;
    const membersByRole = useMemo(() => {
        const counts = new Map<string, number>();

        for (const member of members) {
            if (member.roleCode) {
                counts.set(
                    member.roleCode,
                    (counts.get(member.roleCode) ?? 0) + 1,
                );
            }
        }

        return counts;
    }, [members]);

    return (
        <>
            <Head title="Equipo y roles" />
            <SettingsPage
                title="Equipo y roles"
                description="Quién forma parte de tu equipo y qué puede hacer cada persona en SAM."
                width="wide"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {roles.length}
                        </span>{' '}
                        roles · {customCount}{' '}
                        {customCount === 1 ? 'propio' : 'propios'}
                    </span>
                }
                actions={
                    canManage ? (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus size={14} /> Crear rol
                        </Button>
                    ) : null
                }
            >
                <MembersCard
                    members={members}
                    roles={roles}
                    canManage={canManage}
                    teamSlug={teamSlug}
                />

                <SettingsSection
                    title="Roles"
                    description="Cada rol agrupa permisos. Los predefinidos no se pueden borrar; crea uno propio si ninguno encaja."
                >
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {roles.map((role) => (
                            <RoleCard
                                key={role.id}
                                role={role}
                                memberCount={membersByRole.get(role.code) ?? 0}
                                canManage={canManage}
                                onEdit={() => setEditing(role)}
                                onDelete={() => setDeleting(role)}
                            />
                        ))}
                    </div>
                </SettingsSection>
            </SettingsPage>

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
        </>
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
