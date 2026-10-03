import type {
    IntegrationRow,
    IntegrationsSummary,
    TenantIntegrationStatus,
} from '@/types/sam';
import { summaryStatus } from './integration-state';

/** Props reloaded after any change to a connection. */
export const INTEGRATIONS_RELOAD_PROPS = ['integrations', 'summary'];

/** Client-side fallback while the server summary is absent. */
export function summarizeIntegrations(
    integrations: IntegrationRow[],
): IntegrationsSummary {
    const count = (status: TenantIntegrationStatus) =>
        integrations.filter((i) => summaryStatus(i) === status).length;

    return {
        total: integrations.length,
        working: count('active'),
        attention: count('error'),
        pending: count('pending'),
        inactive: count('inactive'),
        events24h: integrations.reduce((n, i) => n + (i.events24h ?? 0), 0),
        assets: 0,
        monitored: 0,
        drivers: 0,
    };
}

/** Empty-state copy per status filter. */
export const FILTER_EMPTY: Record<TenantIntegrationStatus, string> = {
    active: 'Ninguna conexión está funcionando ahora mismo.',
    error: 'Ninguna conexión requiere atención. Todo en orden.',
    pending: 'No hay conexiones pendientes de configurar.',
    inactive: 'No hay conexiones desactivadas.',
};
