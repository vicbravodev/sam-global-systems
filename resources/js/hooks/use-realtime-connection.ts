import type Pusher from 'pusher-js';
import { useEffect, useState } from 'react';
import { createEcho } from '@/echo';
import type { RealtimeConnectionState } from '@/types/realtime';

type StateChangePayload = { current: string; previous?: string };

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

function readInitialState(): RealtimeConnectionState {
    const echo = createEcho();

    if (!echo) {
        return 'disconnected';
    }

    const pusher = (echo.connector as { pusher: Pusher }).pusher;

    return normalizeState(pusher.connection.state);
}

export function useRealtimeConnection(): RealtimeConnectionState {
    const [state, setState] =
        useState<RealtimeConnectionState>(readInitialState);

    useEffect(() => {
        const echo = createEcho();

        if (!echo) {
            return;
        }

        const pusher = (echo.connector as { pusher: Pusher }).pusher;

        const handler = (payload: StateChangePayload) => {
            setState(normalizeState(payload.current));
        };

        pusher.connection.bind('state_change', handler);

        return () => {
            pusher.connection.unbind('state_change', handler);
        };
    }, []);

    return state;
}
