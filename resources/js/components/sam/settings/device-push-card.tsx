import { BellRing } from 'lucide-react';
import { FormCard } from '@/components/sam/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { WebPushStatus } from '@/hooks/use-web-push';
import { useWebPush } from '@/hooks/use-web-push';

const COPY: Record<WebPushStatus, string> = {
    loading: 'Revisando este dispositivo…',
    unsupported:
        'Este navegador no puede recibir avisos con SAM cerrado. Usa Chrome, Edge, Firefox o Safari actualizados.',
    'needs-install':
        'En iPhone, primero agrega SAM a tu pantalla de inicio: toca Compartir y luego "Agregar a inicio". Abre SAM desde ese ícono y vuelve aquí.',
    unconfigured: 'Los avisos al dispositivo todavía no están disponibles.',
    blocked:
        'Bloqueaste los avisos de SAM en este navegador. Actívalos en la configuración del sitio y vuelve a intentarlo.',
    off: 'Recibe las alertas urgentes y lo que te asignen aunque SAM esté cerrado. Suenan como cualquier notificación del teléfono; las llamadas de emergencia siguen llegando igual.',
    on: 'Este dispositivo recibe tus avisos de SAM aunque la app esté cerrada.',
};

export function DevicePushCard() {
    const { status, busy, enable, disable } = useWebPush();
    const actionable = status === 'off' || status === 'on';

    return (
        <FormCard className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
                <BellRing size={18} className="mt-0.5 shrink-0 text-fg-3" />
                <p className="text-sm text-fg-2">{COPY[status]}</p>
            </div>
            {actionable && (
                <Button
                    type="button"
                    variant={status === 'on' ? 'outline' : 'default'}
                    disabled={busy}
                    onClick={() =>
                        void (status === 'on' ? disable() : enable())
                    }
                    className="shrink-0"
                >
                    {busy && <Spinner />}
                    {status === 'on'
                        ? 'Desactivar en este dispositivo'
                        : 'Activar en este dispositivo'}
                </Button>
            )}
        </FormCard>
    );
}
