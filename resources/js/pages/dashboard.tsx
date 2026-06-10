import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Gauge, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    ProviderTag,
    RealtimeStatus,
    SeverityBadge,
    SlaCountdown,
} from '@/components/sam';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type {
    DashboardIntegration,
    DashboardProps,
    DashboardStreamEvent,
    IncidentRow,
    UsageCounterRow,
} from '@/types/dashboard';

// Reload keys to refresh when each broadcast event arrives. Decisions and AI
// evaluations fire per ingested event, so reloads are debounced below.
const RELOAD_KEYS_BY_EVENT: Record<string, string[]> = {
    'incidents.created': ['kpis', 'incidents', 'stream'],
    'decisions.decision_made': ['kpis', 'stream'],
    'ai.evaluation_completed': ['kpis', 'stream'],
    'usage.updated': ['usage'],
};

const RELOAD_DEBOUNCE_MS = 2000;

export default function Dashboard() {
    const page = usePage();
    const { kpis, incidents, stream, integrations, usage } =
        page.props as unknown as DashboardProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;

    // Coalesce bursts of broadcasts into one partial reload with the union
    // of the affected prop keys.
    const pendingKeys = useRef<Set<string>>(new Set());
    const timer = useRef<number | null>(null);

    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;
            const keys = RELOAD_KEYS_BY_EVENT[detail?.event ?? ''];

            if (!keys) {
                return;
            }

            keys.forEach((key) => pendingKeys.current.add(key));

            if (timer.current !== null) {
                return;
            }

            timer.current = window.setTimeout(() => {
                const only = [...pendingKeys.current];
                pendingKeys.current.clear();
                timer.current = null;
                router.reload({ only });
            }, RELOAD_DEBOUNCE_MS);
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, []);

    return (
        <>
            <Head title="Panel operativo" />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <PageHead
                    criticalCount={kpis.criticalOpen.value}
                    openCount={kpis.openIncidents.value}
                />
                <KpiGrid kpis={kpis} />
                <div className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                    <OpenIncidentsPanel
                        incidents={incidents}
                        teamSlug={teamSlug}
                    />
                    <LiveStreamPanel events={stream} />
                </div>
                <IntegrationsPanel integrations={integrations} />
                <UsagePanel usage={usage} />
            </div>
        </>
    );
}

function shiftLabel(now: Date): string {
    const hour = now.getHours();

    if (hour >= 6 && hour < 14) {
        return 'Turno mañana · 06:00 – 14:00';
    }

    if (hour >= 14 && hour < 22) {
        return 'Turno tarde · 14:00 – 22:00';
    }

    return 'Turno noche · 22:00 – 06:00';
}

function PageHead({
    criticalCount,
    openCount,
}: {
    criticalCount: number;
    openCount: number;
}) {
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <header className="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="sam-h1">Panel operativo</h1>
                <p className="sam-meta mt-1">
                    {shiftLabel(new Date())} ·{' '}
                    <span className="text-fg-2">{openCount} abiertos</span> ·{' '}
                    <span className="text-severity-critical">
                        {criticalCount} críticos
                    </span>
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                onClick={refresh}
                disabled={refreshing}
            >
                <RefreshCw className={cn(refreshing && 'animate-spin')} />
                Refrescar
            </Button>
        </header>
    );
}

interface SparklineProps {
    series: number[];
    colorVar: string;
}

function Sparkline({ series, colorVar }: SparklineProps) {
    if (series.length < 2) {
        return null;
    }

    const max = Math.max(...series);
    const min = Math.min(...series);
    const range = max - min || 1;
    const stepX = 90 / (series.length - 1);

    const points = series
        .map((value, index) => {
            const x = (index * stepX).toFixed(1);
            const y = (28 - ((value - min) / range) * 24).toFixed(1);

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <svg
            className="absolute right-3 bottom-3 opacity-55"
            width="90"
            height="30"
            viewBox="0 0 90 30"
            aria-hidden="true"
        >
            <polyline
                fill="none"
                stroke={`var(${colorVar})`}
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                points={points}
            />
        </svg>
    );
}

interface KpiCardProps {
    label: string;
    value: string;
    delta: string;
    deltaColorClass: string;
    series?: number[];
    sparkColorVar?: string;
}

function KpiCard({
    label,
    value,
    delta,
    deltaColorClass,
    series,
    sparkColorVar,
}: KpiCardProps) {
    return (
        <Card className="relative gap-2 overflow-hidden bg-surface-1 py-4">
            <CardHeader className="px-4">
                <span className="sam-caps">{label}</span>
            </CardHeader>
            <CardContent className="px-4 pb-2">
                <div className="font-mono text-3xl tracking-tight tabular-nums">
                    {value}
                </div>
                <div
                    className={cn(
                        'mt-1.5 font-mono text-[11px] tabular-nums',
                        deltaColorClass,
                    )}
                >
                    {delta}
                </div>
            </CardContent>
            {series && sparkColorVar ? (
                <Sparkline series={series} colorVar={sparkColorVar} />
            ) : null}
        </Card>
    );
}

function formatPercent(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${value.toLocaleString('es', { maximumFractionDigits: 1 })} %`;
}

function formatDeltaPp(deltaPp: number | null): string {
    if (deltaPp === null) {
        return 'sin datos previos';
    }

    const arrow = deltaPp >= 0 ? '↗' : '↘';

    return `${arrow} ${Math.abs(deltaPp).toLocaleString('es', { maximumFractionDigits: 1 })} pp`;
}

function formatSlaClock(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const clamped = Math.max(seconds, 0);
    const minutes = Math.floor(clamped / 60);
    const rest = clamped % 60;

    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

function KpiGrid({ kpis }: { kpis: DashboardProps['kpis'] }) {
    const openDelta =
        kpis.openIncidents.deltaPct === null
            ? 'sin datos de ayer'
            : `${kpis.openIncidents.deltaPct >= 0 ? '↗' : '↘'} ${Math.abs(
                  kpis.openIncidents.deltaPct,
              ).toLocaleString('es', { maximumFractionDigits: 1 })} % vs ayer`;

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard
                label="Incidentes abiertos"
                value={String(kpis.openIncidents.value)}
                delta={openDelta}
                deltaColorClass={
                    (kpis.openIncidents.deltaPct ?? 0) > 0
                        ? 'text-severity-critical'
                        : 'text-severity-low'
                }
                series={kpis.openIncidents.series}
                sparkColorVar="--severity-critical"
            />
            <KpiCard
                label="Críticos ahora"
                value={String(kpis.criticalOpen.value)}
                delta={`SLA promedio: ${formatSlaClock(
                    kpis.criticalOpen.avgSlaRemainingSeconds,
                )}`}
                deltaColorClass="text-severity-high"
                series={kpis.criticalOpen.series}
                sparkColorVar="--severity-high"
            />
            <KpiCard
                label="SLA cumplido · 7 d"
                value={formatPercent(kpis.slaCompliance.value)}
                delta={formatDeltaPp(kpis.slaCompliance.deltaPp)}
                deltaColorClass={
                    (kpis.slaCompliance.deltaPp ?? 0) >= 0
                        ? 'text-severity-low'
                        : 'text-severity-high'
                }
            />
            <KpiCard
                label="Precisión IA · 7 d"
                value={formatPercent(kpis.aiPrecision.value)}
                delta={formatDeltaPp(kpis.aiPrecision.deltaPp)}
                deltaColorClass="text-confidence-high"
            />
        </div>
    );
}

function OpenIncidentsPanel({
    incidents,
    teamSlug,
}: {
    incidents: IncidentRow[];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Incidentes abiertos
                </CardTitle>
                {teamSlug ? (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/${teamSlug}/incidents`}>
                            Ver todos
                            <ChevronRight />
                        </Link>
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent className="p-0">
                {incidents.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin incidentes abiertos ahora mismo.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {incidents.map((incident) => (
                            <li key={incident.id}>
                                <Link
                                    href={
                                        teamSlug
                                            ? `/${teamSlug}/incidents`
                                            : '#'
                                    }
                                    className="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                                >
                                    <SeverityBadge level={incident.severity} />
                                    <span className="w-16 shrink-0 font-mono text-[11px] text-fg-3 tabular-nums">
                                        {incident.id.replace(
                                            /^INC-\d+-/,
                                            'INC-',
                                        )}
                                    </span>
                                    <span className="flex-1 truncate text-sm">
                                        {incident.title}
                                    </span>
                                    <SlaCountdown
                                        seconds={incident.slaSeconds}
                                        total={incident.slaTotal}
                                    />
                                    <ChevronRight className="size-4 text-fg-3" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function connectionToStatus(
    state: ReturnType<typeof useRealtimeConnection>,
): RealtimeState {
    switch (state) {
        case 'connected':
            return 'ok';
        case 'connecting':
        case 'reconnecting':
            return 'warn';
        default:
            return 'down';
    }
}

function LiveStreamPanel({ events }: { events: DashboardStreamEvent[] }) {
    const connection = useRealtimeConnection();

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Stream en vivo</CardTitle>
                <RealtimeStatus state={connectionToStatus(connection)} />
            </CardHeader>
            <CardContent className="p-0">
                {events.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Aún no hay eventos normalizados.
                    </p>
                ) : (
                    <ul className="max-h-72 overflow-auto py-1">
                        {events.map((event, index) => (
                            <li
                                key={event.id}
                                className={cn(
                                    'flex items-center gap-2 px-4 py-1.5',
                                    index === 0 && 'sam-flash',
                                )}
                            >
                                <span className="w-14 shrink-0 font-mono text-[11px] text-fg-3 tabular-nums">
                                    {event.ts}
                                </span>
                                <ProviderTag name={event.provider} />
                                <span className="flex-1 truncate text-xs text-fg-2">
                                    {event.type} ·{' '}
                                    <span className="text-fg-1">
                                        {event.asset}
                                    </span>
                                </span>
                                <DecisionChip
                                    decision={event.decision}
                                    severity={event.severity}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function DecisionChip({
    decision,
    severity,
}: {
    decision: DashboardStreamEvent['decision'];
    severity: DashboardStreamEvent['severity'];
}) {
    const isAlert = decision === 'incident' || decision === 'escalate';
    const sev = severity ?? 'high';
    const sevTextClass: Record<string, string> = {
        critical:
            'text-severity-critical border-severity-critical/40 bg-severity-critical/15',
        high: 'text-severity-high border-severity-high/40 bg-severity-high/15',
        medium: 'text-severity-medium border-severity-medium/40 bg-severity-medium/15',
        low: 'text-severity-low border-severity-low/40 bg-severity-low/15',
        info: 'text-severity-info border-severity-info/40 bg-severity-info/15',
    };

    return (
        <span
            className={cn(
                'inline-flex rounded-sm border px-1.5 py-0.5 text-[10px] font-semibold whitespace-nowrap',
                isAlert
                    ? sevTextClass[sev]
                    : 'border-border bg-surface-3 text-fg-3',
            )}
        >
            {decision}
        </span>
    );
}

function IntegrationsPanel({
    integrations,
}: {
    integrations: DashboardIntegration[];
}) {
    const HEALTH_DOT: Record<DashboardIntegration['health'], string> = {
        ok: 'bg-health-ok',
        warn: 'bg-health-warn',
        down: 'bg-health-down',
        unknown: 'bg-health-unknown',
    };
    const alertCount = integrations.filter((i) => i.health !== 'ok').length;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Integraciones</CardTitle>
                <span className="sam-meta">
                    {integrations.length} proveedores · {alertCount} con alertas
                </span>
            </CardHeader>
            <CardContent className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
                {integrations.length === 0 ? (
                    <p className="px-1 py-3 text-sm text-fg-3 sm:col-span-2 xl:col-span-4">
                        Sin integraciones conectadas todavía.
                    </p>
                ) : (
                    integrations.map((integration) => (
                        <div
                            key={integration.id}
                            className="rounded-md border border-border bg-surface-2 p-3"
                        >
                            <div className="mb-2 flex items-center gap-2">
                                <ProviderTag name={integration.name} />
                                <span className="flex-1 truncate text-sm font-semibold">
                                    {integration.name}
                                </span>
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        HEALTH_DOT[integration.health],
                                    )}
                                    aria-label={`Estado: ${integration.health}`}
                                />
                            </div>
                            <div className="font-mono text-xl tabular-nums">
                                {integration.events24h.toLocaleString('es')}
                            </div>
                            <div className="sam-meta">eventos · últ. 24 h</div>
                            <div className="mt-2 font-mono text-[10px] text-fg-3">
                                sync: {integration.lastSync ?? '—'}
                            </div>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function UsagePanel({ usage }: { usage: UsageCounterRow[] }) {
    if (usage.length === 0) {
        return null;
    }

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Gauge size={15} /> Uso del plan
                </CardTitle>
                <span className="sam-meta">
                    {usage.length}{' '}
                    {usage.length === 1 ? 'medidor' : 'medidores'}
                </span>
            </CardHeader>
            <CardContent className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
                {usage.map((counter) => {
                    const hasOverage = counter.overage > 0;
                    const fillPct = Math.min(counter.percentUsed ?? 0, 100);

                    return (
                        <div
                            key={counter.meterCode}
                            className="rounded-md border border-border bg-surface-2 p-3"
                        >
                            <div className="truncate text-sm font-semibold">
                                {counter.meterName}
                            </div>
                            <div className="mt-1 font-mono text-xl tabular-nums">
                                {counter.consumed.toLocaleString('es')}
                                <span className="text-sm text-fg-3">
                                    {' '}
                                    / {counter.included.toLocaleString(
                                        'es',
                                    )}{' '}
                                    {counter.unit}
                                </span>
                            </div>
                            <div className="mt-2 h-1 overflow-hidden rounded-full bg-surface-3">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        hasOverage
                                            ? 'bg-severity-high'
                                            : 'bg-primary',
                                    )}
                                    style={{ width: `${fillPct}%` }}
                                />
                            </div>
                            <div className="mt-2 flex items-center justify-between font-mono text-[10px] text-fg-3">
                                <span>
                                    {hasOverage
                                        ? `+${counter.overage.toLocaleString('es')} excedente`
                                        : formatPercent(counter.percentUsed)}
                                </span>
                                {counter.periodEnd ? (
                                    <span>renueva {counter.periodEnd}</span>
                                ) : null}
                            </div>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Panel',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
