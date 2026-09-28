import { useState, useSyncExternalStore } from 'react';

// One shared 1 s clock for every live SLA on screen, instead of one
// setInterval per row (200 timers on a full inbox). It stops while the tab is
// hidden and, being time-based, catches up exactly when the tab comes back.
const listeners = new Set<() => void>();
let timer: number | null = null;
let nowSeconds = currentSeconds();

function currentSeconds(): number {
    return Math.floor(Date.now() / 1000);
}

function tick(): void {
    nowSeconds = currentSeconds();
    listeners.forEach((listener) => listener());
}

function start(): void {
    if (timer === null && document.visibilityState !== 'hidden') {
        timer = window.setInterval(tick, 1000);
    }
}

function stop(): void {
    if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'hidden') {
        stop();

        return;
    }

    tick();
    start();
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    if (listeners.size === 1) {
        nowSeconds = currentSeconds();
        document.addEventListener('visibilitychange', onVisibilityChange);
        start();
    }

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0) {
            stop();
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        }
    };
}

function getSnapshot(): number {
    return nowSeconds;
}

export function useLiveSla(initialSeconds: number) {
    const now = useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
    const [anchor, setAnchor] = useState(() => ({
        initial: initialSeconds,
        at: currentSeconds(),
    }));

    // A fresh server value restarts the countdown from it.
    if (anchor.initial !== initialSeconds) {
        setAnchor({ initial: initialSeconds, at: nowSeconds });
    }

    return anchor.initial - Math.max(0, now - anchor.at);
}
