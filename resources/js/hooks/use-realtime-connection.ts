import type Pusher from 'pusher-js';
import { useSyncExternalStore } from 'react';
import { createEcho } from '@/echo';
import type { RealtimeConnectionState } from '@/types/realtime';

function normalizeState(raw: string): RealtimeConnectionState {
    switch (raw) {
        case 'connected':
            return 'connected';
        case 'connecting':
            return 'connecting';
        case 'unavailable':
            return 'reconnecting';
        case 'failed':
            return 'failed';
        case 'disconnected':
        default:
            return 'disconnected';
    }
}

function currentPusher(): Pusher | null {
    const echo = createEcho();

    if (!echo) {
        return null;
    }

    return (echo.connector as { pusher: Pusher }).pusher;
}

function getSnapshot(): RealtimeConnectionState {
    const pusher = currentPusher();

    return pusher ? normalizeState(pusher.connection.state) : 'disconnected';
}

/**
 * What SSR renders and what the client renders during hydration: there is no
 * socket on the server, so both sides agree on a neutral "connecting" and the
 * real state takes over right after hydration (no mismatch warning).
 */
function getServerSnapshot(): RealtimeConnectionState {
    return 'connecting';
}

function subscribe(onChange: () => void): () => void {
    const pusher = currentPusher();

    if (!pusher) {
        return () => {};
    }

    pusher.connection.bind('state_change', onChange);

    return () => {
        pusher.connection.unbind('state_change', onChange);
    };
}

export function useRealtimeConnection(): RealtimeConnectionState {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
