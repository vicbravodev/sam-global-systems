import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HOS_PREVIEW_RETRY_MS } from './use-hos-preview';
import { useHosTags } from './use-hos-tags';

const fetchMock = vi.fn<typeof fetch>();

const BODY = {
    data: [
        {
            id: '1',
            name: 'Cruce',
            parentId: null,
            depth: 0,
            kind: 'vehicles',
            vehicleCount: 2,
            driverCount: 0,
        },
    ],
    meta: { failed: false, hasIntegration: true },
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
    fetchMock.mockImplementation(async () => respond(BODY));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

describe('useHosTags', () => {
    it('lee las etiquetas una vez', async () => {
        const { result } = renderHook(() => useHosTags('/tags'));

        expect(result.current).toEqual({ status: 'loading' });

        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(result.current).toMatchObject({
            status: 'ready',
            failed: false,
            hasIntegration: true,
        });
    });

    it('demasiadas consultas: se reintenta sola, igual que la vista previa', async () => {
        fetchMock.mockImplementationOnce(async () =>
            respond({ message: 'Too Many Attempts.' }, 429),
        );
        const { result } = renderHook(() => useHosTags('/tags'));
        await settle(0);

        expect(result.current).toEqual({ status: 'throttled' });

        await settle(HOS_PREVIEW_RETRY_MS);
        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current).toMatchObject({ status: 'ready' });
    });
});
