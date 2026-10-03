import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import assetRoutes from '@/routes/assets';
import type { AssetsIndexProps } from '@/types/assets';

/**
 * "Vigilar todas": enciende cada unidad pendiente. El servidor avisa si con
 * eso se rebasa el tope (se cobra como extra, no se bloquea).
 */
export function useMonitorAllPending(teamSlug: string | null) {
    const [monitoringAll, setMonitoringAll] = useState(false);

    const monitorAllPending = () => {
        if (teamSlug === null) {
            return;
        }

        setMonitoringAll(true);
        router.reload({
            only: ['assets'],
            data: { monitoring: 'pending', page: undefined },
            onSuccess: (page) => {
                const pending = (
                    (page.props.assets as AssetsIndexProps['assets']) ?? []
                ).map((asset) => asset.id);

                if (pending.length === 0) {
                    setMonitoringAll(false);

                    return;
                }

                router.put(
                    assetRoutes.monitoring.bulk.url(teamSlug),
                    { state: 'monitored', asset_ids: pending },
                    {
                        preserveScroll: true,
                        onSuccess: (result) => {
                            const flash = (
                                result.props as {
                                    flash?: { status?: string | null };
                                }
                            ).flash;
                            toast.success(
                                flash?.status ?? 'Unidades encendidas.',
                            );
                        },
                        onError: (errors) =>
                            toast.error(
                                errors.monitoring ??
                                    'No se pudieron encender las unidades.',
                            ),
                        onFinish: () => setMonitoringAll(false),
                    },
                );
            },
            onError: () => setMonitoringAll(false),
        });
    };

    return { monitoringAll, monitorAllPending };
}
