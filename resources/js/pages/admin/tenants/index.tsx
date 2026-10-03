import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CreateTenantSheet } from '@/components/sam/admin/tenants/create-tenant-sheet';
import {
    matchesQuick,
    matchesSearch,
} from '@/components/sam/admin/tenants/lib';
import { tenantColumns } from '@/components/sam/admin/tenants/tenant-columns';
import { TenantsEmpty } from '@/components/sam/admin/tenants/tenants-empty';
import { TenantsToolbar } from '@/components/sam/admin/tenants/tenants-toolbar';
import type {
    PlanOption,
    QuickFilter,
    TenantRow,
    TenantStats,
} from '@/components/sam/admin/tenants/types';
import { DataTable } from '@/components/sam/data-table/data-table';
import { ListPage } from '@/components/sam/list-page';
import { Button } from '@/components/ui/button';
import { store as impersonateStore } from '@/routes/admin/impersonate';
import {
    index as adminTenantsIndex,
    show as adminTenantShow,
} from '@/routes/admin/tenants';

interface AdminTenantsIndexProps {
    tenants: TenantRow[];
    stats: TenantStats;
    plans?: PlanOption[];
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
                    <TenantsToolbar
                        stats={stats}
                        quick={quick}
                        onToggleQuick={(value) =>
                            setQuick((current) =>
                                current === value ? null : value,
                            )
                        }
                        search={search}
                        onSearch={setSearch}
                        filtered={filtered}
                        onClearFilters={clearFilters}
                        shown={rows.length}
                    />
                ) : null}

                <DataTable
                    columns={tenantColumns({
                        entering,
                        onImpersonate: impersonate,
                    })}
                    rows={rows}
                    rowKey={(row) => row.id}
                    onRowClick={(row) =>
                        router.visit(adminTenantShow(row.slug).url)
                    }
                    defaultSort={{ key: 'created', dir: 'desc' }}
                    empty={
                        <TenantsEmpty
                            filtered={filtered}
                            onClearFilters={clearFilters}
                            onCreate={() => setCreateOpen(true)}
                        />
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
