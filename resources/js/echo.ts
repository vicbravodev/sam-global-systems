import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

type PusherEcho = Echo<'pusher'>;

let instance: PusherEcho | null = null;

function getPusherEnv() {
    const key = import.meta.env.VITE_PUSHER_APP_KEY;
    const host = import.meta.env.VITE_PUSHER_HOST;
    const portRaw = import.meta.env.VITE_PUSHER_PORT;
    const scheme = import.meta.env.VITE_PUSHER_SCHEME ?? 'http';

    if (!key || !host || !portRaw) {
        return null;
    }

    const port = Number(portRaw);

    if (!Number.isFinite(port)) {
        return null;
    }

    return { key, host, port, scheme };
}

export function createEcho(): PusherEcho | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (instance) {
        return instance;
    }

    const env = getPusherEnv();

    if (env === null) {
        return null;
    }

    window.Pusher = Pusher;

    instance = new Echo({
        broadcaster: 'pusher',
        key: env.key,
        wsHost: env.host,
        wsPort: env.port,
        wssPort: env.port,
        forceTLS: env.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: 'mt1',
        disableStats: true,
        authEndpoint: '/broadcasting/auth',
    });

    return instance;
}

export function getEcho(): PusherEcho | null {
    return instance;
}

export function resetEcho(): void {
    if (instance) {
        instance.disconnect();
        instance = null;
    }
}

export type { PusherEcho };
