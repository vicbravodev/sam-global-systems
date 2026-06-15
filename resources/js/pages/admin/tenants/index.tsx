import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Plus, UserCog } from 'lucide-react';
import { useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as impersonateStore } from '@/routes/admin/impersonate';
import {
    show as adminTenantShow,
    store as adminTenantStore,
} from '@/routes/admin/tenants';

interface TenantRow {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    membersCount: number;
    plan: string | null;
    subscriptionStatus: string | null;
    createdAt: string | null;
}

interface PlanOption {
    code: string;
    name: string;
}

interface Stats {
    total: number;
    active: number;
    trialing: number;
    pastDue: number;
}

interface AdminTenantsIndexProps {
    tenants: TenantRow[];
    stats: Stats;
    plans: PlanOption[];
}

const STATUS_LABEL: Record<string, string> = {
    active: 'Activa',
    trialing: 'Trial',
    past_due: 'Morosa',
    suspended: 'Suspendida',
    canceled: 'Cancelada',
    expired: 'Expirada',
};

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleDateString('es', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          });
}

function StatCard({ label, value }: { label: string; value: number }) {
    // D6: misma celda cockpit que la franja de KPIs del dashboard (F3.1):
    // sin borde por celda; la franja lleva el borde único y los hairlines.
    return (
        <div className="bg-surface-1 px-4 py-3">
            <div className="sam-meta">{label}</div>
            <div className="text-2xl font-semibold tabular-nums">{value}</div>
        </div>
    );
}

function CreateTenantDialog({
    open,
    onOpenChange,
    plans,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    plans: PlanOption[];
}) {
    const form = useForm({
        name: '',
        plan_code: '',
        owner_email: '',
        owner_name: '',
    });

    const submit = () => {
        form.post(adminTenantStore().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Crear tenant</DialogTitle>
                    <DialogDescription>
                        Da de alta una organización nueva y asigna a su
                        propietario. Si el email no existe, se crea el usuario y
                        se le envía un enlace para definir su contraseña.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="tenant-name">Nombre del tenant</Label>
                        <Input
                            id="tenant-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Acme Logistics"
                        />
                        {form.errors.name ? (
                            <p className="text-xs text-health-down">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="tenant-plan">Plan (opcional)</Label>
                        <Select
                            value={form.data.plan_code}
                            onValueChange={(v) => form.setData('plan_code', v)}
                        >
                            <SelectTrigger id="tenant-plan">
                                <SelectValue placeholder="Sin plan / trial manual" />
                            </SelectTrigger>
                            <SelectContent>
                                {plans.map((plan) => (
                                    <SelectItem
                                        key={plan.code}
                                        value={plan.code}
                                    >
                                        {plan.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.plan_code ? (
                            <p className="text-xs text-health-down">
                                {form.errors.plan_code}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="owner-email">
                            Email del propietario
                        </Label>
                        <Input
                            id="owner-email"
                            type="email"
                            value={form.data.owner_email}
                            onChange={(e) =>
                                form.setData('owner_email', e.target.value)
                            }
                            placeholder="owner@acme.com"
                            autoComplete="off"
                        />
                        {form.errors.owner_email ? (
                            <p className="text-xs text-health-down">
                                {form.errors.owner_email}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="owner-name">
                            Nombre del propietario (si es nuevo)
                        </Label>
                        <Input
                            id="owner-name"
                            value={form.data.owner_name}
                            onChange={(e) =>
                                form.setData('owner_name', e.target.value)
                            }
                            placeholder="Jane Doe"
                        />
                        {form.errors.owner_name ? (
                            <p className="text-xs text-health-down">
                                {form.errors.owner_name}
                            </p>
                        ) : null}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        Crear tenant
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AdminTenantsIndex({
    tenants,
    stats,
    plans,
}: AdminTenantsIndexProps) {
    const [createOpen, setCreateOpen] = useState(false);

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Tenants" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Tenants</h1>
                    <span className="sam-meta">{tenants.length} equipos</span>
                </div>
                <Button size="sm" onClick={() => setCreateOpen(true)}>
                    <Plus size={14} /> Crear tenant
                </Button>
            </header>

            <div className="flex-1 overflow-y-auto p-5">
                <div className="mb-5 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-4">
                    <StatCard label="Tenants" value={stats.total} />
                    <StatCard label="Activos" value={stats.active} />
                    <StatCard label="Trial" value={stats.trialing} />
                    <StatCard label="Morosos" value={stats.pastDue} />
                </div>

                <div className="overflow-hidden rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-2 text-left">
                            <tr className="sam-meta">
                                <th className="px-3 py-2 font-medium">
                                    Tenant
                                </th>
                                <th className="px-3 py-2 font-medium">Plan</th>
                                <th className="px-3 py-2 font-medium">
                                    Estado
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Miembros
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Creado
                                </th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.map((tenant) => (
                                <tr
                                    key={tenant.id}
                                    className="border-t border-border hover:bg-surface-2/50"
                                >
                                    <td className="px-3 py-2">
                                        <Link
                                            href={
                                                adminTenantShow(tenant.slug).url
                                            }
                                            className="flex items-center gap-2 font-medium hover:underline"
                                        >
                                            <Building2
                                                size={14}
                                                className="text-fg-3"
                                            />
                                            {tenant.name}
                                            {tenant.isPersonal ? (
                                                <span className="sam-meta rounded bg-surface-2 px-1.5 py-0.5">
                                                    personal
                                                </span>
                                            ) : null}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        {tenant.plan ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {tenant.subscriptionStatus
                                            ? (STATUS_LABEL[
                                                  tenant.subscriptionStatus
                                              ] ?? tenant.subscriptionStatus)
                                            : 'Sin suscripción'}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {tenant.membersCount}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {formatDate(tenant.createdAt)}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    impersonateStore(
                                                        tenant.slug,
                                                    ).url,
                                                )
                                            }
                                        >
                                            <UserCog size={13} /> Impersonar
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <CreateTenantDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                plans={plans}
            />
        </div>
    );
}
