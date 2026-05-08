import { RealtimeStatus } from '@/components/sam/realtime-status';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import type { RealtimeConnectionState } from '@/types/realtime';

const STATE_LABEL: Record<RealtimeConnectionState, string> = {
    connecting: 'Conectando…',
    connected: 'Conectado',
    disconnected: 'Desconectado',
    reconnecting: 'Reconectando…',
    failed: 'Sin conexión',
};

function toVisualState(state: RealtimeConnectionState): RealtimeState {
    switch (state) {
        case 'connected':
            return 'ok';
        case 'connecting':
        case 'reconnecting':
            return 'warn';
        case 'disconnected':
        case 'failed':
        default:
            return 'down';
    }
}

interface Props {
    className?: string;
}

export function RealtimeStatusIndicator({ className }: Props) {
    const state = useRealtimeConnection();

    return (
        <RealtimeStatus
            state={toVisualState(state)}
            className={className}
            label={STATE_LABEL[state]}
        />
    );
}
