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

export type SubscribeResult = 'saved' | 'denied' | 'failed';

function sameKey(a: ArrayBuffer | null, b: Uint8Array): boolean {
    if (!a || a.byteLength !== b.length) {
        return false;
    }

    const bytes = new Uint8Array(a);

    return bytes.every((value, index) => value === b[index]);
}

/**
 * Devuelve una suscripción hecha con la llave VAPID actual: si la existente
 * se creó con otra (rotación de llaves), se da de baja y se crea de nuevo.
 */
async function ensureSubscription(
    registration: ServiceWorkerRegistration,
    publicKey: string,
): Promise<PushSubscription> {
    const key = urlBase64ToUint8Array(publicKey);
    const existing = await registration.pushManager.getSubscription();

    if (existing && sameKey(existing.options.applicationServerKey, key)) {
        return existing;
    }

    if (existing) {
        await existing.unsubscribe();
    }

    return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
    });
}

/**
 * Pide permiso (sólo llamar desde un clic) y registra este dispositivo.
 */
export async function subscribeDevice(
    publicKey: string,
): Promise<SubscribeResult> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return 'denied';
    }

    const registration = await registerServiceWorker();

    if (!registration) {
        return 'failed';
    }

    const subscription = await ensureSubscription(registration, publicKey);

    return (await saveSubscription(subscription)) ? 'saved' : 'failed';
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
export async function syncSubscription(
    publicKey: string | null,
): Promise<void> {
    if (
        !publicKey ||
        pushSupport() !== 'supported' ||
        Notification.permission !== 'granted'
    ) {
        return;
    }

    const registration = await registerServiceWorker();

    if (!registration || !(await registration.pushManager.getSubscription())) {
        return;
    }

    await saveSubscription(await ensureSubscription(registration, publicKey));
}
