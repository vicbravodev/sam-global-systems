import { useEffect, useState } from 'react';
import { getJson } from '@/lib/sam-fetch';
import type { HosTagOption } from '@/types/hos';
import { HOS_PREVIEW_RETRY_MS } from './use-hos-preview';

export type HosTagsState =
    | { status: 'loading' }
    | { status: 'error' }
    /** Demasiadas consultas seguidas (429). */
    | { status: 'throttled' }
    | {
          status: 'ready';
          tags: HosTagOption[];
          failed: boolean;
          hasIntegration: boolean;
      };

async function fetchHosTags(
    url: string,
    signal: AbortSignal,
): Promise<HosTagsState> {
    try {
        const response = await getJson(url, signal);

        if (response.status === 429) {
            return { status: 'throttled' };
        }

        if (!response.ok) {
            return { status: 'error' };
        }

        const body = (await response.json()) as {
            data: HosTagOption[];
            meta: { failed: boolean; hasIntegration: boolean };
        };

        return {
            status: 'ready',
            tags: body.data,
            failed: body.meta.failed,
            hasIntegration: body.meta.hasIntegration,
        };
    } catch {
        return { status: 'error' };
    }
}

/**
 * Etiquetas de Samsara del tenant (caché del servidor compartida con el
 * sondeo). Ante un 429 se reintenta sola, como la vista previa.
 */
export function useHosTags(url: string | null): HosTagsState {
    const [settled, setSettled] = useState<{
        url: string;
        state: HosTagsState;
    } | null>(null);

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();
        let retry: number | undefined;

        const run = () => {
            void fetchHosTags(url, controller.signal).then((state) => {
                if (controller.signal.aborted) {
                    return;
                }

                setSettled({ url, state });

                if (state.status === 'throttled') {
                    retry = window.setTimeout(run, HOS_PREVIEW_RETRY_MS);
                }
            });
        };

        run();

        return () => {
            window.clearTimeout(retry);
            controller.abort();
        };
    }, [url]);

    if (url === null) {
        return { status: 'error' };
    }

    return settled?.url === url ? settled.state : { status: 'loading' };
}
