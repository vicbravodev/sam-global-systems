import { useEffect, useState } from 'react';
import { postJson } from '@/lib/sam-fetch';
import type { HosPreview } from '@/types/hos';

/** Espera antes de reintentar tras un 429 (el límite es por minuto). */
export const HOS_PREVIEW_RETRY_MS = 20_000;

export interface HosPreviewSelection {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
}

export type HosPreviewState =
    | { status: 'idle' }
    | { status: 'loading'; last: HosPreview | null }
    | { status: 'ready'; preview: HosPreview }
    /** Demasiadas consultas seguidas (429): se reintenta solo. */
    | { status: 'throttled'; last: HosPreview | null }
    | { status: 'error' };

type PreviewOutcome =
    | { kind: 'ok'; preview: HosPreview }
    | { kind: 'throttled' }
    | { kind: 'error' };

/** Cuerpo de la petición, ordenado: la misma selección da la misma llave. */
export function selectionKey(selection: HosPreviewSelection): string {
    return JSON.stringify({
        tag_ids: [...selection.tagIds].sort(),
        included_asset_ids: [...selection.includedAssetIds].sort(
            (a, b) => a - b,
        ),
        excluded_asset_ids: [...selection.excludedAssetIds].sort(
            (a, b) => a - b,
        ),
    });
}

/** Fuera del hook: el compilador no admite try/catch con await dentro. */
async function fetchHosPreview(
    url: string,
    body: Record<string, unknown>,
    signal: AbortSignal,
): Promise<PreviewOutcome> {
    try {
        const response = await postJson(url, body, signal);

        if (response.status === 429) {
            return { kind: 'throttled' };
        }

        if (!response.ok) {
            return { kind: 'error' };
        }

        const json = (await response.json()) as { data: HosPreview };

        return { kind: 'ok', preview: json.data };
    } catch {
        return { kind: 'error' };
    }
}

/**
 * "N tractos · M choferes entran ahora" de la selección en borrador. Pide
 * `delayMs` después del último cambio y cancela la petición anterior; el
 * servidor calcula sobre la última lectura del sondeo (sin ir a Samsara).
 * Ante un 429 conserva el último resultado y reintenta pasado un rato.
 */
export function useHosPreview(
    url: string | null,
    selection: HosPreviewSelection,
    delayMs = 600,
): HosPreviewState {
    const key = selectionKey(selection);
    const [settled, setSettled] = useState<{
        key: string;
        outcome: PreviewOutcome;
        /** Último resultado bueno, de esta selección o de una anterior. */
        last: HosPreview | null;
    } | null>(null);

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();
        let retry: number | undefined;

        const run = () => {
            void fetchHosPreview(
                url,
                JSON.parse(key) as Record<string, unknown>,
                controller.signal,
            ).then((outcome) => {
                if (controller.signal.aborted) {
                    return;
                }

                setSettled((previous) => ({
                    key,
                    outcome,
                    last:
                        outcome.kind === 'ok'
                            ? outcome.preview
                            : (previous?.last ?? null),
                }));

                if (outcome.kind === 'throttled') {
                    retry = window.setTimeout(run, HOS_PREVIEW_RETRY_MS);
                }
            });
        };

        const timer = window.setTimeout(run, delayMs);

        return () => {
            window.clearTimeout(timer);
            window.clearTimeout(retry);
            controller.abort();
        };
    }, [url, key, delayMs]);

    if (url === null) {
        return { status: 'idle' };
    }

    if (settled === null || settled.key !== key) {
        return { status: 'loading', last: settled?.last ?? null };
    }

    switch (settled.outcome.kind) {
        case 'ok':
            return { status: 'ready', preview: settled.outcome.preview };
        case 'throttled':
            return { status: 'throttled', last: settled.last };
        default:
            return { status: 'error' };
    }
}
