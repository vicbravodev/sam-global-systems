import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    useBroadcastReload,
    useTeamBroadcast,
    useTeamBroadcastsSubscription,
} from '@/hooks/use-team-broadcasts';

const PROVIDER_LABELS: Record<string, string> = {
    samsara: 'Samsara',
};

/**
 * Mounted once by the workspace layouts: owns the socket subscription and
 * the reactions that belong to the shell rather than to one page (sidebar
 * badge, in-app notification toasts, "report ready", integration errors).
 */
export function RealtimeBootstrap() {
    useTeamBroadcastsSubscription();

    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const teamId = page.props.currentTeam?.id ?? null;
    const userId =
        (page.props.auth as { user?: { id?: number } | null } | undefined)?.user
            ?.id ?? null;

    // Inbox badge in the sidebar.
    useBroadcastReload({
        'incidents.created': ['navBadges'],
        'incidents.updated': ['navBadges'],
    });

    useTeamBroadcast(['notification.pushed'], ({ payload }) => {
        if (teamSlug === null) {
            return;
        }

        if (payload.team_id !== null && payload.team_id !== teamId) {
            return;
        }

        const href = `/${teamSlug}/notifications/${payload.notification_id}`;
        const show =
            payload.priority === 'critical' || payload.priority === 'high'
                ? toast.warning
                : toast.info;

        show(payload.subject ?? 'Nueva notificación', {
            description: payload.body_preview ?? undefined,
            action: { label: 'Ver', onClick: () => router.visit(href) },
        });
    });

    useTeamBroadcast(['report.ready'], ({ payload }) => {
        if (teamSlug === null || payload.requested_by_user_id !== userId) {
            return;
        }

        const href = `/${teamSlug}/analytics/executions/${payload.report_execution_id}/download`;

        toast.success(`${payload.report_name} está listo`, {
            description: `Formato ${payload.output_format.toUpperCase()}`,
            action: {
                label: 'Descargar',
                onClick: () => window.location.assign(href),
            },
        });
    });

    // Integrations raises its own feedback for the test it just ran.
    const onIntegrationsPage = page.component.startsWith('integrations/');

    useTeamBroadcast(['integration.status_changed'], ({ payload }) => {
        if (payload.status !== 'error' || onIntegrationsPage) {
            return;
        }

        const provider =
            PROVIDER_LABELS[payload.provider_code] ?? payload.provider_code;

        toast.error(`La integración con ${provider} dejó de responder`, {
            description:
                'Revisa las credenciales en Integraciones y vuelve a probar la conexión.',
            action: teamSlug
                ? {
                      label: 'Abrir',
                      onClick: () => router.visit(`/${teamSlug}/integrations`),
                  }
                : undefined,
        });
    });

    return null;
}
