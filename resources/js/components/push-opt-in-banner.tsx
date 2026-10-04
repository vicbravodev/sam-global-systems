import { usePage } from '@inertiajs/react';
import { BellRing, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useWebPush } from '@/hooks/use-web-push';
import { syncSubscription } from '@/lib/web-push';

const DISMISSED_KEY = 'sam.push-banner.dismissed';

function readDismissed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function writeDismissed(): void {
    try {
        window.localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // Sin almacenamiento: el banner volverá a salir; no es grave.
    }
}

/**
 * Invita a activar avisos en este dispositivo mientras el permiso no se ha
 * pedido. También re-sincroniza la suscripción al abrir la app (rotación del
 * navegador o cambio de usuario/team en el mismo navegador).
 */
export function PushOptInBanner() {
    const { status, busy, enable } = useWebPush();
    const publicKey = usePage().props.webPush?.publicKey ?? null;
    const [dismissed, setDismissed] = useState(readDismissed);

    useEffect(() => {
        void syncSubscription(publicKey).catch(() => undefined);
    }, [publicKey]);

    if (
        dismissed ||
        status !== 'off' ||
        typeof Notification === 'undefined' ||
        Notification.permission !== 'default'
    ) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex shrink-0 flex-col gap-2 border-b border-border bg-surface-2 px-4 py-2 text-sm text-fg-1 sm:flex-row sm:items-center sm:justify-between"
        >
            <span className="flex items-center gap-2">
                <BellRing size={16} className="shrink-0" />
                Recibe las alertas en este dispositivo aunque SAM esté cerrado.
            </span>
            <span className="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={busy}
                    onClick={() => void enable()}
                >
                    Activar
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Ahora no"
                    onClick={() => {
                        writeDismissed();
                        setDismissed(true);
                    }}
                >
                    <X size={16} />
                </Button>
            </span>
        </div>
    );
}
