import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { HosPreview } from '@/types/hos';
import {
    HOS_PREVIEW_RETRY_MS,
    selectionKey,
    useHosPreview,
} from './use-hos-preview';
import type { HosPreviewSelection } from './use-hos-preview';

const fetchMock = vi.fn<typeof fetch>();

const PREVIEW: HosPreview = {
    trucks: 13,
    drivers: 43,
    skipped: { no_vehicle: 6 },
    failed: false,
    hasIntegration: true,
};

const SELECTION: HosPreviewSelection = {
    tagIds: ['4738197'],
    includedAssetIds: [],
    excludedAssetIds: [],
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function settle(ms: number): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    fetchMock.mockImplementation(async () => respond({ data: PREVIEW }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

describe('useHosPreview', () => {
    it('espera a que dejen de cambiar la selección y pide una sola vez', async () => {
        const { result, rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );

        expect(result.current).toEqual({ status: 'loading', last: null });

        await settle(300);
        rerender({ selection: { ...SELECTION, includedAssetIds: [5] } });
        await settle(300);
        expect(fetchMock).not.toHaveBeenCalled();

        await settle(300);
        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual({
            tag_ids: ['4738197'],
            included_asset_ids: [5],
            excluded_asset_ids: [],
        });
        expect(result.current).toEqual({ status: 'ready', preview: PREVIEW });
    });

    it('mientras recalcula conserva el último resultado', async () => {
        const { result, rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );
        await settle(600);
        await settle(0);

        rerender({ selection: { ...SELECTION, tagIds: [] } });

        expect(result.current).toEqual({ status: 'loading', last: PREVIEW });
    });

    it('un error del servidor se ve como error', async () => {
        fetchMock.mockImplementation(async () =>
            respond({ message: 'x' }, 500),
        );
        const { result } = renderHook(() =>
            useHosPreview('/preview', SELECTION, 600),
        );

        await settle(600);
        await settle(0);

        expect(result.current).toEqual({ status: 'error' });
    });

    it('demasiadas consultas: conserva el último resultado y reintenta solo', async () => {
        const { result, rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );
        await settle(600);
        await settle(0);

        fetchMock.mockImplementationOnce(async () =>
            respond({ message: 'Too Many Attempts.' }, 429),
        );
        rerender({ selection: { ...SELECTION, tagIds: [] } });
        await settle(600);
        await settle(0);

        expect(result.current).toEqual({ status: 'throttled', last: PREVIEW });
        expect(fetchMock).toHaveBeenCalledTimes(2);

        await settle(HOS_PREVIEW_RETRY_MS);
        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(result.current).toEqual({ status: 'ready', preview: PREVIEW });
    });

    it('Reintentar vuelve a pedir la misma selección tras un error', async () => {
        fetchMock.mockImplementationOnce(async () =>
            respond({ message: 'x' }, 500),
        );
        const { result, rerender } = renderHook(
            ({ attempt }) => useHosPreview('/preview', SELECTION, 600, attempt),
            { initialProps: { attempt: 0 } },
        );
        await settle(600);
        await settle(0);

        expect(result.current).toEqual({ status: 'error' });

        rerender({ attempt: 1 });

        expect(result.current).toEqual({ status: 'loading', last: null });

        await settle(600);
        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current).toEqual({ status: 'ready', preview: PREVIEW });
    });

    it('un cambio cancela la petición en curso', async () => {
        const signals: (AbortSignal | null | undefined)[] = [];
        fetchMock.mockImplementation(async (_url, init) => {
            signals.push(init?.signal);

            return new Promise<Response>(() => {});
        });
        const { rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );
        await settle(600);

        rerender({ selection: { ...SELECTION, tagIds: [] } });

        expect(signals[0]?.aborted).toBe(true);
    });

    it('sin url no pide nada', async () => {
        const { result } = renderHook(() =>
            useHosPreview(null, SELECTION, 600),
        );

        await settle(1000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(result.current).toEqual({ status: 'idle' });
    });

    it('la llave no depende del orden de la selección', () => {
        expect(
            selectionKey({
                tagIds: ['2', '1'],
                includedAssetIds: [9, 3],
                excludedAssetIds: [],
            }),
        ).toBe(
            selectionKey({
                tagIds: ['1', '2'],
                includedAssetIds: [3, 9],
                excludedAssetIds: [],
            }),
        );
    });
});
