import { router } from '@inertiajs/react';
import { Mail, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import type {
    Tenant,
    Member,
    Pending,
} from '@/components/sam/admin/tenant/types';
import { visit } from '@/components/sam/admin/tenant/visit';
import { BillingPill } from '@/components/sam/billing/panel';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { FormField } from '@/components/sam/form-field';
import { MetaChip } from '@/components/sam/meta-chip';
import { Panel } from '@/components/sam/panel';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { teamRoleLabel } from '@/lib/labels';
import {
    destroy as removeMemberRoute,
    makeOwner as makeOwnerRoute,
    sendAccess as sendAccessRoute,
    store as addMemberRoute,
    update as updateMemberRoute,
} from '@/routes/admin/tenants/members';

export function MembersTab({
    tenant,
    members,
    confirm,
}: {
    tenant: Tenant;
    members: Member[];
    confirm: (p: Pending) => void;
}) {
    const [email, setEmail] = useState('');
    const [name, setName] = useState('');
    const [role, setRole] = useState('member');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);

    const add = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            addMemberRoute(tenant.slug).url,
            { email, name: name || null, role },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setAdding(true),
                onFinish: () => setAdding(false),
                onSuccess: () => {
                    setEmail('');
                    setName('');
                    setErrors({});
                },
                onError: setErrors,
            },
        );
    };

    const run =
        (id: number, fn: () => Promise<void>) => async (): Promise<void> => {
            setBusy(id);

            try {
                await fn();
            } finally {
                setBusy(null);
            }
        };

    const sendAccess = (member: Member) =>
        run(member.id, () =>
            visit(
                'post',
                sendAccessRoute({ team: tenant.slug, user: member.id }).url,
                {},
                (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo reenviar el acceso.',
                    ),
            ),
        )();

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Miembros"
                description="Quién entra a la consola de este cliente."
            >
                {members.length === 0 ? (
                    <EmptyState
                        icon={UserPlus}
                        title="Sin miembros"
                        description="Añade al responsable y a su equipo de monitoreo."
                        className="py-6"
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {members.map((member) => {
                            const isOwner = member.role === 'owner';

                            return (
                                <li
                                    key={member.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5"
                                >
                                    <EntityAvatar name={member.name} />
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                                            <span className="truncate">
                                                {member.name}
                                            </span>
                                            {member.pendingAccess ? (
                                                <BillingPill tone="warn">
                                                    Acceso pendiente
                                                </BillingPill>
                                            ) : null}
                                        </p>
                                        <p className="truncate text-xs text-fg-3">
                                            {member.email}
                                        </p>
                                    </div>

                                    {member.pendingAccess ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={busy === member.id}
                                            onClick={() => sendAccess(member)}
                                        >
                                            {busy === member.id ? (
                                                <Spinner />
                                            ) : (
                                                <Mail className="size-3.5" />
                                            )}
                                            Reenviar acceso
                                        </Button>
                                    ) : null}

                                    {tenant.isPersonal || isOwner ? (
                                        <MetaChip>
                                            {teamRoleLabel(member.role)}
                                        </MetaChip>
                                    ) : (
                                        <span className="flex items-center gap-1">
                                            <Select
                                                value={member.role}
                                                onValueChange={(next) =>
                                                    confirm({
                                                        title: `Cambiar el rol de ${member.name}`,
                                                        description: `Pasará a ${teamRoleLabel(next)} en ${tenant.name}.`,
                                                        confirmLabel:
                                                            'Cambiar rol',
                                                        tone: 'default',
                                                        run: () =>
                                                            visit(
                                                                'put',
                                                                updateMemberRoute(
                                                                    {
                                                                        team: tenant.slug,
                                                                        user: member.id,
                                                                    },
                                                                ).url,
                                                                { role: next },
                                                            ),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    className="h-7 w-36"
                                                    aria-label={`Rol de ${member.name}`}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="admin">
                                                        Administrador
                                                    </SelectItem>
                                                    <SelectItem value="member">
                                                        Miembro
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Hacer propietario a ${member.name}`,
                                                        description:
                                                            'El propietario actual pasa a Administrador. El propietario controla facturación y miembros.',
                                                        confirmLabel:
                                                            'Hacer propietario',
                                                        tone: 'default',
                                                        run: () =>
                                                            visit(
                                                                'post',
                                                                makeOwnerRoute({
                                                                    team: tenant.slug,
                                                                    user: member.id,
                                                                }).url,
                                                            ),
                                                    })
                                                }
                                            >
                                                Hacer propietario
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Quitar a ${member.name}`,
                                                        description: `${member.email} deja de tener acceso a ${tenant.name}.`,
                                                        confirmLabel: 'Quitar',
                                                        tone: 'destructive',
                                                        run: () =>
                                                            visit(
                                                                'delete',
                                                                removeMemberRoute(
                                                                    {
                                                                        team: tenant.slug,
                                                                        user: member.id,
                                                                    },
                                                                ).url,
                                                            ),
                                                    })
                                                }
                                            >
                                                Quitar
                                            </Button>
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Panel>

            {tenant.isPersonal ? null : (
                <Panel
                    size="lg"
                    bodyClassName="p-4"
                    title="Añadir miembro"
                    description="Si el correo no tiene cuenta, se crea y recibe su enlace de acceso (7 días)."
                    className="self-start"
                >
                    <form onSubmit={add} className="grid gap-3">
                        <FormField
                            label="Correo"
                            htmlFor="member-email"
                            error={errors.email}
                        >
                            <Input
                                id="member-email"
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="monitor@empresa.mx"
                                required
                            />
                        </FormField>
                        <FormField
                            label="Nombre"
                            htmlFor="member-name"
                            error={errors.name}
                        >
                            <Input
                                id="member-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="Obligatorio si no tiene cuenta"
                            />
                        </FormField>
                        <FormField
                            label="Rol"
                            htmlFor="member-role"
                            error={errors.role}
                        >
                            <Select value={role} onValueChange={setRole}>
                                <SelectTrigger
                                    id="member-role"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">
                                        Administrador
                                    </SelectItem>
                                    <SelectItem value="member">
                                        Miembro
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </FormField>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={adding || email === ''}
                        >
                            {adding && <Spinner />}
                            Añadir miembro
                        </Button>
                    </form>
                </Panel>
            )}
        </div>
    );
}
