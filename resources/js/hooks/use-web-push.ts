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

async function attemptEnable(
    publicKey: string,
): Promise<{ status: WebPushStatus | null; failed: boolean }> {
    try {
        const result = await subscribeDevice(publicKey);

        if (result === 'saved') {
            return { status: 'on', failed: false };
        }

        // Con el guardado fallido no se re-lee el estado: el navegador sí
        // tiene suscripción, pero el servidor no, y no llegaría ningún aviso.
        return {
            status:
                result === 'failed' ? 'off' : await resolveStatus(publicKey),
            failed: result === 'failed',
        };
    } catch {
        return { status: 'off', failed: true };
    }
}

async function attemptDisable(
    publicKey: string | null,
): Promise<WebPushStatus> {
    try {
        await unsubscribeDevice();
    } catch {
        // Se re-lee el estado real más abajo.
    }

    try {
        return await resolveStatus(publicKey);
    } catch {
        return 'off';
    }
}

export function useWebPush() {
    const publicKey = usePage().props.webPush?.publicKey ?? null;
    const [status, setStatus] = useState<WebPushStatus>('loading');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

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
        setFailed(false);
        const result = await attemptEnable(publicKey);
        setBusy(false);

        if (result.status) {
            setStatus(result.status);
        }

        setFailed(result.failed);
    }

    async function disable(): Promise<void> {
        setBusy(true);
        setFailed(false);
        setStatus(await attemptDisable(publicKey));
        setBusy(false);
    }

    return { status, busy, failed, enable, disable };
}
