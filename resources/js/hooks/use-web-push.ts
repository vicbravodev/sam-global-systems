import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    currentSubscription,
    pushSupport,
    subscribeDevice,
    unsubscribeDevice,
} from '@/lib/web-push';

export type WebPushStatus =
    | 'loading'
    | 'unsupported'
    | 'needs-install'
    | 'unconfigured'
    | 'blocked'
    | 'off'
    | 'on';

async function resolveStatus(publicKey: string | null): Promise<WebPushStatus> {
    const support = pushSupport();

    if (support !== 'supported') {
        return support;
    }

    if (!publicKey) {
        return 'unconfigured';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    return (await currentSubscription()) ? 'on' : 'off';
}

export function useWebPush() {
    const publicKey = usePage().props.webPush?.publicKey ?? null;
    const [status, setStatus] = useState<WebPushStatus>('loading');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;

        void resolveStatus(publicKey).then((next) => {
            if (!cancelled) {
                setStatus(next);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [publicKey]);

    async function enable(): Promise<void> {
        if (!publicKey) {
            return;
        }

        setBusy(true);
        const ok = await subscribeDevice(publicKey).catch(() => false);
        setStatus(ok ? 'on' : await resolveStatus(publicKey));
        setBusy(false);
    }

    async function disable(): Promise<void> {
        setBusy(true);
        await unsubscribeDevice().catch(() => undefined);
        setStatus(await resolveStatus(publicKey));
        setBusy(false);
    }

    return { status, busy, enable, disable };
}
