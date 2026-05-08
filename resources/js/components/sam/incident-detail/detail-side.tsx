import { RefreshCw, TriangleAlert, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';

// ---- UserAvatar ----

function UserAvatar({
    initials,
    size = 32,
    isPrimary = false,
    isEmpty = false,
}: {
    initials?: string;
    size?: number;
    isPrimary?: boolean;
    isEmpty?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-grid shrink-0 place-items-center rounded-full border border-border font-semibold',
                isPrimary
                    ? 'bg-primary text-primary-foreground'
                    : isEmpty
                      ? 'bg-surface-3 text-fg-3'
                      : 'bg-surface-3 text-fg-2',
            )}
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, size * 0.4),
            }}
        >
            {isEmpty ? '?' : initials}
        </span>
    );
}

// ---- RiskBar ----

function RiskBar({ value }: { value: number }) {
    const color =
        value > 70
            ? 'text-severity-critical'
            : value > 40
              ? 'text-severity-high'
              : 'text-severity-low';
    const bgColor =
        value > 70
            ? 'bg-severity-critical'
            : value > 40
              ? 'bg-severity-high'
              : 'bg-severity-low';

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <span className={cn('font-mono text-[11px] font-semibold', color)}>
                {value}
            </span>
            <span className="h-1 w-12 shrink-0 overflow-hidden rounded-full bg-surface-3">
                <span
                    className={cn('block h-full', bgColor)}
                    style={{ width: `${value}%` }}
                />
            </span>
        </span>
    );
}

// ---- SectionTitle ----

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
            {children}
        </h3>
    );
}

// ---- DetailSide ----

interface DetailSideProps {
    incident: IncidentDetail;
}

export function DetailSide({ incident }: DetailSideProps) {
    const ctx = incident.operationalContext;

    return (
        <div className="flex flex-col gap-5">
            {/* Assignee */}
            <section>
                <SectionTitle>Asignación</SectionTitle>
                <div
                    className="grid items-center gap-2.5 rounded-[6px] border border-border bg-surface-2 p-3"
                    style={{
                        gridTemplateColumns: 'auto minmax(0,1fr) auto',
                    }}
                >
                    {incident.assignee ? (
                        <>
                            <UserAvatar
                                initials={incident.assignee.initials}
                                size={32}
                                isPrimary
                            />
                            <div className="min-w-0">
                                <div className="truncate text-[13px] font-semibold text-fg-1">
                                    {incident.assignee.name}
                                </div>
                                <div className="text-[11px] text-fg-3">
                                    Supervisor · asignado por regla
                                </div>
                            </div>
                            <button
                                type="button"
                                className="cursor-pointer border-none bg-transparent text-[12px] font-medium whitespace-nowrap text-fg-2 hover:text-fg-1"
                            >
                                Reasignar
                            </button>
                        </>
                    ) : (
                        <>
                            <UserAvatar size={32} isEmpty />
                            <div className="min-w-0">
                                <div className="text-[13px] text-fg-2 italic">
                                    Sin asignar
                                </div>
                            </div>
                            <Button size="sm" variant="default">
                                Asignarme
                            </Button>
                        </>
                    )}
                </div>
            </section>

            {/* Actions */}
            <section>
                <SectionTitle>Acciones</SectionTitle>
                <Button
                    variant="default"
                    className="mb-2 w-full justify-center"
                >
                    Resolver incidente
                </Button>
                <div className="mt-1 flex flex-wrap gap-x-3.5 gap-y-1">
                    <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-[12px] font-medium text-fg-2 hover:text-fg-1"
                    >
                        <TriangleAlert size={12} />
                        Escalar
                    </button>
                    <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-[12px] font-medium text-fg-2 hover:text-fg-1"
                    >
                        <X size={12} />
                        Descartar
                    </button>
                    <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-[12px] font-medium text-fg-2 hover:text-fg-1"
                    >
                        <RefreshCw size={12} />
                        Reabrir
                    </button>
                </div>
            </section>

            {/* Operational context */}
            <section>
                <SectionTitle>Contexto operativo</SectionTitle>
                <div className="flex flex-col overflow-hidden rounded-[6px] border border-border bg-surface-2">
                    {[
                        { key: 'Clima', value: ctx.weather },
                        { key: 'Tráfico', value: ctx.traffic },
                        {
                            key: 'Riesgo cond.',
                            value: <RiskBar value={ctx.driverRisk} />,
                        },
                        { key: 'Geocerca', value: ctx.geofenceStatus },
                        { key: 'H. conducción', value: ctx.drivingHours },
                    ].map(({ key, value }) => (
                        <div
                            key={key}
                            className="grid items-center gap-3 border-b border-border px-3 py-2 text-[12px] last:border-b-0"
                            style={{
                                gridTemplateColumns: 'minmax(88px,auto) 1fr',
                            }}
                        >
                            <span className="text-[11px] font-medium whitespace-nowrap text-fg-3">
                                {key}
                            </span>
                            <span className="justify-self-end text-right text-fg-1">
                                {value}
                            </span>
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
