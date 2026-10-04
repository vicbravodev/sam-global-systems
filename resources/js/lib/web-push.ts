import { deleteJson, postJson } from '@/lib/sam-fetch';
import {
    destroy as destroySubscription,
    store as storeSubscription,
} from '@/routes/push-subscriptions';

export type PushSupport = 'unsupported' | 'needs-install' | 'supported';

const SW_URL = '/sw.js';

function isIos(): boolean {
    return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

/**
 * En iPhone/iPad los avisos sólo existen con SAM agregado a la pantalla de
 * inicio; en el resto basta con Service Worker + Push API.
 */
export function pushSupport(): PushSupport {
    if (typeof window === 'undefined') {
        return 'unsupported';
    }

    if (isIos() && !isStandalone()) {
        return 'needs-install';
    }

    return 'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
        ? 'supported'
        : 'unsupported';
}

export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    try {
        return await navigator.serviceWorker.register(SW_URL, { scope: '/' });
    } catch {
        return null;
    }
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    const registration = await registerServiceWorker();

    return registration ? registration.pushManager.getSubscription() : null;
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

async function saveSubscription(
    subscription: PushSubscription,
): Promise<boolean> {
    const json = subscription.toJSON();
    const response = await postJson(storeSubscription.url(), {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: PushManager.supportedContentEncodings?.includes(
            'aes128gcm',
        )
            ? 'aes128gcm'
            : 'aesgcm',
    });

    return response.ok;
}

/**
 * Pide permiso (sólo llamar desde un clic) y registra este dispositivo.
 */
export async function subscribeDevice(publicKey: string): Promise<boolean> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return false;
    }

    const registration = await registerServiceWorker();

    if (!registration) {
        return false;
    }

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicKey),
        }));

    return saveSubscription(subscription);
}

export async function unsubscribeDevice(): Promise<void> {
    const subscription = await currentSubscription();

    if (!subscription) {
        return;
    }

    await deleteJson(destroySubscription.url(), {
        endpoint: subscription.endpoint,
    });
    await subscription.unsubscribe();
}

/**
 * Al abrir la app con avisos ya activos, re-registra la suscripción: si el
 * navegador la rotó, o si ahora es otro usuario/team quien usa este
 * navegador, el servidor la reasigna (y el anterior deja de recibir aquí).
 */
export async function syncSubscription(): Promise<void> {
    if (
        pushSupport() !== 'supported' ||
        Notification.permission !== 'granted'
    ) {
        return;
    }

    const subscription = await currentSubscription();

    if (subscription) {
        await saveSubscription(subscription);
    }
}
