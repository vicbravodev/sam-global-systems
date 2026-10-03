import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import type {
    InboxFilterOptions,
    InboxFilters,
    InboxTab,
    IncidentAbilities,
    MockIncident,
} from '@/types/sam';

export const INBOX_TABS: { key: InboxTab; label: string }[] = [
    { key: 'open', label: 'Abiertos' },
    { key: 'mine', label: 'Míos' },
    { key: 'unassigned', label: 'Sin asignar' },
    { key: 'sla', label: 'SLA crítico' },
    { key: 'all', label: 'Todos' },
    { key: 'discarded', label: 'Descartados' },
];

export const NO_ABILITIES: IncidentAbilities = {
    manage: false,
    resolve: false,
    close: false,
    requestMedia: false,
    reevaluate: false,
};

export const EMPTY_INBOX_FILTERS: InboxFilters = {
    q: null,
    severity: null,
    status: null,
    provider: null,
    shift: null,
};

export const EMPTY_INBOX_OPTIONS: InboxFilterOptions = {
    severities: [],
    statuses: [],
    providers: [],
    shifts: [],
};

export const NETWORK_ERROR = 'Error de red. Vuelve a intentarlo.';

/**
 * POST de una acción sobre un incidente. `null` = error de red. Vive fuera
 * del componente: el React Compiler aún no compila condicionales dentro de
 * try/catch.
 */
export async function postIncidentAction(
    url: string,
    body: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; message: string | null } | null> {
    try {
        const response = await postJson(url, body);

        return {
            ok: response.ok,
            status: response.status,
            message: response.ok ? null : await readErrorMessage(response),
        };
    } catch {
        return null;
    }
}

/**
 * Whether an `incidents.updated` echo belongs to an action this tab took in
 * the last OWN_ECHO_WINDOW_MS. Expired entries are pruned on the way.
 */
export function isOwnEcho(actedUntil: Map<number, number>, incidentId: number) {
    const until = actedUntil.get(incidentId);

    if (until === undefined) {
        return false;
    }

    if (until < Date.now()) {
        actedUntil.delete(incidentId);

        return false;
    }

    return true;
}

export function openOnly(incidents: MockIncident[]): MockIncident[] {
    return incidents.filter(
        (i) => !['resolved', 'closed', 'discarded'].includes(i.status),
    );
}

/** The rows a tab shows, ordered by SLA (most urgent first). */
export function inboxRows(
    tab: InboxTab,
    incidents: MockIncident[],
    openIncidents: MockIncident[],
    currentUserId: number | null,
): MockIncident[] {
    let source: MockIncident[];

    switch (tab) {
        case 'open':
            source = openIncidents;
            break;
        case 'mine':
            source = incidents.filter(
                (i) =>
                    currentUserId !== null && i.assignee?.id === currentUserId,
            );
            break;
        case 'unassigned':
            source = openIncidents.filter((i) => !i.assignee);
            break;
        case 'sla':
            source = openIncidents
                .filter((i) => i.slaSeconds < 900)
                .sort((a, b) => a.slaSeconds - b.slaSeconds);
            break;
        case 'discarded':
            source = incidents.filter((i) => i.status === 'discarded');
            break;
        default:
            source = incidents;
    }

    if (tab !== 'sla') {
        return [...source].sort((a, b) => a.slaSeconds - b.slaSeconds);
    }

    return source;
}
