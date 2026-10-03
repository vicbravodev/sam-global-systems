import { router, usePage } from '@inertiajs/react';
import { Siren, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTeamBroadcast } from '@/hooks/use-team-broadcasts';
import incidentRoutes from '@/routes/incidents';

type ActiveAlert = {
    id: number;
    title: string;
};

const TERMINAL_STATUSES = ['resolved', 'closed', 'false_positive', 'cancelled'];

const ORIGINAL_TITLE_KEY = '__samOriginalTitle';

/**
 * Alerta de pánico/crítico que no se puede ignorar (decisión 2026-09-28):
 * banner fijo arriba de todo, sonido repetido y notificación del navegador
 * aunque la pestaña esté en segundo plano. Sigue sonando hasta que alguien la
 * atiende (abre el incidente) o la silencia; desaparece sola si el incidente
 * se cierra. Sólo para quien puede ver la bandeja de incidentes.
 */
export function CriticalIncidentAlert() {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const canSeeIncidents = page.props.nav?.incidents ?? false;
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const audioRef = useRef<AudioContext | null>(null);

    useTeamBroadcast(['incidents.created'], ({ payload }) => {
        if (!canSeeIncidents || payload.priority !== 'critical') {
            return;
        }

        setAlerts((current) =>
            current.some((alert) => alert.id === payload.incident_id)
                ? current
                : [
                      ...current,
                      { id: payload.incident_id, title: payload.title },
                  ],
        );

        notifyBrowser(payload.incident_id, payload.title, teamSlug);
    });

    useTeamBroadcast(['incidents.updated'], ({ payload }) => {
        if (TERMINAL_STATUSES.includes(payload.status)) {
            setAlerts((current) =>
                current.filter((alert) => alert.id !== payload.incident_id),
            );
        }
    });

    const beep = useCallback(() => {
        try {
            const ctx =
                audioRef.current ??
                new (
                    window.AudioContext ||
                    (
                        window as unknown as {
                            webkitAudioContext: typeof AudioContext;
                        }
                    ).webkitAudioContext
                )();
            audioRef.current = ctx;

            [0, 0.25].forEach((offset) => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.type = 'square';
                oscillator.frequency.value = 880;
                gain.gain.value = 0.08;
                oscillator.connect(gain).connect(ctx.destination);
                oscillator.start(ctx.currentTime + offset);
                oscillator.stop(ctx.currentTime + offset + 0.15);
            });
        } catch {
            // Sin audio disponible (política del navegador): queda el banner.
        }
    }, []);

    useEffect(() => {
        if (alerts.length === 0) {
            restoreTitle();

            return;
        }

        beep();
        const sound = window.setInterval(beep, 2500);
        let flash = false;
        const title = window.setInterval(() => {
            flash = !flash;
            setFlashingTitle(flash ? `🚨 EMERGENCIA (${alerts.length})` : null);
        }, 1000);

        return () => {
            window.clearInterval(sound);
            window.clearInterval(title);
        };
    }, [alerts.length, beep]);

    if (alerts.length === 0 || teamSlug === null) {
        return null;
    }

    const dismiss = (id: number) =>
        setAlerts((current) => current.filter((alert) => alert.id !== id));

    return (
        <div
            role="alert"
            aria-live="assertive"
            className="flex shrink-0 flex-col gap-1 bg-severity-critical px-4 py-2 text-sm font-medium text-white"
        >
            {alerts.map((alert) => (
                <div
                    key={alert.id}
                    className="flex items-center justify-between gap-3"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Siren size={16} className="shrink-0 animate-pulse" />
                        <span className="truncate">
                            Emergencia: {alert.title}
                        </span>
                    </span>
                    <span className="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                dismiss(alert.id);
                                router.visit(
                                    incidentRoutes.show([teamSlug, alert.id]),
                                );
                            }}
                            className="rounded bg-white/20 px-2.5 py-1 text-xs hover:bg-white/30"
                        >
                            Atender
                        </button>
                        <button
                            type="button"
                            aria-label="Silenciar esta alerta"
                            onClick={() => dismiss(alert.id)}
                            className="rounded p-1 hover:bg-white/20"
                        >
                            <X size={14} />
                        </button>
                    </span>
                </div>
            ))}
        </div>
    );
}

function notifyBrowser(id: number, title: string, teamSlug: string | null) {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return;
    }

    const show = () => {
        const notification = new Notification('🚨 Emergencia en tu flota', {
            body: title,
            tag: `incident-${id}`,
            requireInteraction: true,
        });

        notification.onclick = () => {
            window.focus();

            if (teamSlug !== null) {
                router.visit(incidentRoutes.show([teamSlug, id]));
            }
        };
    };

    if (Notification.permission === 'granted') {
        show();
    } else if (Notification.permission === 'default') {
        void Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                show();
            }
        });
    }
}

function setFlashingTitle(text: string | null) {
    const store = window as unknown as Record<string, string | undefined>;
    store[ORIGINAL_TITLE_KEY] ??= document.title;
    document.title = text ?? store[ORIGINAL_TITLE_KEY] ?? document.title;
}

function restoreTitle() {
    if (typeof window === 'undefined') {
        return;
    }

    const store = window as unknown as Record<string, string | undefined>;

    if (store[ORIGINAL_TITLE_KEY] !== undefined) {
        document.title = store[ORIGINAL_TITLE_KEY];
        store[ORIGINAL_TITLE_KEY] = undefined;
    }
}
