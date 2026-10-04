import { router, usePage } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useEcho } from '@/echo';
import {
    REALTIME_RESYNC_EVENT_NAME,
    TEAM_BROADCAST_EVENT_NAME,
    useBroadcastReload,
    useReloadBuffer,
    useTeamBroadcast,
    useTeamBroadcastsSubscription,
} from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import type { IncidentCreatedPayload } from '@/types/realtime';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: vi.fn(),
}));
vi.mock('@/echo', () => ({ useEcho: vi.fn() }));

const reload = vi.mocked(router.reload);

const INCIDENT: IncidentCreatedPayload = {
    incident_id: 7,
    title: 'Botón de pánico',
    priority: 'critical',
    status: 'open',
    asset_id: 3,
    driver_id: null,
    opened_at: '2026-10-03T18:00:00Z',
};

function broadcast(detail: TeamBroadcastDetail): void {
    act(() => {
        window.dispatchEvent(
            new CustomEvent(TEAM_BROADCAST_EVENT_NAME, { detail }),
        );
    });
}

function advance(ms: number): void {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

let hidden = false;

function setHidden(value: boolean): void {
    hidden = value;
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    hidden = false;
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    reload.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useBroadcastReload', () => {
    it('agrupa varios eventos en una sola recarga parcial', () => {
        renderHook(() =>
            useBroadcastReload({
                'incidents.created': ['incidents', 'counts'],
                'incidents.updated': ['incidents'],
            }),
        );

        broadcast({ event: 'incidents.created', payload: INCIDENT });
        advance(500);
        broadcast({
            event: 'incidents.updated',
            payload: {
                incident_id: 7,
                status: 'acknowledged',
                priority: 'critical',
                assigned_to: null,
                updated_at: '2026-10-03T18:01:00Z',
            },
        });

        expect(reload).not.toHaveBeenCalled();

        advance(1000);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({
            only: ['incidents', 'counts'],
        });
    });

    it('ignora eventos sin regla', () => {
        renderHook(() => useBroadcastReload({ 'incidents.created': ['x'] }));

        broadcast({
            event: 'report.ready',
            payload: {} as TeamBroadcastDetail<'report.ready'>['payload'],
        });
        advance(5000);

        expect(reload).not.toHaveBeenCalled();
    });

    it('deja que una regla descarte un payload devolviendo null', () => {
        renderHook(() =>
            useBroadcastReload({
                'incidents.created': (payload) =>
                    payload.priority === 'critical' ? ['incidents'] : null,
            }),
        );

        broadcast({
            event: 'incidents.created',
            payload: { ...INCIDENT, priority: 'low' },
        });
        advance(2000);
        expect(reload).not.toHaveBeenCalled();

        broadcast({ event: 'incidents.created', payload: INCIDENT });
        advance(2000);
        expect(reload).toHaveBeenCalledWith({ only: ['incidents'] });
    });

    it('no recarga con la pestaña oculta y se pone al día al volver', () => {
        renderHook(() =>
            useBroadcastReload({ 'incidents.created': ['incidents'] }),
        );

        setHidden(true);
        broadcast({ event: 'incidents.created', payload: INCIDENT });
        advance(10_000);
        expect(reload).not.toHaveBeenCalled();

        setHidden(false);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({ only: ['incidents'] });
    });

    it('tras una caída del socket recarga todas las claves fijas', () => {
        renderHook(() =>
            useBroadcastReload(
                {
                    'incidents.created': ['incidents'],
                    'incidents.updated': () => ['detail'],
                },
                { resync: ['counts'] },
            ),
        );

        act(() => {
            window.dispatchEvent(new Event(REALTIME_RESYNC_EVENT_NAME));
        });
        advance(1500);

        // Las reglas-función dependen del payload: no hay uno que reenviar.
        expect(reload).toHaveBeenCalledWith({ only: ['incidents', 'counts'] });
    });

    it('respeta el intervalo mínimo de una clave cara', () => {
        renderHook(() =>
            useBroadcastReload(
                { 'incidents.created': ['incidents', 'stats'] },
                { minIntervalMs: { stats: 10_000 } },
            ),
        );

        broadcast({ event: 'incidents.created', payload: INCIDENT });
        advance(1500);

        // Las props llegaron frescas al montar: `stats` aún no puede ir.
        expect(reload).toHaveBeenLastCalledWith({ only: ['incidents'] });

        advance(8500);

        expect(reload).toHaveBeenCalledTimes(2);
        expect(reload).toHaveBeenLastCalledWith({ only: ['stats'] });
    });

    it('cancela lo pendiente al desmontar', () => {
        const { unmount } = renderHook(() =>
            useBroadcastReload({ 'incidents.created': ['incidents'] }),
        );

        broadcast({ event: 'incidents.created', payload: INCIDENT });
        unmount();
        advance(5000);

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('useReloadBuffer', () => {
    it('reloadNow recarga al instante y absorbe lo pendiente de esas claves', () => {
        const { result } = renderHook(() => useReloadBuffer());

        act(() => {
            result.current.schedule(['incidents']);
            result.current.reloadNow(['incidents']);
        });

        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({ only: ['incidents'] });

        advance(5000);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('un retraso menor adelanta la recarga, uno mayor no la atrasa', () => {
        const { result } = renderHook(() => useReloadBuffer());

        act(() => {
            result.current.schedule(['a']);
            result.current.schedule(['b'], 200);
            result.current.schedule(['c'], 10_000);
        });
        advance(200);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({ only: ['a', 'b', 'c'] });
    });
});

describe('useTeamBroadcast', () => {
    it('entrega sólo los eventos pedidos, con el handler más reciente', () => {
        const first = vi.fn();
        const second = vi.fn();
        const { rerender } = renderHook(
            ({ handler }) => useTeamBroadcast(['incidents.created'], handler),
            { initialProps: { handler: first } },
        );

        rerender({ handler: second });
        broadcast({ event: 'incidents.created', payload: INCIDENT });
        broadcast({
            event: 'report.ready',
            payload: {} as TeamBroadcastDetail<'report.ready'>['payload'],
        });

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledTimes(1);
        expect(second).toHaveBeenCalledWith({
            event: 'incidents.created',
            payload: INCIDENT,
        });
    });
});

describe('useTeamBroadcastsSubscription', () => {
    type Listener = (payload: unknown) => void;

    function fakeEcho() {
        const listeners = new Map<string, Map<string, Listener>>();
        const stateHandlers = new Set<(change: { current: string }) => void>();

        const echo = {
            private: vi.fn((name: string) => {
                const channel = new Map<string, Listener>();
                listeners.set(name, channel);

                return {
                    listen: (event: string, handler: Listener) =>
                        channel.set(event, handler),
                    stopListening: (event: string) => channel.delete(event),
                };
            }),
            leaveChannel: vi.fn(),
            connector: {
                pusher: {
                    connection: {
                        bind: (_: string, handler: never) =>
                            stateHandlers.add(handler),
                        unbind: (_: string, handler: never) =>
                            stateHandlers.delete(handler),
                    },
                },
            },
        };

        return {
            echo,
            fire(channel: string, event: string, payload: unknown) {
                act(() => listeners.get(channel)?.get(event)?.(payload));
            },
            listenerCount(channel: string) {
                return listeners.get(channel)?.size ?? 0;
            },
            changeState(current: string) {
                act(() => stateHandlers.forEach((h) => h({ current })));
            },
        };
    }

    function mountWith(
        fake: ReturnType<typeof fakeEcho>,
        teamId: number | null,
    ) {
        vi.mocked(usePage).mockReturnValue({
            props: {
                currentTeam: teamId === null ? null : { id: teamId },
                auth: { user: { id: 42 } },
            },
        } as unknown as ReturnType<typeof usePage>);
        vi.mocked(useEcho).mockReturnValue(
            fake.echo as unknown as ReturnType<typeof useEcho>,
        );

        return renderHook(() => useTeamBroadcastsSubscription());
    }

    it('escucha sólo el canal privado del equipo y del usuario actuales', () => {
        const fake = fakeEcho();

        mountWith(fake, 5);

        expect(fake.echo.private.mock.calls.map(([name]) => name)).toEqual([
            'accounts.5',
            'users.42',
        ]);
    });

    it('reenvía cada evento del socket como evento de ventana', () => {
        const fake = fakeEcho();
        const received = vi.fn();
        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, (event) =>
            received((event as CustomEvent).detail),
        );

        mountWith(fake, 5);
        fake.fire('accounts.5', '.incidents.created', INCIDENT);

        expect(received).toHaveBeenCalledWith({
            event: 'incidents.created',
            payload: INCIDENT,
        });
    });

    it('sin equipo no se suscribe al canal de cuentas', () => {
        const fake = fakeEcho();

        mountWith(fake, null);

        expect(fake.echo.private).not.toHaveBeenCalledWith('accounts.null');
        expect(fake.echo.private.mock.calls.map(([name]) => name)).toEqual([
            'users.42',
        ]);
    });

    it('al desmontar deja de escuchar y abandona los canales', () => {
        const fake = fakeEcho();
        const { unmount } = mountWith(fake, 5);

        unmount();

        expect(fake.listenerCount('accounts.5')).toBe(0);
        expect(fake.echo.leaveChannel).toHaveBeenCalledWith(
            'private-accounts.5',
        );
        expect(fake.echo.leaveChannel).toHaveBeenCalledWith('private-users.42');
    });

    it('pide una resincronización sólo al volver de una caída', () => {
        const fake = fakeEcho();
        const resync = vi.fn();
        window.addEventListener(REALTIME_RESYNC_EVENT_NAME, resync);

        mountWith(fake, 5);

        fake.changeState('connected');
        expect(resync).not.toHaveBeenCalled();

        fake.changeState('unavailable');
        fake.changeState('connecting');
        fake.changeState('connected');
        fake.changeState('connected');

        expect(resync).toHaveBeenCalledTimes(1);

        window.removeEventListener(REALTIME_RESYNC_EVENT_NAME, resync);
    });
});
