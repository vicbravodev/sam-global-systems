import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { DataFreshness } from '@/components/sam/assets/data-freshness';
import { RealtimeStatus } from '@/components/sam/realtime-status';
import { Button } from '@/components/ui/button';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import { cn } from '@/lib/utils';
import { connectionToStatus } from './lib';

export interface LiveMapHeaderProps {
    unitCount: number;
    movingCount: number;
    unpositionedCount: number;
    newestAt: string | null;
}

/** Title, fleet figures, data freshness, socket state and refresh. */
export function LiveMapHeader({
    unitCount,
    movingCount,
    unpositionedCount,
    newestAt,
}: LiveMapHeaderProps) {
    const connection = useRealtimeConnection();
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['assets', 'unpositionedCount'],
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
            <div className="flex items-baseline gap-3">
                <h1 className="text-md font-semibold text-fg-1">
                    Mapa en vivo
                </h1>
                <span className="text-xs text-fg-3 tabular-nums">
                    <span className="font-medium text-fg-1">{unitCount}</span>{' '}
                    {unitCount === 1 ? 'unidad' : 'unidades'}
                    {' · '}
                    <span className="font-medium text-fg-1">
                        {movingCount}
                    </span>{' '}
                    en movimiento
                    {unpositionedCount > 0 &&
                        ` · ${unpositionedCount} sin posición`}
                </span>
            </div>
            <div className="flex items-center gap-3">
                <DataFreshness newestAt={newestAt} />
                <RealtimeStatus state={connectionToStatus(connection)} />
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={refresh}
                    disabled={refreshing}
                >
                    <RefreshCw
                        size={13}
                        className={cn(refreshing && 'animate-spin')}
                    />
                    Refrescar
                </Button>
            </div>
        </header>
    );
}
