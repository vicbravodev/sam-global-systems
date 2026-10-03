import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useEcho } from '@/echo';
import type {
    TeamBroadcastEvent,
    TeamBroadcastEventMap,
    UserBroadcastEvent,
    UserBroadcastEventMap,
} from '@/types/realtime';

/**
 * One socket subscription per tab, fanned out as a window event.
 *
 * The layout mounts {@link useTeamBroadcastsSubscription} once (private
 * `accounts.{teamId}` + `users.{userId}`), and pages react through
 * {@link useTeamBroadcast} / {@link useBroadcastReload}. Pages never open
 * channels themselves, so navigating between them never re-authorises or
 * resubscribes.
 */
const TEAM_EVENTS: TeamBroadcastEvent[] = [
    'asset.location_updated',
    'fleet.positions_updated',
    'fleet.telemetry_updated',
    'asset.status_changed',
    'asset.monitoring_changed',
    'usage.updated',
    'ai.evaluation_completed',
    'decisions.decision_made',
    'action.executed',
    'incidents.created',
    'incidents.updated',
    'integration.status_changed',
    'report.ready',
];

const USER_EVENTS: UserBroadcastEvent[] = ['notification.pushed'];

type AnyBroadcastMap = TeamBroadcastEventMap & UserBroadcastEventMap;
type AnyBroadcastEvent = keyof AnyBroadcastMap;

/**
 * Discriminated by `event`: checking `detail.event` narrows `payload` to
 * that event's shape, no casts needed.
 */
export type TeamBroadcastDetail<
    E extends AnyBroadcastEvent = AnyBroadcastEvent,
> = {
    [K in E]: { event: K; payload: AnyBroadcastMap[K] };
}[E];

export const TEAM_BROADCAST_EVENT_NAME = 'sam:team-broadcast';

/**
 * Fired after the socket comes back from a drop: whatever was broadcast while
 * it was down is lost, so reload-driven views refresh once.
 */
export const REALTIME_RESYNC_EVENT_NAME = 'sam:realtime-resync';

function emit(detail: TeamBroadcastDetail): void {
    window.dispatchEvent(
        new CustomEvent<TeamBroadcastDetail>(TEAM_BROADCAST_EVENT_NAME, {
            detail,
        }),
    );
}

export function useTeamBroadcastsSubscription(): void {
    const page = usePage();
    const teamId = page.props.currentTeam?.id ?? null;
    const userId = page.props.auth?.user?.id ?? null;
    // Null until the realtime client finishes loading; the effects below
    // subscribe as soon as it is there.
    const echo = useEcho();

    useEffect(() => {
        if (teamId === null) {
            return;
        }

        if (!echo) {
            return;
        }

        const channelName = `accounts.${teamId}`;
        const channel = echo.private(channelName);

        const handlers = TEAM_EVENTS.map((event) => {
            // The socket is the untyped boundary: the event name pairs the
            // payload with its shape.
            const handler = (payload: TeamBroadcastEventMap[typeof event]) =>
                emit({ event, payload } as TeamBroadcastDetail);

            channel.listen(`.${event}`, handler);

            return { event, handler };
        });

        return () => {
            for (const { event, handler } of handlers) {
                channel.stopListening(`.${event}`, handler);
            }

            echo.leaveChannel(`private-${channelName}`);
        };
    }, [echo, teamId]);

    useEffect(() => {
        if (userId === null) {
            return;
        }

        if (!echo) {
            return;
        }

        const channelName = `users.${userId}`;
        const channel = echo.private(channelName);

        const handlers = USER_EVENTS.map((event) => {
            const handler = (payload: UserBroadcastEventMap[typeof event]) =>
                emit({ event, payload });

            channel.listen(`.${event}`, handler);

            return { event, handler };
        });

        return () => {
            for (const { event, handler } of handlers) {
                channel.stopListening(`.${event}`, handler);
            }

            echo.leaveChannel(`private-${channelName}`);
        };
    }, [echo, userId]);

    // Missed-message recovery: Pusher does not replay what was sent while the
    // connection was down.
    useEffect(() => {
        if (!echo) {
            return;
        }

        const connection = echo.connector.pusher.connection;
        let dropped = false;

        const onStateChange = ({ current }: { current: string }) => {
            if (current === 'connected') {
                if (dropped) {
                    dropped = false;
                    window.dispatchEvent(new Event(REALTIME_RESYNC_EVENT_NAME));
                }

                return;
            }

            if (current === 'unavailable' || current === 'disconnected') {
                dropped = true;
            }
        };

        connection.bind('state_change', onStateChange);

        return () => {
            connection.unbind('state_change', onStateChange);
        };
    }, [echo]);
}

/**
 * Run `handler` for the given broadcast events. The handler may change on
 * every render; the listener is attached once per event list.
 */
export function useTeamBroadcast<E extends AnyBroadcastEvent>(
    events: readonly E[],
    handler: (detail: TeamBroadcastDetail<E>) => void,
): void {
    const handlerRef = useRef(handler);
    const key = events.join('|');

    useEffect(() => {
        handlerRef.current = handler;
    });

    useEffect(() => {
        const wanted = new Set<string>(key.split('|'));

        const listener = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail && wanted.has(detail.event)) {
                handlerRef.current(detail as TeamBroadcastDetail<E>);
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, listener);

        return () =>
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, listener);
    }, [key]);
}

type ReloadRules<E extends AnyBroadcastEvent> = Partial<
    Record<
        E,
        | readonly string[]
        | ((payload: AnyBroadcastMap[E]) => readonly string[] | null)
    >
>;

/**
 * Partial Inertia reloads driven by broadcasts.
 *
 * Each event maps to the prop keys it invalidates (or a function returning
 * them, `null` to ignore that payload). Bursts are coalesced: keys accumulate
 * and one `router.reload({ only })` goes out after `debounceMs`. A hidden tab
 * does not reload; it flushes once when it becomes visible again. After a
 * socket drop every static key (plus `resync`) reloads once.
 *
 * `minIntervalMs` caps how often an expensive key reloads (e.g. aggregates
 * that a single live event barely moves): within its interval the key stays
 * pending and goes out with the first flush after it expires.
 */
export function useBroadcastReload<E extends AnyBroadcastEvent>(
    rules: ReloadRules<E>,
    {
        debounceMs = 1500,
        resync = [],
        minIntervalMs = {},
    }: {
        debounceMs?: number;
        resync?: readonly string[];
        minIntervalMs?: Readonly<Record<string, number>>;
    } = {},
): void {
    const rulesRef = useRef(rules);
    const resyncRef = useRef(resync);
    const minIntervalRef = useRef(minIntervalMs);
    const pending = useRef<Set<string>>(new Set());
    const timer = useRef<number | null>(null);
    // Props arrive fresh with the page, so each key's interval starts at mount.
    const lastReload = useRef<Map<string, number>>(new Map());

    useEffect(() => {
        rulesRef.current = rules;
        resyncRef.current = resync;
        minIntervalRef.current = minIntervalMs;
    });

    useEffect(() => {
        const mountedAt = Date.now();

        const waitFor = (key: string, now: number): number => {
            const interval = minIntervalRef.current[key] ?? 0;

            return Math.max(
                0,
                (lastReload.current.get(key) ?? mountedAt) + interval - now,
            );
        };

        const flush = () => {
            timer.current = null;

            if (document.hidden || pending.current.size === 0) {
                return;
            }

            const now = Date.now();
            const only = [...pending.current].filter(
                (key) => waitFor(key, now) === 0,
            );

            only.forEach((key) => {
                pending.current.delete(key);
                lastReload.current.set(key, now);
            });

            if (only.length > 0) {
                router.reload({ only });
            }

            if (pending.current.size > 0) {
                const next = Math.min(
                    ...[...pending.current].map((key) => waitFor(key, now)),
                );
                timer.current = window.setTimeout(
                    flush,
                    Math.max(next, debounceMs),
                );
            }
        };

        const schedule = (keys: readonly string[]) => {
            keys.forEach((key) => pending.current.add(key));

            if (timer.current === null) {
                timer.current = window.setTimeout(flush, debounceMs);
            }
        };

        const onBroadcast = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;
            const rule = detail
                ? rulesRef.current[detail.event as E]
                : undefined;

            if (!rule) {
                return;
            }

            const keys: readonly string[] | null =
                typeof rule === 'function'
                    ? (
                          rule as (
                              payload: AnyBroadcastMap[E],
                          ) => readonly string[] | null
                      )(detail.payload as AnyBroadcastMap[E])
                    : (rule as readonly string[]);

            if (keys && keys.length > 0) {
                schedule(keys);
            }
        };

        const onResync = () => {
            const all = Object.values(rulesRef.current).flatMap((rule) =>
                typeof rule === 'function' ? [] : (rule as readonly string[]),
            );

            schedule([...all, ...resyncRef.current]);
        };

        const onVisible = () => {
            if (!document.hidden && pending.current.size > 0) {
                flush();
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, onBroadcast);
        window.addEventListener(REALTIME_RESYNC_EVENT_NAME, onResync);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, onBroadcast);
            window.removeEventListener(REALTIME_RESYNC_EVENT_NAME, onResync);
            document.removeEventListener('visibilitychange', onVisible);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                timer.current = null;
            }
        };
    }, [debounceMs]);
}
