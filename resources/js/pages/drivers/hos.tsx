import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import { useState } from 'react';
import { HosFleetTable } from '@/components/sam/hos/hos-fleet-table';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { formatNumber } from '@/lib/format';
import { TONE_TEXT } from '@/lib/tone';
import driverRoutes from '@/routes/drivers';
import type {
    HosFleetPageProps,
    HosFleetRow,
    HosFleetSummary,
} from '@/types/hos';

export default function DriversHos({ fleet }: HosFleetPageProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const [refreshing, setRefreshing] = useState(false);

    // Cada sondeo (~1 min) recarga sólo la lista.
    useBroadcastReload(
        { 'hos.clocks_updated': ['fleet'] },
        { resync: ['fleet'] },
    );

    const refresh = () =>
        router.reload({
            only: ['fleet'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    const open = (row: HosFleetRow) => {
        if (teamSlug !== null) {
            router.visit(
                driverRoutes.show.url([teamSlug, row.driver.id], {
                    query: { pestana: 'hos' },
                }),
            );
        }
    };

    return (
        <>
            <Head title="HOS (EE. UU.)" />
            <ListPage
                title="HOS (EE. UU.)"
                description="Choferes vigilados en Estados Unidos, del más urgente al más holgado."
                meta={<FleetMeta summary={fleet.summary} />}
                onRefresh={refresh}
                refreshing={refreshing}
            >
                {fleet.rows.length === 0 ? (
                    <ListEmptyState
                        icon={Hourglass}
                        filtered={false}
                        title="Nadie en monitoreo HOS ahora"
                        description="Entran los choferes que manejan un tracto vigilado y cumplen la configuración de HOS de tu empresa."
                        filteredDescription=""
                    />
                ) : (
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <HosFleetTable rows={fleet.rows} onSelect={open} />
                    </div>
                )}
            </ListPage>
        </>
    );
}

function FleetMeta({ summary }: { summary: HosFleetSummary }) {
    return (
        <span className="text-xs text-fg-3">
            <span className="font-medium text-fg-1">
                {formatNumber(summary.total)}
            </span>{' '}
            {summary.total === 1 ? 'chofer vigilado' : 'choferes vigilados'}
            {summary.violation > 0 ? (
                <>
                    {' · '}
                    <span className={TONE_TEXT.critical}>
                        {formatNumber(summary.violation)} en infracción
                    </span>
                </>
            ) : null}
            {summary.at_limit > 0 ? (
                <>
                    {' · '}
                    <span className={TONE_TEXT.high}>
                        {formatNumber(summary.at_limit)} en el límite
                    </span>
                </>
            ) : null}
        </span>
    );
}

DriversHos.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? driverRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        {
            title: 'HOS (EE. UU.)',
            href: props.currentTeam
                ? driverRoutes.hos.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
