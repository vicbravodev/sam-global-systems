import { useEffect, useMemo, useState } from 'react';
import { useTeamBroadcast } from '@/hooks/use-team-broadcasts';
import { getJson } from '@/lib/sam-fetch';
import incidentRoutes from '@/routes/incidents';
import type { IncidentDetail, MockIncident } from '@/types/sam';

/**
 * Detail payload of the inbox row open in the side panel, fetched on demand
 * and cached per row. An `incidents.updated` broadcast for a cached incident
 * drops its entry so the open panel is not left stale.
 */
export function useIncidentDetail(
    incidents: MockIncident[],
    selectedId: string | null,
    teamSlug: string | null,
) {
    const [detailCache, setDetailCache] = useState<
        Record<string, IncidentDetail>
    >({});
    const [failedIds, setFailedIds] = useState<Set<string>>(new Set());

    const selectedRow = useMemo(
        () => incidents.find((i) => i.id === selectedId) ?? null,
        [incidents, selectedId],
    );
    const detail = selectedId ? (detailCache[selectedId] ?? null) : null;
    const failed = selectedId !== null && failedIds.has(selectedId);
    const loading = selectedId !== null && detail === null && !failed;

    // Fetch the full detail payload for the selected row on demand. State is
    // only mutated inside the async callbacks, never synchronously.
    useEffect(() => {
        if (selectedId === null || selectedRow === null || teamSlug === null) {
            return;
        }

        if (detailCache[selectedId] || failedIds.has(selectedId)) {
            return;
        }

        const controller = new AbortController();

        getJson(
            incidentRoutes.show.url([teamSlug, selectedRow.incidentId]),
            controller.signal,
        )
            .then((res) =>
                res.ok
                    ? (res.json() as Promise<IncidentDetail>)
                    : Promise.reject(res),
            )
            .then((data) => {
                setDetailCache((prev) => ({ ...prev, [selectedId]: data }));
            })
            .catch((error: unknown) => {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                setFailedIds((prev) => new Set(prev).add(selectedId));
            });

        return () => controller.abort();
    }, [selectedId, selectedRow, teamSlug, detailCache, failedIds]);

    // An update to an incident whose detail is cached drops that cache entry
    // so the open panel is not left stale (own echoes included: the panel
    // refetches on its own after a mutation anyway).
    useTeamBroadcast(['incidents.updated'], (event) => {
        const rowId = incidents.find(
            (row) => row.incidentId === event.payload.incident_id,
        )?.id;

        if (rowId === undefined) {
            return;
        }

        setDetailCache((prev) => {
            if (!(rowId in prev)) {
                return prev;
            }

            const next = { ...prev };
            delete next[rowId];

            return next;
        });
    });

    /** Drop every cached detail (and past failures), e.g. on refresh. */
    const clear = () => {
        setDetailCache({});
        setFailedIds(new Set());
    };

    /** Drop one row's cached detail so it is fetched again. */
    const invalidate = (rowId: string) => {
        setDetailCache((prev) => {
            const next = { ...prev };
            delete next[rowId];

            return next;
        });
        setFailedIds((prev) => {
            const next = new Set(prev);
            next.delete(rowId);

            return next;
        });
    };

    return { selectedRow, detail, loading, clear, invalidate };
}
