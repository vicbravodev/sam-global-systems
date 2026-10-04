import { Head, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useState } from 'react';
import {
    DEMO_REQUEST_STATUS,
    DEMO_REQUEST_STATUSES,
} from '@/components/sam/admin/demo-requests/copy';
import type { DemoRequestStatus } from '@/components/sam/admin/demo-requests/copy';
import { DemoRequestItem } from '@/components/sam/admin/demo-requests/demo-request-item';
import type { DemoRequestRow } from '@/components/sam/admin/demo-requests/types';
import { ListFooter } from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { TONE_DOT } from '@/lib/tone';
import { index as demoRequestsIndex } from '@/routes/admin/demo-requests';
import type { ListPagination } from '@/types/pagination';

interface AdminDemoRequestsIndexProps {
    requests: DemoRequestRow[];
    pagination: ListPagination;
    counts: Record<DemoRequestStatus, number>;
    filters: { status: DemoRequestStatus | null };
}

export default function AdminDemoRequestsIndex({
    requests,
    pagination,
    counts,
    filters,
}: AdminDemoRequestsIndexProps) {
    const [loading, setLoading] = useState(false);
    const total = counts.new + counts.contacted + counts.closed;

    const apply = (status: string | null, page?: number) =>
        router.get(
            demoRequestsIndex().url,
            {
                status: status ?? undefined,
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

    return (
        <>
            <Head title="Solicitudes de demo" />
            <ListPage
                title="Solicitudes de demo"
                description="Prospectos que pidieron una demo desde el sitio. Cada una también llega por correo a los operadores."
                meta={
                    <span className="text-xs text-fg-3 tabular-nums">
                        <span className="font-medium text-fg-1">
                            {counts.new}
                        </span>{' '}
                        sin contactar
                    </span>
                }
                filters={
                    <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-5 py-2">
                        <SegmentedFilter
                            aria-label="Filtrar por seguimiento"
                            value={filters.status}
                            onChange={(status) => apply(status)}
                            allLabel="Todas"
                            allCount={total}
                            options={DEMO_REQUEST_STATUSES.map((status) => ({
                                value: status,
                                label: DEMO_REQUEST_STATUS[status].label,
                                count: counts[status],
                                dot: TONE_DOT[DEMO_REQUEST_STATUS[status].tone],
                            }))}
                        />
                    </div>
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={requests.length}
                        onPage={(page) => apply(filters.status, page)}
                        noun={['solicitud', 'solicitudes']}
                    />
                }
            >
                <div
                    className="min-h-0 flex-1 overflow-y-auto p-5"
                    aria-busy={loading}
                >
                    {requests.length === 0 ? (
                        <ListEmptyState
                            icon={Inbox}
                            filtered={filters.status !== null}
                            title="Sin solicitudes de demo"
                            description="Cuando alguien llene el formulario «Pedir una demo» del sitio, aparecerá aquí y te llegará por correo."
                            filteredDescription="No hay solicitudes con ese seguimiento."
                        />
                    ) : (
                        <ul className="max-w-5xl divide-y divide-border rounded-lg border border-border bg-surface-1">
                            {requests.map((request) => (
                                <DemoRequestItem
                                    key={request.id}
                                    request={request}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </ListPage>
        </>
    );
}

AdminDemoRequestsIndex.layout = {
    breadcrumbs: [
        { title: 'Solicitudes de demo', href: demoRequestsIndex().url },
    ],
};
