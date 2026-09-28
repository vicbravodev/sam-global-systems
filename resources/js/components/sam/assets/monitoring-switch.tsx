import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { AssetMonitoringState } from '@/types/assets';

export const MONITORING_LABEL: Record<AssetMonitoringState, string> = {
    monitored: 'Vigilada',
    pending: 'Sin vigilar',
    excluded: 'Excluida',
};

interface MonitoringSwitchProps {
    assetId: number;
    assetName: string;
    state: AssetMonitoringState;
    teamSlug: string | null;
    /** Compact (table cell) or with the state label next to it. */
    withLabel?: boolean;
    className?: string;
}

/**
 * Enciende o apaga la vigilancia de una unidad. Encender más allá del tope
 * contratado se permite: el servidor avisa que se cobra como extra por día.
 */
export function MonitoringSwitch({
    assetId,
    assetName,
    state,
    teamSlug,
    withLabel = false,
    className,
}: MonitoringSwitchProps) {
    const [busy, setBusy] = useState(false);
    const on = state === 'monitored';

    const toggle = (next: boolean) => {
        if (teamSlug === null || busy) {
            return;
        }

        setBusy(true);
        router.put(
            `/${teamSlug}/assets/${assetId}/monitoring`,
            { state: next ? 'monitored' : 'excluded' },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (
                        page.props as { flash?: { status?: string | null } }
                    ).flash;
                    toast.success(
                        flash?.status ??
                            (next
                                ? `${assetName} ahora está vigilada.`
                                : `${assetName} quedó excluida.`),
                    );
                },
                onError: (errors) =>
                    toast.error(
                        errors.monitoring ??
                            'No se pudo cambiar la vigilancia.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <span
            className={cn('inline-flex items-center gap-2', className)}
            onClick={(e) => e.stopPropagation()}
        >
            <Switch
                checked={on}
                disabled={busy || teamSlug === null}
                onCheckedChange={toggle}
                aria-label={`${on ? 'Dejar de vigilar' : 'Vigilar'} ${assetName}`}
            />
            {withLabel && (
                <span
                    className={cn(
                        'text-xs',
                        on
                            ? 'text-health-ok'
                            : state === 'pending'
                              ? 'text-severity-medium'
                              : 'text-fg-3',
                    )}
                >
                    {MONITORING_LABEL[state]}
                </span>
            )}
        </span>
    );
}
