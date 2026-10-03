import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

// Every prop of the page. "Refrescar" names them all: a reload without `only`
// would drop the deferred ones (kpis, integrations, usage) back to their
// skeletons until the follow-up deferred request lands.
const ALL_KEYS = ['kpis', 'incidents', 'stream', 'integrations', 'usage'];

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

export function DashboardHeader({
    criticalCount,
    openCount,
}: {
    /** `null` while the deferred KPIs are still loading. */
    criticalCount: number | null;
    openCount: number | null;
}) {
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ALL_KEYS,
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <header className="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="sam-h1">Panel operativo</h1>
                <p className="sam-meta mt-1">
                    {shiftLabel(new Date())} ·{' '}
                    {openCount === null || criticalCount === null ? (
                        <Skeleton className="inline-block h-3 w-36 align-middle" />
                    ) : (
                        <>
                            <span className="text-fg-2">
                                {openCount} abiertos
                            </span>{' '}
                            ·{' '}
                            <span className="text-severity-critical">
                                {criticalCount} críticos
                            </span>
                        </>
                    )}
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
