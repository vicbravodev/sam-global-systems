import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
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
    'hos.clocks_updated',
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

export interface ReloadBufferOptions {
    /** Coalescing window: one reload this long after the first key. */
    debounceMs?: number;
    /** Per-key floor between reloads (expensive aggregates). */
    minIntervalMs?: Readonly<Record<string, number>>;
}

export interface ReloadBuffer {
    /**
     * Queue prop keys for one coalesced `router.reload({ only })`. `delayMs`
     * (default: the buffer's debounce) only ever brings the pending flush
     * forward, never pushes it back.
     */
    schedule: (keys: readonly string[], delayMs?: number) => void;
    /**
     * Reload these keys right now (e.g. after the user's own action) and
     * drop them from the pending set: whatever was queued for them is
     * covered by this fresher reload.
     */
    reloadNow: (keys: readonly string[]) => void;
}

/**
 * The coalescing core behind {@link useReloadBuffer}, outside React so the
 * timer, the pending keys and the per-key clocks live in plain closures.
 */
function createReloadBuffer() {
    let debounceMs = 1500;
    let minIntervalMs: Readonly<Record<string, number>> = {};
    let active = false;
    let mountedAt = Date.now();
    let timer: number | null = null;
    let dueAt = 0;
    const pending = new Set<string>();
    // Props arrive fresh with the page, so each key's interval starts at mount.
    const lastReload = new Map<string, number>();

    const waitFor = (key: string, now: number): number =>
        Math.max(
            0,
            (lastReload.get(key) ?? mountedAt) +
                (minIntervalMs[key] ?? 0) -
                now,
        );

    const arm = (delayMs: number) => {
        const due = Date.now() + delayMs;

        if (timer !== null) {
            if (dueAt <= due) {
                return;
            }

            window.clearTimeout(timer);
        }

        dueAt = due;
        timer = window.setTimeout(flush, delayMs);
    };

    function flush() {
        timer = null;

        // A hidden tab keeps its keys; `onVisible` flushes them.
        if (!active || document.hidden || pending.size === 0) {
            return;
        }

        const now = Date.now();
        const only = [...pending].filter((key) => waitFor(key, now) === 0);

        only.forEach((key) => {
            pending.delete(key);
            lastReload.set(key, now);
        });

        if (only.length > 0) {
            router.reload({ only });
        }

        if (pending.size > 0) {
            const next = Math.min(
                ...[...pending].map((key) => waitFor(key, now)),
            );
            arm(Math.max(next, debounceMs));
        }
    }

    const onVisible = () => {
        if (!document.hidden && pending.size > 0) {
            flush();
        }
    };

    return {
        configure(options: Required<ReloadBufferOptions>) {
            debounceMs = options.debounceMs;
            minIntervalMs = options.minIntervalMs;
        },
        schedule(keys: readonly string[], delayMs?: number) {
            if (!active || keys.length === 0) {
                return;
            }

            keys.forEach((key) => pending.add(key));
            arm(delayMs ?? debounceMs);
        },
        reloadNow(keys: readonly string[]) {
            if (keys.length === 0) {
                return;
            }

            const now = Date.now();

            keys.forEach((key) => {
                pending.delete(key);
                lastReload.set(key, now);
            });

            if (pending.size === 0 && timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }

            router.reload({ only: [...keys] });
        },
        start(): () => void {
            active = true;
            mountedAt = Date.now();
            document.addEventListener('visibilitychange', onVisible);

            return () => {
                active = false;
                document.removeEventListener('visibilitychange', onVisible);
                pending.clear();
                lastReload.clear();

                if (timer !== null) {
                    window.clearTimeout(timer);
                    timer = null;
                }
            };
        },
    };
}

/**
 * Coalesced partial reloads for a page: keys accumulate and one
 * `router.reload({ only })` goes out per window. A hidden tab does not
 * reload; it flushes once when it becomes visible again. `minIntervalMs`
 * caps how often an expensive key reloads: within its interval the key stays
 * pending and goes out with the first flush after it expires.
 *
 * {@link useBroadcastReload} is this plus the broadcast wiring; use the
 * buffer directly to feed it from a custom handler (per-event delays) or to
 * fold the page's own post-action reload into the same window.
 */
export function useReloadBuffer({
    debounceMs = 1500,
    minIntervalMs = {},
}: ReloadBufferOptions = {}): ReloadBuffer {
    const [buffer] = useState(createReloadBuffer);

    useEffect(() => {
        buffer.configure({ debounceMs, minIntervalMs });
    });

    useEffect(() => buffer.start(), [buffer]);

    return buffer;
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
 * them, `null` to ignore that payload); the keys go through a
 * {@link useReloadBuffer} (debounce, hidden tab, `minIntervalMs`). After a
 * socket drop every static key (plus `resync`) reloads once. Returns the
 * buffer, so the page can queue its own reloads in the same window.
 */
export function useBroadcastReload<E extends AnyBroadcastEvent>(
    rules: ReloadRules<E>,
    {
        debounceMs = 1500,
        resync = [],
        minIntervalMs = {},
    }: ReloadBufferOptions & { resync?: readonly string[] } = {},
): ReloadBuffer {
    const buffer = useReloadBuffer({ debounceMs, minIntervalMs });
    const rulesRef = useRef(rules);
    const resyncRef = useRef(resync);

    useEffect(() => {
        rulesRef.current = rules;
        resyncRef.current = resync;
    });

    useEffect(() => {
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

            if (keys) {
                buffer.schedule(keys);
            }
        };

        const onResync = () => {
            const all = Object.values(rulesRef.current).flatMap((rule) =>
                typeof rule === 'function' ? [] : (rule as readonly string[]),
            );

            buffer.schedule([...all, ...resyncRef.current]);
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, onBroadcast);
        window.addEventListener(REALTIME_RESYNC_EVENT_NAME, onResync);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, onBroadcast);
            window.removeEventListener(REALTIME_RESYNC_EVENT_NAME, onResync);
        };
    }, [buffer]);

    return buffer;
}
