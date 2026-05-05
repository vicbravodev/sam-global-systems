import { Head } from '@inertiajs/react';
import { ChevronRight, ExternalLink, RefreshCw } from 'lucide-react';
import {
    ProviderTag,
    RealtimeStatus,
    SeverityBadge,
    SlaCountdown,
} from '@/components/sam';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type {
    DashboardMockData,
    MockIncident,
    MockIntegration,
    MockStreamEvent,
} from '@/types/sam';

export default function Dashboard() {
    const { incidents, integrations, stream } = MOCK_DASHBOARD;
    const open = incidents.filter(
        (i) => !['closed', 'resolved', 'discarded'].includes(i.status),
    );
    const critical = open.filter((i) => i.severity === 'critical');

    return (
        <>
            <Head title="Dashboard operativo" />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <PageHead
                    criticalCount={critical.length}
                    openCount={open.length}
                />
                <KpiGrid open={open} critical={critical} />
                <div className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                    <OpenIncidentsPanel incidents={open} />
                    <LiveStreamPanel events={stream} />
                </div>
                <IntegrationsPanel integrations={integrations} />
            </div>
        </>
    );
}

function PageHead({
    criticalCount,
    openCount,
}: {
    criticalCount: number;
    openCount: number;
}) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="sam-h1">Dashboard operativo</h1>
                <p className="sam-meta mt-1">
                    Turno tarde · 14:00 – 22:00 ·{' '}
                    <span className="text-fg-2">{openCount} abiertos</span> ·{' '}
                    <span className="text-severity-critical">
                        {criticalCount} críticos
                    </span>
                </p>
            </div>
            <div className="flex gap-2">
                <Button variant="outline" size="sm">
                    <RefreshCw />
                    Refrescar
                </Button>
                <Button variant="ghost" size="sm">
                    <ExternalLink />
                    Abrir analítica
                </Button>
            </div>
        </header>
    );
}

interface SparklineProps {
    points: string;
    colorVar: string;
}

function Sparkline({ points, colorVar }: SparklineProps) {
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
    spark: string;
    sparkColorVar: string;
}

function KpiCard({
    label,
    value,
    delta,
    deltaColorClass,
    spark,
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
            <Sparkline points={spark} colorVar={sparkColorVar} />
        </Card>
    );
}

function KpiGrid({
    open,
    critical,
}: {
    open: MockIncident[];
    critical: MockIncident[];
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard
                label="Incidentes abiertos"
                value={String(open.length)}
                delta="↗ 12 % vs ayer"
                deltaColorClass="text-severity-critical"
                spark="0,20 12,16 24,18 36,12 48,14 60,9 72,12 90,4"
                sparkColorVar="--severity-critical"
            />
            <KpiCard
                label="Críticos ahora"
                value={String(critical.length)}
                delta="SLA promedio: 04:12"
                deltaColorClass="text-severity-high"
                spark="0,8 12,10 24,12 36,9 48,10 60,7 72,4 90,2"
                sparkColorVar="--severity-high"
            />
            <KpiCard
                label="SLA cumplido · 7 d"
                value="94,2 %"
                delta="↘ 0,8 pp"
                deltaColorClass="text-severity-low"
                spark="0,8 12,10 24,9 36,7 48,8 60,10 72,12 90,13"
                sparkColorVar="--primary"
            />
            <KpiCard
                label="Precisión IA"
                value="87,1 %"
                delta="↗ 1,4 pp"
                deltaColorClass="text-confidence-high"
                spark="0,18 12,16 24,14 36,12 48,11 60,9 72,7 90,6"
                sparkColorVar="--ai-accent"
            />
        </div>
    );
}

function OpenIncidentsPanel({ incidents }: { incidents: MockIncident[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Incidentes abiertos
                </CardTitle>
                <Button variant="ghost" size="sm">
                    Ver todos
                    <ChevronRight />
                </Button>
            </CardHeader>
            <CardContent className="p-0">
                <ul className="divide-y divide-border">
                    {incidents.slice(0, 5).map((i) => (
                        <li key={i.id}>
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                            >
                                <SeverityBadge level={i.severity} />
                                <span className="w-16 shrink-0 font-mono text-[11px] text-fg-3 tabular-nums">
                                    {i.id.replace('INC-2026-', 'INC-')}
                                </span>
                                <span className="flex-1 truncate text-sm">
                                    {i.title}
                                </span>
                                <SlaCountdown
                                    seconds={i.slaSeconds}
                                    total={i.slaTotal}
                                />
                                <ChevronRight className="size-4 text-fg-3" />
                            </button>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function LiveStreamPanel({ events }: { events: MockStreamEvent[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Stream en vivo</CardTitle>
                <RealtimeStatus state="ok" />
            </CardHeader>
            <CardContent className="p-0">
                <ul className="max-h-72 overflow-auto py-1">
                    {events.map((e, i) => (
                        <li
                            key={`${e.ts}-${i}`}
                            className={cn(
                                'flex items-center gap-2 px-4 py-1.5',
                                i === 0 && 'sam-flash',
                            )}
                        >
                            <span className="w-14 shrink-0 font-mono text-[11px] text-fg-3 tabular-nums">
                                {e.ts}
                            </span>
                            <ProviderTag name={e.provider} />
                            <span className="flex-1 truncate text-xs text-fg-2">
                                {e.type} ·{' '}
                                <span className="text-fg-1">{e.asset}</span>
                            </span>
                            <DecisionChip
                                decision={e.decision}
                                severity={e.severity}
                            />
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function DecisionChip({
    decision,
    severity,
}: {
    decision: MockStreamEvent['decision'];
    severity: MockStreamEvent['severity'];
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
    integrations: MockIntegration[];
}) {
    const HEALTH_DOT: Record<MockIntegration['health'], string> = {
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
                {integrations.map((i) => (
                    <div
                        key={i.key}
                        className="rounded-md border border-border bg-surface-2 p-3"
                    >
                        <div className="mb-2 flex items-center gap-2">
                            <ProviderTag name={i.name} />
                            <span className="flex-1 truncate text-sm font-semibold">
                                {i.name}
                            </span>
                            <span
                                className={cn(
                                    'size-2 rounded-full',
                                    HEALTH_DOT[i.health],
                                )}
                                aria-label={`Estado: ${i.health}`}
                            />
                        </div>
                        <div className="font-mono text-xl tabular-nums">
                            {i.events24h.toLocaleString('es')}
                        </div>
                        <div className="sam-meta">eventos · últ. 24 h</div>
                        <div className="mt-2 font-mono text-[10px] text-fg-3">
                            sync: {i.lastSync}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

const MOCK_DASHBOARD: DashboardMockData = {
    incidents: [
        {
            id: 'INC-2026-04822',
            title: 'Colisión frontal detectada',
            severity: 'critical',
            status: 'in-progress',
            provider: 'Samsara',
            asset: 'T-412 · Volvo FH16',
            driver: 'M. Pereira',
            assignee: { name: 'María Gómez', initials: 'MG' },
            slaSeconds: 252,
            slaTotal: 1800,
            ageMin: 2,
            eventType: 'collision',
            location: 'RN7 km 184 · Mendoza',
            aiConfidence: 0.87,
            aiDecision: 'incident',
            aiReason: '',
            realtime: true,
        },
        {
            id: 'INC-2026-04821',
            title: 'Frenado brusco > 0.6 g',
            severity: 'high',
            status: 'new',
            provider: 'Motive',
            asset: 'T-118 · Scania R450',
            driver: 'L. Silva',
            assignee: null,
            slaSeconds: 728,
            slaTotal: 1800,
            ageMin: 5,
            eventType: 'harsh_brake',
            location: 'Av. Perón 2200 · CABA',
            aiConfidence: 0.71,
            aiDecision: 'incident',
            aiReason: '',
            realtime: true,
        },
        {
            id: 'INC-2026-04820',
            title: 'Exceso de velocidad 92 / 70 km/h',
            severity: 'medium',
            status: 'assigned',
            provider: 'Samsara',
            asset: 'T-207 · MB Actros',
            driver: 'C. Ruiz',
            assignee: { name: 'J. Ríos', initials: 'JR' },
            slaSeconds: 1721,
            slaTotal: 3600,
            ageMin: 22,
            eventType: 'overspeed',
            location: 'Ruta 2 km 64',
            aiConfidence: 0.82,
            aiDecision: 'incident',
            aiReason: '',
        },
        {
            id: 'INC-2026-04819',
            title: 'Sin cinturón detectado',
            severity: 'low',
            status: 'triaging',
            provider: 'Samsara',
            asset: 'T-301',
            driver: 'D. Acosta',
            assignee: { name: 'J. Ríos', initials: 'JR' },
            slaSeconds: 2400,
            slaTotal: 3600,
            ageMin: 41,
            eventType: 'no_seatbelt',
            location: 'Depósito central',
            aiConfidence: 0.55,
            aiDecision: 'incident',
            aiReason: '',
        },
        {
            id: 'INC-2026-04818',
            title: 'Posible fatiga del conductor',
            severity: 'high',
            status: 'assigned',
            provider: 'Motive',
            asset: 'T-502',
            driver: 'F. Medina',
            assignee: { name: 'M. Gómez', initials: 'MG' },
            slaSeconds: 420,
            slaTotal: 1800,
            ageMin: 48,
            eventType: 'fatigue',
            location: 'RN9 km 412',
            aiConfidence: 0.79,
            aiDecision: 'escalate',
            aiReason: '',
        },
        {
            id: 'INC-2026-04817',
            title: 'Ralentí prolongado',
            severity: 'low',
            status: 'resolved',
            provider: 'Geotab',
            asset: 'T-042',
            driver: 'R. Vera',
            assignee: { name: 'J. Ríos', initials: 'JR' },
            slaSeconds: 0,
            slaTotal: 3600,
            ageMin: 96,
            eventType: 'idle',
            location: 'Playa de maniobras',
            aiConfidence: 0.63,
            aiDecision: 'info',
            aiReason: '',
        },
    ],
    integrations: [
        {
            name: 'Samsara',
            key: 'samsara',
            health: 'ok',
            events24h: 4821,
            lastSync: 'hace 4 s',
        },
        {
            name: 'Motive',
            key: 'motive',
            health: 'ok',
            events24h: 2104,
            lastSync: 'hace 11 s',
        },
        {
            name: 'Geotab',
            key: 'geotab',
            health: 'warn',
            events24h: 388,
            lastSync: 'hace 2 min',
        },
        {
            name: 'Verizon Connect',
            key: 'verizon',
            health: 'down',
            events24h: 0,
            lastSync: 'hace 18 min',
        },
    ],
    stream: [
        {
            ts: '14:32:08',
            provider: 'Samsara',
            type: 'collision',
            asset: 'T-412',
            decision: 'incident',
            severity: 'critical',
        },
        {
            ts: '14:31:54',
            provider: 'Samsara',
            type: 'harsh_accel',
            asset: 'T-088',
            decision: 'discard',
            severity: null,
        },
        {
            ts: '14:31:41',
            provider: 'Motive',
            type: 'overspeed',
            asset: 'T-207',
            decision: 'info',
            severity: 'low',
        },
        {
            ts: '14:31:12',
            provider: 'Motive',
            type: 'fatigue',
            asset: 'T-502',
            decision: 'escalate',
            severity: 'high',
        },
        {
            ts: '14:30:55',
            provider: 'Geotab',
            type: 'idle',
            asset: 'T-042',
            decision: 'info',
            severity: 'low',
        },
        {
            ts: '14:30:34',
            provider: 'Samsara',
            type: 'geofence_exit',
            asset: 'T-217',
            decision: 'discard',
            severity: null,
        },
        {
            ts: '14:30:02',
            provider: 'Samsara',
            type: 'no_seatbelt',
            asset: 'T-301',
            decision: 'incident',
            severity: 'low',
        },
        {
            ts: '14:29:41',
            provider: 'Motive',
            type: 'harsh_brake',
            asset: 'T-118',
            decision: 'incident',
            severity: 'high',
        },
    ],
};

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
