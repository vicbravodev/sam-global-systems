import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';
import { useEffect, useSyncExternalStore } from 'react';

type PusherEcho = Echo<'pusher'>;

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

// Created on first use by the realtime hooks mounted in the Ops/Admin
// layouts, never at module load: public and auth pages open no socket and
// never download laravel-echo / pusher-js (they load via dynamic import).

let instance: PusherEcho | null = null;
let loading: Promise<PusherEcho | null> | null = null;
let loadFailed = false;
const listeners = new Set<() => void>();

function notify(): void {
    listeners.forEach((listener) => listener());
}

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

/**
 * Whether a socket can exist here at all (browser + Pusher env). Until the
 * client finishes loading, callers can report "connecting" instead of
 * "disconnected".
 */
export function isEchoAvailable(): boolean {
    return typeof window !== 'undefined' && getPusherEnv() !== null;
}

/** True once loading the client failed (offline chunk, blocked import). */
export function echoLoadFailed(): boolean {
    return loadFailed;
}

/**
 * Loads the client code and creates the shared instance on first call;
 * later calls resolve to the same instance. Resolves `null` on the server,
 * without Pusher env, or when the chunk fails to load (a later call retries).
 */
export function loadEcho(): Promise<PusherEcho | null> {
    if (instance) {
        return Promise.resolve(instance);
    }

    const env = typeof window === 'undefined' ? null : getPusherEnv();

    if (env === null) {
        return Promise.resolve(null);
    }

    loading ??= Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: EchoClient }, { default: PusherClient }]) => {
            window.Pusher = PusherClient;

            instance ??= new EchoClient({
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
            loadFailed = false;
            notify();

            return instance;
        })
        .catch(() => {
            loading = null;
            loadFailed = true;
            notify();

            return null;
        });

    return loading;
}

/** The instance if it already loaded, without triggering a load. */
export function getEcho(): PusherEcho | null {
    return instance;
}

/** Notified when the instance appears, fails to load or is reset. */
export function subscribeEcho(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

/**
 * The shared instance for effects: `null` until the client has loaded (the
 * component re-renders once it is ready, so effects keyed on it subscribe
 * then). Starts the load on mount.
 */
export function useEcho(): PusherEcho | null {
    const echo = useSyncExternalStore(subscribeEcho, getEcho, () => null);

    useEffect(() => {
        void loadEcho();
    }, []);

    return echo;
}

export function resetEcho(): void {
    if (instance) {
        instance.disconnect();
        instance = null;
    }

    loading = null;
    notify();
}

export type { PusherEcho };
