import { Panel } from '@/components/sam/panel';
import { hoursMinutesLabel, relativeLabel } from '@/lib/time';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosDriverPanelData } from '@/types/hos';
import { HosClockBars } from './hos-clock-bars';
import { HosEpisodeList } from './hos-episode-list';
import { HosStatusBadge } from './hos-status-badge';

export interface HosDriverPanelProps {
    hos: HosDriverPanelData;
    teamSlug: string | null;
}

/** Pestaña HOS del detalle del chofer. */
export function HosDriverPanel({ hos, teamSlug }: HosDriverPanelProps) {
    const { state } = hos;

    return (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div className="flex min-w-0 flex-col gap-4">
                <Panel
                    title="Horas de servicio"
                    description={
                        state
                            ? `Lectura de Samsara ${relativeLabel(state.observedAt)}${state.asset ? ` · ${state.asset.name}` : ''}`
                            : 'Este chofer aún no entra al monitoreo HOS.'
                    }
                    action={
                        state ? (
                            <HosStatusBadge
                                dutyStatus={state.dutyStatus}
                                appDisconnected={
                                    state.appDisconnectedSince !== null
                                }
                            />
                        ) : null
                    }
                    bodyClassName="gap-3 p-4"
                >
                    {state ? (
                        <>
                            <HosClockBars
                                clocks={state.clocks}
                                dutyStatus={state.dutyStatus}
                            />
                            {state.violationSeconds > 0 ? (
                                <p
                                    className={cn(
                                        'text-xs font-medium',
                                        TONE_TEXT.critical,
                                    )}
                                >
                                    Samsara marca{' '}
                                    {hoursMinutesLabel(state.violationSeconds)}{' '}
                                    en infracción.
                                </p>
                            ) : null}
                            {state.appDisconnectedSince !== null ? (
                                <p className={cn('text-xs', TONE_TEXT.warn)}>
                                    La app de Samsara del chofer está
                                    desconectada{' '}
                                    {relativeLabel(
                                        state.appDisconnectedSince,
                                        'long',
                                    )}
                                    : sus relojes pueden no reflejar lo que está
                                    haciendo.
                                </p>
                            ) : null}
                            {state.stale ? (
                                <p className={cn('text-xs', TONE_TEXT.warn)}>
                                    Sin lectura reciente de Samsara: los relojes
                                    pueden estar atrasados.
                                </p>
                            ) : null}
                        </>
                    ) : (
                        <p className="text-xs text-fg-3">
                            Entra cuando maneja un tracto vigilado que cumple la
                            configuración de HOS.
                        </p>
                    )}
                </Panel>
                <Panel
                    title="Lo que está pasando"
                    description="Situaciones abiertas y los avisos que SAM ya le mandó."
                >
                    <HosEpisodeList
                        episodes={hos.openEpisodes}
                        teamSlug={teamSlug}
                        empty="Nada abierto: va en regla."
                    />
                </Panel>
            </div>
            <div className="flex min-w-0 flex-col gap-4">
                <Panel
                    title="Historial"
                    description="Situaciones cerradas, de la más reciente a la más antigua."
                >
                    <HosEpisodeList
                        episodes={hos.history}
                        teamSlug={teamSlug}
                        empty="Sin situaciones anteriores."
                    />
                </Panel>
            </div>
        </div>
    );
}
