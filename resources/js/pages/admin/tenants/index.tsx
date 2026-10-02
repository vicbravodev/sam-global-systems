import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CircleCheck,
    Hourglass,
    PauseCircle,
    Plus,
    UserCog,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import {
    StagePill,
    SubscriptionPill,
} from '@/components/sam/admin-tenant-status';
import type { OnboardingStage } from '@/components/sam/admin-tenant-status';
import { DataTable } from '@/components/sam/data-table/data-table';
import type { DataTableColumn } from '@/components/sam/data-table/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { ClearFiltersButton, SearchInput } from '@/components/sam/list';
import { ListPage } from '@/components/sam/list-page';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
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
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate } from '@/lib/format';
import { DEFAULT_TENANT_TIMEZONE, TENANT_TIMEZONES } from '@/lib/timezones';
import { store as impersonateStore } from '@/routes/admin/impersonate';
import {
    index as adminTenantsIndex,
    show as adminTenantShow,
    store as adminTenantStore,
} from '@/routes/admin/tenants';

interface TenantRow {
    id: number;
    name: string;
    slug: string;
    membersCount: number;
    owner: { name: string; email: string; pendingAccess: boolean } | null;
    plan: string | null;
    subscriptionStatus: string | null;
    integrationsCount: number;
    monitoredAssets: number;
    stage: OnboardingStage;
    createdAt: string | null;
}

interface PlanOption {
    code: string;
    name: string;
}

interface Stats {
    total: number;
    operating: number;
    onboarding: number;
    pastDue: number;
    suspended: number;
}

interface AdminTenantsIndexProps {
    tenants: TenantRow[];
    stats: Stats;
    plans?: PlanOption[];
}

type QuickFilter = 'operating' | 'onboarding' | 'past_due' | 'suspended';

const NO_PLAN = '__none__';

function matchesQuick(row: TenantRow, filter: QuickFilter | null): boolean {
    switch (filter) {
        case 'operating':
            return row.stage === 'operating';
        case 'onboarding':
            return row.stage !== 'operating';
        case 'past_due':
            return row.subscriptionStatus === 'past_due';
        case 'suspended':
            return row.subscriptionStatus === 'suspended';
        default:
            return true;
    }
}

function matchesSearch(row: TenantRow, q: string | null): boolean {
    if (!q) {
        return true;
    }

    const needle = q.toLocaleLowerCase('es');

    return [row.name, row.slug, row.owner?.name, row.owner?.email]
        .filter((v): v is string => Boolean(v))
        .some((v) => v.toLocaleLowerCase('es').includes(needle));
}

function CreateTenantSheet({
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
        timezone: DEFAULT_TENANT_TIMEZONE,
        plan_code: NO_PLAN,
        owner_email: '',
        owner_name: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            plan_code: data.plan_code === NO_PLAN ? null : data.plan_code,
        }));
        form.post(adminTenantStore().url, {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <SheetHeader className="border-b border-border px-5 py-4">
                        <SheetTitle>Nuevo cliente</SheetTitle>
                        <SheetDescription>
                            Da de alta la empresa y a su responsable. Nace con
                            el paquete por defecto (reglas, escalación y
                            ajustes) listo.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">
                        <fieldset className="grid gap-4">
                            <legend className="sam-caps mb-1 text-fg-3">
                                Empresa
                            </legend>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-name">Nombre</Label>
                                <Input
                                    id="tenant-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Transportes del Norte"
                                    autoFocus
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-timezone">
                                    Zona horaria
                                </Label>
                                <Select
                                    value={form.data.timezone}
                                    onValueChange={(v) =>
                                        form.setData('timezone', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="tenant-timezone"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {TENANT_TIMEZONES.map((tz) => (
                                            <SelectItem
                                                key={tz.value}
                                                value={tz.value}
                                            >
                                                {tz.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-fg-3">
                                    Rige el horario silencioso, los reportes y
                                    el contexto de la IA.
                                </p>
                                <InputError message={form.errors.timezone} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-plan">
                                    Topes de plan
                                </Label>
                                <Select
                                    value={form.data.plan_code}
                                    onValueChange={(v) =>
                                        form.setData('plan_code', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="tenant-plan"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NO_PLAN}>
                                            Sin plan (sólo términos propios)
                                        </SelectItem>
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
                                <InputError message={form.errors.plan_code} />
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-4">
                            <legend className="sam-caps mb-1 text-fg-3">
                                Responsable
                            </legend>
                            <div className="grid gap-1.5">
                                <Label htmlFor="owner-email">Correo</Label>
                                <Input
                                    id="owner-email"
                                    type="email"
                                    value={form.data.owner_email}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_email',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="direccion@empresa.mx"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={form.errors.owner_email} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="owner-name">Nombre</Label>
                                <Input
                                    id="owner-name"
                                    value={form.data.owner_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Obligatorio si aún no tiene cuenta"
                                />
                                <InputError message={form.errors.owner_name} />
                            </div>
                        </fieldset>

                        <div className="rounded-lg border border-border bg-surface-2 px-4 py-3">
                            <p className="sam-caps mb-2 text-fg-3">
                                Qué pasa después
                            </p>
                            <ol className="grid list-decimal gap-1.5 pl-4 text-sm text-fg-2">
                                <li>
                                    El responsable recibe un correo para definir
                                    su contraseña (válido 7 días).
                                </li>
                                <li>
                                    Conecta su proveedor (Samsara) en
                                    Integraciones.
                                </li>
                                <li>
                                    Elige qué unidades vigilar; desde ahí se
                                    cobra por tracto-día.
                                </li>
                            </ol>
                        </div>
                    </div>

                    <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-5 py-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Crear cliente
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

export default function AdminTenantsIndex({
    tenants,
    stats,
    plans,
}: AdminTenantsIndexProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [quick, setQuick] = useState<QuickFilter | null>(null);
    const [search, setSearch] = useState<string | null>(null);
    const [entering, setEntering] = useState<number | null>(null);

    const rows = useMemo(
        () =>
            tenants.filter(
                (row) => matchesQuick(row, quick) && matchesSearch(row, search),
            ),
        [tenants, quick, search],
    );

    const toggle = (value: QuickFilter) => () =>
        setQuick((current) => (current === value ? null : value));
    const filtered = quick !== null || search !== null;
    const clearFilters = () => {
        setQuick(null);
        setSearch(null);
    };

    const impersonate = (row: TenantRow) => {
        router.post(
            impersonateStore(row.slug).url,
            {},
            {
                onStart: () => setEntering(row.id),
                onFinish: () => setEntering(null),
            },
        );
    };

    const columns: DataTableColumn<TenantRow>[] = [
        {
            key: 'name',
            header: 'Cliente',
            sortValue: (row) => row.name,
            cell: (row) => (
                <span className="flex min-w-0 items-center gap-2.5">
                    <EntityAvatar name={row.name} shape="square" size={26} />
                    <span className="min-w-0">
                        <span className="block truncate font-medium text-fg-1">
                            {row.name}
                        </span>
                        <span className="block truncate font-mono text-3xs text-fg-3">
                            {row.slug}
                        </span>
                    </span>
                </span>
            ),
        },
        {
            key: 'owner',
            header: 'Responsable',
            sortValue: (row) => row.owner?.name ?? null,
            cell: (row) =>
                row.owner ? (
                    <span className="block min-w-0">
                        <span className="block truncate text-fg-1">
                            {row.owner.name}
                        </span>
                        <span className="block truncate text-xs text-fg-3">
                            {row.owner.email}
                        </span>
                    </span>
                ) : (
                    <span className="text-fg-3">Sin responsable</span>
                ),
        },
        {
            key: 'stage',
            header: 'Estado',
            width: 'w-52',
            sortValue: (row) => row.stage,
            cell: (row) => <StagePill stage={row.stage} />,
        },
        {
            key: 'assets',
            header: 'Unidades',
            width: 'w-24',
            align: 'right',
            numeric: true,
            sortValue: (row) => row.monitoredAssets,
            cell: (row) => row.monitoredAssets,
        },
        {
            key: 'subscription',
            header: 'Suscripción',
            width: 'w-40',
            sortValue: (row) => row.subscriptionStatus,
            cell: (row) => (
                <span className="flex flex-col items-start gap-0.5">
                    <SubscriptionPill status={row.subscriptionStatus} />
                    {row.plan ? (
                        <span className="text-3xs text-fg-3">{row.plan}</span>
                    ) : null}
                </span>
            ),
        },
        {
            key: 'created',
            header: 'Alta',
            width: 'w-32',
            sortValue: (row) => row.createdAt,
            cell: (row) => (
                <span className="text-xs whitespace-nowrap text-fg-3 tabular-nums">
                    {formatDate(row.createdAt)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Acciones</span>,
            width: 'w-12',
            align: 'right',
            cell: (row) => (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label={`Entrar a la consola de ${row.name}`}
                            disabled={entering !== null}
                            onClick={(e) => {
                                e.stopPropagation();
                                impersonate(row);
                            }}
                        >
                            {entering === row.id ? (
                                <Spinner />
                            ) : (
                                <UserCog className="size-3.5" />
                            )}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Entrar a su consola</TooltipContent>
                </Tooltip>
            ),
        },
    ];

    return (
        <>
            <Head title="Clientes" />
            <ListPage
                title="Clientes"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {stats.total}
                        </span>{' '}
                        {stats.total === 1 ? 'cliente' : 'clientes'}
                    </span>
                }
                actions={
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus className="size-3.5" />
                        Nuevo cliente
                    </Button>
                }
            >
                {stats.total > 0 ? (
                    <>
                        <PulseStrip>
                            <PulseStat
                                label="Operando"
                                value={stats.operating}
                                tone="ok"
                                icon={CircleCheck}
                                onClick={toggle('operating')}
                                active={quick === 'operating'}
                            />
                            <PulseStat
                                label="En alta"
                                value={stats.onboarding}
                                hint="Por terminar"
                                tone={stats.onboarding > 0 ? 'warn' : 'neutral'}
                                icon={Hourglass}
                                onClick={toggle('onboarding')}
                                active={quick === 'onboarding'}
                            />
                            <PulseStat
                                label="Pago vencido"
                                value={stats.pastDue}
                                tone={
                                    stats.pastDue > 0 ? 'critical' : 'neutral'
                                }
                                icon={AlertTriangle}
                                onClick={toggle('past_due')}
                                active={quick === 'past_due'}
                            />
                            <PulseStat
                                label="Suspendidos"
                                value={stats.suspended}
                                icon={PauseCircle}
                                onClick={toggle('suspended')}
                                active={quick === 'suspended'}
                            />
                        </PulseStrip>

                        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                            <SearchInput
                                value={search}
                                onApply={setSearch}
                                placeholder="Buscar cliente o responsable…"
                                delay={150}
                                className="w-full sm:w-80"
                            />
                            {filtered ? (
                                <ClearFiltersButton onClick={clearFilters} />
                            ) : null}
                            <span className="ml-auto text-xs text-fg-3 tabular-nums">
                                {rows.length} de {stats.total}
                            </span>
                        </div>
                    </>
                ) : null}

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    onRowClick={(row) =>
                        router.visit(adminTenantShow(row.slug).url)
                    }
                    defaultSort={{ key: 'created', dir: 'desc' }}
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Building2}
                                title="Sin resultados"
                                description="Ningún cliente coincide con la búsqueda o el filtro."
                                action={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        Limpiar filtros
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Building2}
                                title="Da de alta tu primer cliente"
                                description="Crea la empresa y a su responsable; recibirá un correo para entrar y conectar su flota."
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="size-3.5" />
                                        Nuevo cliente
                                    </Button>
                                }
                            />
                        )
                    }
                />
            </ListPage>

            <CreateTenantSheet
                open={createOpen}
                onOpenChange={setCreateOpen}
                plans={plans ?? []}
            />
        </>
    );
}

AdminTenantsIndex.layout = {
    breadcrumbs: [{ title: 'Clientes', href: adminTenantsIndex().url }],
};
