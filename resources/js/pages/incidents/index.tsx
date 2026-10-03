import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { BulkBar } from '@/components/sam/inbox/bulk-bar';
import { DensityToggle } from '@/components/sam/inbox/density-toggle';
import { InboxBody } from '@/components/sam/inbox/inbox-body';
import { InboxDetailColumn } from '@/components/sam/inbox/inbox-detail-column';
import { InboxFilterBar } from '@/components/sam/inbox/inbox-filter-bar';
import { InboxFooter } from '@/components/sam/inbox/inbox-footer';
import { InboxHeader } from '@/components/sam/inbox/inbox-header';
import {
    EMPTY_INBOX_FILTERS,
    EMPTY_INBOX_OPTIONS,
    INBOX_TABS,
    inboxRows,
    NO_ABILITIES,
    openOnly,
} from '@/components/sam/inbox/lib';
import { useInboxActions } from '@/components/sam/inbox/use-inbox-actions';
import { useInboxKeyboard } from '@/components/sam/inbox/use-inbox-keyboard';
import { useInboxSelection } from '@/components/sam/inbox/use-inbox-selection';
import { useIncidentDetail } from '@/components/sam/inbox/use-incident-detail';
import { TabBar } from '@/components/sam/tab-bar';
import { useServerList } from '@/hooks/use-server-list';
import { cn } from '@/lib/utils';
import incidentRoutes from '@/routes/incidents';
import type {
    InboxDensity,
    InboxFilterOptions,
    InboxFilters,
    InboxLayout,
    InboxTab,
    IncidentAbilities,
    MockIncident,
} from '@/types/sam';

interface IncidentsIndexProps {
    incidents: MockIncident[];
    filters: InboxFilters;
    /**
     * Deferred (with `members` and `reclassifyOptions`, read by the detail
     * panel): until it lands the filter triggers render as usual over empty
     * menus, so no skeleton is needed.
     */
    filterOptions?: InboxFilterOptions;
    can?: IncidentAbilities;
}

export default function IncidentsIndex(pageProps: IncidentsIndexProps) {
    const page = usePage();
    const incidents = useMemo(
        () => pageProps.incidents ?? [],
        [pageProps.incidents],
    );
    const filterOptions = pageProps.filterOptions ?? EMPTY_INBOX_OPTIONS;
    const can = pageProps.can ?? NO_ABILITIES;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const currentUserId =
        (page.props.auth?.user?.id as number | undefined) ?? null;

    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [layout, setLayout] = useState<InboxLayout>('table');
    const [density, setDensity] = useState<InboxDensity>('comfortable');
    const [tab, setTab] = useState<InboxTab>('open');

    const detail = useIncidentDetail(incidents, selectedId, teamSlug);

    const list = useServerList({
        only: ['incidents'],
        filters: pageProps.filters ?? EMPTY_INBOX_FILTERS,
        emptyFilters: EMPTY_INBOX_FILTERS,
        // Refrescar también tira los detalles cacheados del panel.
        onRefreshFinish: detail.clear,
    });

    const openIncidents = openOnly(incidents);
    const critical = openIncidents.filter(
        (i) => i.severity === 'critical',
    ).length;
    const rows = inboxRows(tab, incidents, openIncidents, currentUserId);

    const selection = useInboxSelection(rows);
    const actions = useInboxActions({
        incidents,
        openIncidents,
        teamSlug,
        currentUserId,
        selected: selection.selected,
        clearSelection: selection.clear,
    });

    // Invalidate the cached detail for the open incident and refresh the list
    // after a panel action mutates server state.
    const handlePanelMutated = () => {
        actions.reloadAfterAction(
            detail.selectedRow ? [detail.selectedRow.incidentId] : [],
        );

        if (selectedId !== null) {
            detail.invalidate(selectedId);
        }
    };

    const handleSelect = (id: string) => {
        setSelectedId((prev) => (prev === id ? null : id));
    };

    useInboxKeyboard({
        rows,
        selectedId,
        setSelectedId,
        onToggle: selection.toggle,
        canAssign: can.manage,
        onAssign: (row) => void actions.assignIncidentToMe(row),
        teamSlug,
    });

    return (
        <>
            <Head title="Incidentes" />
            <div
                className={cn(
                    'flex min-h-0 flex-1 overflow-hidden',
                    selectedId !== null
                        ? 'has-detail md:grid md:grid-cols-[1fr_minmax(520px,700px)]'
                        : '',
                )}
            >
                {/* INBOX PANEL */}
                <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                    {selection.selected.size > 0 && (
                        <BulkBar
                            count={selection.selected.size}
                            pending={actions.bulkPending}
                            canManage={can.manage}
                            canResolve={can.resolve}
                            onAssign={actions.bulkAssign}
                            onEscalate={actions.bulkEscalate}
                            onDiscard={actions.bulkDiscard}
                            onClear={selection.clear}
                        />
                    )}

                    <InboxHeader
                        openCount={openIncidents.length}
                        criticalCount={critical}
                        layout={layout}
                        setLayout={setLayout}
                        onRefresh={list.refresh}
                        refreshing={list.refreshing}
                        onAssignOldestCritical={() =>
                            void actions.assignOldestCritical()
                        }
                        assigningOldest={actions.assigningOldest}
                        canAssign={can.manage}
                    />

                    <TabBar
                        aria-label="Vistas de la bandeja"
                        items={INBOX_TABS.map((t) => ({
                            ...t,
                            count:
                                t.key === 'open' && openIncidents.length > 0
                                    ? openIncidents.length
                                    : undefined,
                        }))}
                        value={tab}
                        onChange={(key) => setTab(key as InboxTab)}
                        actions={
                            <DensityToggle
                                density={density}
                                setDensity={setDensity}
                            />
                        }
                        className="shrink-0 bg-surface-1 px-5"
                    />

                    <InboxFilterBar
                        filters={list.filters}
                        options={filterOptions}
                        onApply={list.apply}
                        onReset={list.reset}
                    />

                    <InboxBody
                        hasIncidents={incidents.length > 0}
                        rows={rows}
                        layout={layout}
                        density={density}
                        selectedId={selectedId}
                        selectedSet={selection.selected}
                        allChecked={selection.allChecked}
                        onSelect={handleSelect}
                        onToggle={selection.toggle}
                        onSelectAll={selection.toggleAll}
                        currentUserId={currentUserId}
                        claimPendingId={actions.claimPendingId}
                        onClaimToggle={
                            can.manage ? actions.toggleClaim : undefined
                        }
                    />

                    <InboxFooter
                        count={rows.length}
                        total={incidents.length}
                        canAssign={can.manage}
                    />
                </div>

                {selectedId !== null && (
                    <InboxDetailColumn
                        detail={detail.detail}
                        loading={detail.loading}
                        teamSlug={teamSlug}
                        onClose={() => setSelectedId(null)}
                        onMutated={handlePanelMutated}
                    />
                )}
            </div>
        </>
    );
}

IncidentsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? incidentRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
