import { Head, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/sam/data-table/data-table';
import type { DataTableColumn } from '@/components/sam/data-table/data-table';
import {
    ClearFiltersButton,
    FilterDropdown,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { StatusBadge } from '@/components/sam/status-badge';
import { Switch } from '@/components/ui/switch';
import { formatDateTime } from '@/lib/format';
import type { Tone } from '@/lib/tone';
import { index as auditIndex } from '@/routes/admin/audit';
import type { ListPagination } from '@/types/pagination';

interface AuditEntry {
    id: number;
    action: string;
    actionLabel: string;
    category: string;
    categoryLabel: string | null;
    summary: string;
    team: string | null;
    actorEmail: string | null;
    occurredAt: string | null;
}

interface Filters {
    system: boolean;
    category: string | null;
    tenant: string | null;
    q: string | null;
}

interface AdminAuditIndexProps {
    entries: AuditEntry[];
    pagination: ListPagination;
    filters: Filters;
    tenants?: { value: string; label: string }[];
}

const CATEGORY_TONE: Record<string, Tone> = {
    security: 'info',
    billing: 'warn',
};

export default function AdminAuditIndex({
    entries,
    pagination,
    filters,
    tenants,
}: AdminAuditIndexProps) {
    const [loading, setLoading] = useState(false);

    const apply = (next: Partial<Filters>, page?: number) => {
        const merged = { ...filters, ...next };

        router.get(
            auditIndex().url,
            {
                system: merged.system ? 1 : undefined,
                category: merged.category ?? undefined,
                tenant: merged.tenant ?? undefined,
                q: merged.q ?? undefined,
                page: page && page > 1 ? page : undefined,
            },
            {
                preserveState: true,
                preserveScroll: page === undefined,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const filtered =
        filters.category !== null ||
        filters.tenant !== null ||
        filters.q !== null;

    const columns: DataTableColumn<AuditEntry>[] = [
        {
            key: 'when',
            header: 'Cuándo',
            width: 'w-40',
            cell: (row) => (
                <span className="text-xs whitespace-nowrap text-fg-3 tabular-nums">
                    {formatDateTime(row.occurredAt)}
                </span>
            ),
        },
        {
            key: 'action',
            header: 'Acción',
            width: 'w-64',
            cell: (row) => (
                <span className="flex flex-col items-start gap-1">
                    <span className="text-fg-1" title={row.action}>
                        {row.actionLabel}
                    </span>
                    {row.categoryLabel ? (
                        <StatusBadge
                            size="sm"
                            tone={CATEGORY_TONE[row.category] ?? 'neutral'}
                            label={row.categoryLabel}
                        />
                    ) : null}
                </span>
            ),
        },
        {
            key: 'tenant',
            header: 'Cliente',
            width: 'w-48',
            cell: (row) => (
                <span className="text-fg-2">{row.team ?? 'Plataforma'}</span>
            ),
        },
        {
            key: 'actor',
            header: 'Quién',
            width: 'w-56',
            cell: (row) => (
                <span className="break-all text-fg-2">
                    {row.actorEmail ?? 'Sistema'}
                </span>
            ),
        },
        {
            key: 'summary',
            header: 'Detalle',
            cell: (row) => <span className="text-fg-2">{row.summary}</span>,
        },
    ];

    return (
        <>
            <Head title="Auditoría" />
            <ListPage
                title="Auditoría"
                description="Seguridad y cobro de todos los clientes: entradas a consolas, altas, miembros, planes, facturas y operadores."
                meta={
                    <span className="text-xs text-fg-3 tabular-nums">
                        <span className="font-medium text-fg-1">
                            {pagination.total}
                        </span>{' '}
                        {pagination.total === 1 ? 'evento' : 'eventos'}
                    </span>
                }
            >
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                    <SearchInput
                        value={filters.q}
                        onApply={(q) => apply({ q })}
                        placeholder="Buscar en el detalle…"
                        className="w-full sm:w-72"
                    />
                    <FilterDropdown
                        label="Categoría"
                        value={filters.category}
                        options={[
                            { value: 'security', label: 'Seguridad' },
                            { value: 'billing', label: 'Cobro' },
                        ]}
                        onChange={(category) => apply({ category })}
                        allLabel="Todas"
                    />
                    <FilterDropdown
                        label="Cliente"
                        value={filters.tenant}
                        options={tenants ?? []}
                        onChange={(tenant) => apply({ tenant })}
                        allLabel="Todos"
                    />
                    {filtered ? (
                        <ClearFiltersButton
                            onClick={() =>
                                apply({ category: null, tenant: null, q: null })
                            }
                        />
                    ) : null}
                    <label
                        htmlFor="audit-system"
                        className="ml-auto flex items-center gap-2 text-xs text-fg-2"
                    >
                        <Switch
                            id="audit-system"
                            checked={filters.system}
                            onCheckedChange={(system) => apply({ system })}
                        />
                        Incluir actividad automática
                    </label>
                </div>

                <DataTable
                    columns={columns}
                    rows={entries}
                    rowKey={(row) => row.id}
                    loading={loading}
                    empty={
                        <ListEmptyState
                            icon={ScrollText}
                            filtered={filtered}
                            title="Sin eventos de auditoría"
                            description="Aquí aparecerá cada alta, entrada a la consola de un cliente y cambio de cobro."
                            filteredDescription="Ningún evento coincide con los filtros."
                        />
                    }
                />

                <ListFooter
                    pagination={pagination}
                    shown={entries.length}
                    onPage={(page) => apply({}, page)}
                    noun={['evento', 'eventos']}
                />
            </ListPage>
        </>
    );
}

AdminAuditIndex.layout = {
    breadcrumbs: [{ title: 'Auditoría', href: auditIndex().url }],
};
