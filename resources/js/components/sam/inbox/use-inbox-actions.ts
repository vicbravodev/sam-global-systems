import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { postJson } from '@/lib/sam-fetch';
import incidentRoutes from '@/routes/incidents';
import type { MockIncident } from '@/types/sam';
import { isOwnEcho, NETWORK_ERROR, postIncidentAction } from './lib';

// Coalescing window for live inbox reloads.
const INBOX_RELOAD_DEBOUNCE_MS = 1500;

// How long after acting on an incident its broadcast echo is treated as our
// own (the explicit post-action reload already fetched that state). Echoes go
// through the realtime queue, normally well under a second.
const OWN_ECHO_WINDOW_MS = 5000;

interface UseInboxActionsOptions {
    incidents: MockIncident[];
    openIncidents: MockIncident[];
    teamSlug: string | null;
    currentUserId: number | null;
    /** Rows checked for a bulk action. */
    selected: Set<string>;
    clearSelection: () => void;
}

/**
 * The inbox's live reloads and every action taken from the list (row claim,
 * keyboard assign, bulk bar, "assign me the oldest critical").
 */
export function useInboxActions({
    incidents,
    openIncidents,
    teamSlug,
    currentUserId,
    selected,
    clearSelection,
}: UseInboxActionsOptions) {
    const [bulkPending, setBulkPending] = useState<string | null>(null);
    const [assigningOldest, setAssigningOldest] = useState(false);
    /** Incidente cuya toma/liberación está en vuelo, para bloquear su botón. */
    const [claimPendingId, setClaimPendingId] = useState<number | null>(null);

    // Live updates: a freshly created or updated incident (status change,
    // claim, media assessed) refreshes the inbox through the shared reload
    // buffer: bursts coalesce into one 200-row reload, a hidden tab waits
    // until it is visible again, a socket drop resyncs.
    //
    // The operator's own actions reload the list right away (instant
    // feedback), so the broadcast echo of that same action would be a second
    // identical 200-row reload: echoes of incidents this tab just acted on
    // are skipped for OWN_ECHO_WINDOW_MS. Actions without an echo (assign,
    // reclassify) are unaffected — their explicit reload is the only one.
    const actedUntil = useRef<Map<number, number>>(new Map());

    const markActed = (incidentIds: readonly number[]) => {
        const until = Date.now() + OWN_ECHO_WINDOW_MS;
        incidentIds.forEach((id) => actedUntil.current.set(id, until));
    };

    const reload = useBroadcastReload(
        {
            'incidents.created': ['incidents'],
            'incidents.updated': (payload) =>
                isOwnEcho(actedUntil.current, payload.incident_id)
                    ? null
                    : ['incidents'],
        },
        { debounceMs: INBOX_RELOAD_DEBOUNCE_MS },
    );

    /**
     * The one list reload after the operator's own action: immediate, and it
     * supersedes any reload already queued (an echo that beat the response).
     */
    const reloadAfterAction = (incidentIds: readonly number[]) => {
        markActed(incidentIds);
        reload.reloadNow(['incidents']);
    };

    const assignIncidentToMe = async (incident: MockIncident) => {
        if (teamSlug === null || currentUserId === null) {
            return;
        }

        const result = await postIncidentAction(
            incidentRoutes.assign.url([teamSlug, incident.incidentId]),
            { assigned_to_type: 'user', assigned_to_id: currentUserId },
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(`Te asignaste ${incident.id}.`);
            reloadAfterAction([incident.incidentId]);
        } else {
            toast.error(result.message ?? 'No se pudo asignar el incidente.');
        }
    };

    /**
     * Toma/suelta desde la fila. El 409 no es un error del usuario sino una
     * carrera perdida: se avisa con el mensaje del servidor y se refresca para
     * que la fila pase a mostrar quién ganó.
     */
    const toggleClaim = async (incident: MockIncident) => {
        if (teamSlug === null) {
            return;
        }

        const mine =
            incident.claimedBy !== null &&
            incident.claimedBy.id === currentUserId;
        const action = mine ? incidentRoutes.release : incidentRoutes.claim;

        setClaimPendingId(incident.incidentId);

        const result = await postIncidentAction(
            action.url([teamSlug, incident.incidentId]),
            {},
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(
                mine ? `Soltaste ${incident.id}.` : `Tomaste ${incident.id}.`,
            );
            reloadAfterAction([incident.incidentId]);
        } else {
            toast.error(result.message ?? 'No se pudo completar la acción.');

            // Lost race: show who won. Not our echo, so nothing is marked.
            if (result.status === 409) {
                reload.reloadNow(['incidents']);
            }
        }

        setClaimPendingId(null);
    };

    // Run a bulk action over the current selection, then refresh + clear.
    const runBulk = async (
        key: string,
        buildBody: (incident: MockIncident) => Record<string, unknown>,
        action: typeof incidentRoutes.assign,
        verb: string,
    ) => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        const targets = incidents.filter((i) => selected.has(i.id));

        if (targets.length === 0) {
            return;
        }

        setBulkPending(key);

        const results = await Promise.allSettled(
            targets.map((incident) =>
                postJson(
                    action.url([teamSlug, incident.incidentId]),
                    buildBody(incident),
                ),
            ),
        );

        const succeeded = targets.filter((_, index) => {
            const result = results[index];

            return result?.status === 'fulfilled' && result.value.ok;
        });
        const ok = succeeded.length;
        const failed = targets.length - ok;

        setBulkPending(null);
        clearSelection();

        if (ok > 0) {
            toast.success(`${ok} ${verb}.`);
        }

        if (failed > 0) {
            toast.error(`${failed} no se pudieron procesar.`);
        }

        // One reload for the whole batch; the echoes of the incidents that
        // changed are covered by it.
        reloadAfterAction(succeeded.map((incident) => incident.incidentId));
    };

    const bulkAssign = () => {
        if (currentUserId === null) {
            toast.error('No se pudo identificar tu usuario.');

            return;
        }

        void runBulk(
            'assign',
            () => ({ assigned_to_type: 'user', assigned_to_id: currentUserId }),
            incidentRoutes.assign,
            'asignados',
        );
    };

    const bulkEscalate = () =>
        void runBulk(
            'escalate',
            () => ({}),
            incidentRoutes.escalate,
            'escalados',
        );

    const bulkDiscard = () =>
        void runBulk(
            'discard',
            () => ({
                resolution_code: 'false_positive',
                summary: 'Descartado por el operador.',
            }),
            incidentRoutes.resolve,
            'descartados',
        );

    const assignOldestCritical = async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        if (currentUserId === null) {
            toast.error('No se pudo identificar tu usuario.');

            return;
        }

        const candidates = openIncidents.filter(
            (i) => i.severity === 'critical',
        );

        if (candidates.length === 0) {
            toast('No hay incidentes críticos abiertos.');

            return;
        }

        const oldest = candidates.reduce((a, b) =>
            a.ageMin >= b.ageMin ? a : b,
        );

        setAssigningOldest(true);

        const result = await postIncidentAction(
            incidentRoutes.assign.url([teamSlug, oldest.incidentId]),
            { assigned_to_type: 'user', assigned_to_id: currentUserId },
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(`Te asignaste ${oldest.id}.`);
            reloadAfterAction([oldest.incidentId]);
        } else if (result.status === 403) {
            toast.error('No tienes permisos para asignar.');
        } else {
            toast.error(result.message ?? 'No se pudo asignar el incidente.');
        }

        setAssigningOldest(false);
    };

    return {
        reloadAfterAction,
        assignIncidentToMe,
        toggleClaim,
        claimPendingId,
        bulkPending,
        bulkAssign,
        bulkEscalate,
        bulkDiscard,
        assignOldestCritical,
        assigningOldest,
    };
}
