import type { SharedPageProps } from '@inertiajs/core';
import { Deferred, Head, usePage } from '@inertiajs/react';
import { KpiStripSkeleton } from '@/components/sam';
import { DashboardHeader } from '@/components/sam/dashboard/dashboard-header';
import { IntegrationsPanel } from '@/components/sam/dashboard/integrations-panel';
import { KPI_LABELS, KpiCards } from '@/components/sam/dashboard/kpi-cards';
import { LiveStreamPanel } from '@/components/sam/dashboard/live-stream-panel';
import { OpenIncidentsPanel } from '@/components/sam/dashboard/open-incidents-panel';
import { PanelSkeleton } from '@/components/sam/dashboard/panel-skeleton';
import { UsagePanel } from '@/components/sam/dashboard/usage-panel';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { dashboard, home } from '@/routes';
import type { DashboardProps } from '@/types/dashboard';

// Reload keys to refresh when each broadcast event arrives. Decisions and AI
// evaluations fire per ingested event, so reloads are debounced below.
const RELOAD_KEYS_BY_EVENT = {
    'incidents.created': ['kpis', 'incidents', 'stream'],
    'incidents.updated': ['kpis', 'incidents'],
    'decisions.decision_made': ['kpis', 'stream'],
    'ai.evaluation_completed': ['kpis', 'stream'],
    'usage.updated': ['usage'],
    'integration.status_changed': ['integrations'],
} as const;

const RELOAD_DEBOUNCE_MS = 2000;

// The KPI strip is a two-week aggregate: a live event barely moves it.
const KPI_MIN_INTERVAL_MS = 30000;

// The stream shows the last 8 events: during an ingestion burst every
// decision and evaluation would reload it each debounce window (~30/min).
// One refresh every few seconds still reads as live (~8/min at most).
const STREAM_MIN_INTERVAL_MS = 8000;

export default function Dashboard({
    kpis,
    incidents,
    stream,
    integrations,
    usage,
}: DashboardProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;

    useBroadcastReload(RELOAD_KEYS_BY_EVENT, {
        debounceMs: RELOAD_DEBOUNCE_MS,
        minIntervalMs: {
            kpis: KPI_MIN_INTERVAL_MS,
            stream: STREAM_MIN_INTERVAL_MS,
        },
    });

    return (
        <>
            <Head title="Panel operativo" />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <DashboardHeader
                    criticalCount={kpis?.criticalOpen.value ?? null}
                    openCount={kpis?.openIncidents.value ?? null}
                />
                <Deferred
                    data="kpis"
                    fallback={
                        <KpiStripSkeleton
                            labels={KPI_LABELS}
                            className="shrink-0"
                        />
                    }
                >
                    {kpis && <KpiCards kpis={kpis} />}
                </Deferred>
                {/* Jerarquía cockpit (F3.1): incidentes abiertos es el panel
                    dominante; el stream vive como columna lateral persistente.
                    En móvil (una columna) la columna izquierda se disuelve
                    (`max-lg:contents`) para ordenar: incidentes y stream en
                    vivo antes que integraciones y medidores de uso. */}
                <div className="grid items-start gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="flex min-w-0 flex-col gap-4 max-lg:contents">
                        <div className="min-w-0 max-lg:order-1">
                            <OpenIncidentsPanel
                                incidents={incidents}
                                teamSlug={teamSlug}
                            />
                        </div>
                        <div className="min-w-0 max-lg:order-3">
                            <Deferred
                                data="integrations"
                                fallback={
                                    <PanelSkeleton
                                        title="Integraciones"
                                        tiles={4}
                                    />
                                }
                            >
                                {integrations && (
                                    <IntegrationsPanel
                                        integrations={integrations}
                                    />
                                )}
                            </Deferred>
                        </div>
                        <div className="min-w-0 max-lg:order-4">
                            <Deferred
                                data="usage"
                                fallback={
                                    <PanelSkeleton
                                        title="Uso del plan"
                                        tiles={4}
                                    />
                                }
                            >
                                {usage && <UsagePanel usage={usage} />}
                            </Deferred>
                        </div>
                    </div>
                    <div className="min-w-0 max-lg:order-2">
                        <LiveStreamPanel events={stream} />
                    </div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Panel',
            href: props.currentTeam
                ? dashboard(props.currentTeam.slug)
                : home(),
        },
    ],
});
