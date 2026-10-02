import type Pusher from 'pusher-js';
import { useSyncExternalStore } from 'react';
import {
    echoLoadFailed,
    getEcho,
    isEchoAvailable,
    loadEcho,
    subscribeEcho,
} from '@/echo';
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
    const echo = getEcho();

    if (!echo) {
        return null;
    }

    return (echo.connector as { pusher: Pusher }).pusher;
}

function getSnapshot(): RealtimeConnectionState {
    const pusher = currentPusher();

    if (pusher) {
        return normalizeState(pusher.connection.state);
    }

    // The client code is still downloading: the socket is on its way.
    return isEchoAvailable() && !echoLoadFailed()
        ? 'connecting'
        : 'disconnected';
}

/**
 * What SSR renders and what the client renders during hydration: there is no
 * socket on the server, so both sides agree on a neutral "connecting" and the
 * real state takes over right after hydration (no mismatch warning).
 */
function getServerSnapshot(): RealtimeConnectionState {
    return 'connecting';
}

/**
 * Follows the socket's state changes, attaching to the connection as soon as
 * the lazily loaded client exists (and re-attaching if it is recreated).
 */
function subscribe(onChange: () => void): () => void {
    let detach = () => {};

    const attach = () => {
        detach();
        detach = () => {};

        const pusher = currentPusher();

        if (!pusher) {
            return;
        }

        pusher.connection.bind('state_change', onChange);
        detach = () => pusher.connection.unbind('state_change', onChange);
    };

    attach();

    const unsubscribeEcho = subscribeEcho(() => {
        attach();
        onChange();
    });

    void loadEcho();

    return () => {
        unsubscribeEcho();
        detach();
    };
}

export function useRealtimeConnection(): RealtimeConnectionState {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
