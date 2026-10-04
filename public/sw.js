/*
 * Service worker de SAM: sólo avisos al dispositivo. Sin caché offline a
 * propósito (no servir builds viejos). El servidor manda
 * { title, body, url, tag, critical, renotify } (PushPayload.php).
 */
const ICON = '/icons/icon-192.png';
const CRITICAL_VIBRATION = [400, 200, 400, 200, 800];

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'SAM';
    const critical = data.critical === true;

    event.waitUntil(
        self.registration.showNotification(title, {
            body: data.body || '',
            tag: data.tag,
            renotify: Boolean(data.tag) && data.renotify !== false,
            icon: ICON,
            badge: ICON,
            requireInteraction: critical,
            vibrate: critical ? CRITICAL_VIBRATION : [200],
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client
                        .focus()
                        .then((focused) => (focused && 'navigate' in focused ? focused.navigate(target) : undefined))
                        .catch(() => self.clients.openWindow(target));
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    // El navegador rotó la suscripción: la app la vuelve a sincronizar al
    // abrirse (lib/web-push.ts → syncSubscription). Sin sesión garantizada
    // aquí, no intentamos llamar al servidor.
    event.waitUntil(Promise.resolve());
});
