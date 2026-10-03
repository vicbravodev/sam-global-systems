import { Inbox, LayoutList, Loader2, Rows3 } from 'lucide-react';
import type { ReactNode } from 'react';
import { RefreshButton } from '@/components/sam/list-page';
import { PermissionTooltip } from '@/components/sam/permission-tooltip';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { InboxLayout } from '@/types/sam';

export interface InboxHeaderProps {
    openCount: number;
    criticalCount: number;
    layout: InboxLayout;
    setLayout: (l: InboxLayout) => void;
    onRefresh: () => void;
    refreshing: boolean;
    onAssignOldestCritical: () => void;
    assigningOldest: boolean;
    canAssign: boolean;
}

export function InboxHeader({
    openCount,
    criticalCount,
    layout,
    setLayout,
    onRefresh,
    refreshing,
    onAssignOldestCritical,
    assigningOldest,
    canAssign,
}: InboxHeaderProps) {
    const layouts: {
        value: InboxLayout;
        icon: ReactNode;
        label: string;
    }[] = [
        {
            value: 'table',
            icon: <LayoutList size={14} strokeWidth={1.75} />,
            label: 'Tabla',
        },
        {
            value: 'grouped',
            icon: <Rows3 size={14} strokeWidth={1.75} />,
            label: 'Agrupado',
        },
        {
            value: 'stream',
            icon: <Inbox size={14} strokeWidth={1.75} />,
            label: 'Stream',
        },
    ];

    return (
        <PageHeader
            title="Bandeja de incidentes"
            meta={
                <div className="flex items-center gap-2 text-xs text-fg-3">
                    <span>
                        <span className="font-medium text-fg-1">
                            {openCount}
                        </span>{' '}
                        abiertos
                    </span>
                    <span>·</span>
                    <span className="flex items-center gap-1">
                        <span className="relative inline-flex size-1.5">
                            <span className="absolute inline-flex h-full w-full rounded-full bg-severity-critical opacity-60 motion-safe:animate-ping" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-severity-critical" />
                        </span>
                        <span className="font-medium text-severity-critical">
                            {criticalCount}
                        </span>{' '}
                        críticos
                    </span>
                </div>
            }
            actions={
                <>
                    {/* Layout switcher */}
                    <div className="flex items-center gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                        {layouts.map((l) => (
                            <button
                                key={l.value}
                                type="button"
                                onClick={() => setLayout(l.value)}
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-sm px-2 py-1 text-2xs font-medium transition-colors',
                                    layout === l.value
                                        ? 'bg-surface-1 text-fg-1 shadow-sm'
                                        : 'text-fg-3 hover:text-fg-2',
                                )}
                                title={l.label}
                            >
                                {l.icon}
                            </button>
                        ))}
                    </div>

                    <RefreshButton
                        onClick={onRefresh}
                        refreshing={refreshing}
                    />

                    {!canAssign ? (
                        <PermissionTooltip
                            allowed={false}
                            reason="Tu rol no permite asignar incidentes."
                        >
                            <Button variant="outline" size="sm" disabled>
                                Asignarme crítico más viejo
                            </Button>
                        </PermissionTooltip>
                    ) : criticalCount === 0 ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span tabIndex={0}>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled
                                        className="pointer-events-none"
                                    >
                                        Asignarme crítico más viejo
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                No hay incidentes críticos abiertos ahora mismo.
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onAssignOldestCritical}
                            disabled={assigningOldest}
                        >
                            {assigningOldest ? (
                                <Loader2 size={13} className="animate-spin" />
                            ) : null}
                            Asignarme crítico más viejo
                        </Button>
                    )}
                </>
            }
            className="shrink-0 border-b border-border bg-background px-5 py-3"
        />
    );
}
