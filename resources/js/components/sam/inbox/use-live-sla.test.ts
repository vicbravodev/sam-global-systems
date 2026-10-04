import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useLiveSla } from './use-live-sla';

function advance(ms: number): void {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-03T18:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useLiveSla', () => {
    it('cuenta hacia atrás cada segundo', () => {
        const { result } = renderHook(() => useLiveSla(600));

        expect(result.current).toBe(600);

        advance(3000);

        expect(result.current).toBe(597);
    });

    it('sigue contando por debajo de cero (SLA vencido)', () => {
        const { result } = renderHook(() => useLiveSla(2));

        advance(5000);

        expect(result.current).toBe(-3);
    });

    it('reinicia la cuenta con un valor nuevo del servidor', () => {
        const { result, rerender } = renderHook(
            ({ seconds }) => useLiveSla(seconds),
            { initialProps: { seconds: 600 } },
        );

        advance(10_000);
        rerender({ seconds: 900 });

        expect(result.current).toBe(900);

        advance(1000);

        expect(result.current).toBe(899);
    });

    it('comparte un solo reloj entre todas las filas', () => {
        const setInterval = vi.spyOn(window, 'setInterval');

        const first = renderHook(() => useLiveSla(100));
        renderHook(() => useLiveSla(200));

        expect(setInterval).toHaveBeenCalledTimes(1);

        first.unmount();
    });
});
