import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { deleteJson, postJson, readErrorMessage } from '@/lib/sam-fetch';
import integrationRoutes from '@/routes/integrations';
import type { IntegrationRow } from '@/types/sam';
import { INTEGRATIONS_RELOAD_PROPS as RELOAD_PROPS } from './lib';

/** Test a connection, and disconnect one (after its confirmation). */
export function useIntegrationActions(teamSlug: string | null) {
    const [disconnecting, setDisconnecting] = useState<IntegrationRow | null>(
        null,
    );
    const [testingId, setTestingId] = useState<number | null>(null);

    const runTest = useCallback(
        async (integration: IntegrationRow) => {
            if (teamSlug === null) {
                toast.error('No hay equipo activo.');

                return;
            }

            setTestingId(integration.id);

            const response = await postJson(
                integrationRoutes.test.url([teamSlug, integration.id]),
            );

            setTestingId(null);

            if (response.status === 403) {
                toast.error('No tienes permisos para probar conexiones.');

                return;
            }

            if (!response.ok) {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo probar la conexión.',
                );
                router.reload({ only: RELOAD_PROPS });

                return;
            }

            const payload = (await response.json()) as {
                data?: { success?: boolean; message?: string };
            };

            if (payload.data?.success) {
                toast.success(
                    `Conexión correcta: SAM puede leer los datos de ${integration.provider}.`,
                );
            } else {
                toast.error(
                    'La prueba falló. En la tarjeta te decimos qué pasó y cómo resolverlo.',
                );
            }

            router.reload({ only: RELOAD_PROPS });
        },
        [teamSlug],
    );

    const disconnect = useCallback(async () => {
        if (disconnecting === null || teamSlug === null) {
            return;
        }

        const response = await deleteJson(
            integrationRoutes.destroy.url([teamSlug, disconnecting.id]),
        );

        if (response.ok) {
            toast.success('Conexión eliminada.');
            setDisconnecting(null);
            router.reload({ only: RELOAD_PROPS });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para desconectar proveedores.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo desconectar el proveedor.',
        );
    }, [disconnecting, teamSlug]);

    return { testingId, runTest, disconnecting, setDisconnecting, disconnect };
}
