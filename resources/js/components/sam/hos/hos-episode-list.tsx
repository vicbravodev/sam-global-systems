import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/sam/status-badge';
import { formatDateTime } from '@/lib/format';
import { formatClock, relativeLabel } from '@/lib/time';
import incidentRoutes from '@/routes/incidents';
import type { HosEpisodeEntry, HosNudge } from '@/types/hos';
import { HOS_RESOLUTION, HOS_SITUATION_LABELS } from './copy';
import { deliveryLines, nudgeTitle, nudgesLabel } from './lib';

export interface HosEpisodeListProps {
    episodes: HosEpisodeEntry[];
    teamSlug: string | null;
    empty: string;
}

/** Situaciones HOS con los avisos que SAM mandó y su resultado. */
export function HosEpisodeList({
    episodes,
    teamSlug,
    empty,
}: HosEpisodeListProps) {
    if (episodes.length === 0) {
        return (
            <p className="px-4 py-6 text-center text-xs text-fg-3">{empty}</p>
        );
    }

    return (
        <ul className="flex flex-col divide-y divide-border">
            {episodes.map((episode) => (
                <EpisodeItem
                    key={episode.id}
                    episode={episode}
                    teamSlug={teamSlug}
                />
            ))}
        </ul>
    );
}

function EpisodeItem({
    episode,
    teamSlug,
}: {
    episode: HosEpisodeEntry;
    teamSlug: string | null;
}) {
    const resolution =
        episode.resolution === null
            ? null
            : (HOS_RESOLUTION[episode.resolution] ?? null);

    return (
        <li className="flex flex-col gap-2 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-semibold text-fg-1">
                    {HOS_SITUATION_LABELS[episode.situation]}
                </span>
                <div className="flex flex-wrap items-center gap-1.5">
                    {episode.escalatedAt ? (
                        <StatusBadge
                            size="sm"
                            tone="high"
                            label="Escalado a tu equipo"
                        />
                    ) : null}
                    {resolution ? (
                        <StatusBadge size="sm" dot {...resolution} />
                    ) : episode.resolvedAt === null ? (
                        <StatusBadge
                            size="sm"
                            dot
                            pulse
                            tone="warn"
                            label="Abierto"
                        />
                    ) : null}
                </div>
            </div>
            <p className="text-xs text-fg-3">
                Desde {formatDateTime(episode.openedAt)}
                {episode.resolvedAt
                    ? ` · cerró ${relativeLabel(episode.resolvedAt)}`
                    : ''}
                {` · ${nudgesLabel(episode.nudges.length)}`}
                {episode.incident && teamSlug !== null ? (
                    <>
                        {' · '}
                        <Link
                            href={incidentRoutes.show.url([
                                teamSlug,
                                episode.incident.id,
                            ])}
                            className="text-primary hover:underline"
                        >
                            Incidente {episode.incident.reference}
                        </Link>
                    </>
                ) : null}
            </p>
            {episode.nudges.length > 0 ? (
                <ol
                    className="flex flex-col gap-1"
                    aria-label="Avisos enviados"
                >
                    {episode.nudges.map((nudge) => (
                        <NudgeItem key={nudge.id} nudge={nudge} />
                    ))}
                </ol>
            ) : null}
        </li>
    );
}

function NudgeItem({ nudge }: { nudge: HosNudge }) {
    const lines = deliveryLines(nudge);

    return (
        <li className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <span className="text-fg-3 tabular-nums">
                {formatClock(nudge.createdAt)}
            </span>
            <span className="text-fg-2">{nudgeTitle(nudge)}</span>
            {lines.length === 0 ? (
                <span className="text-fg-3">Sin entregas registradas</span>
            ) : (
                lines.map((line) => (
                    <StatusBadge
                        key={line.key}
                        size="sm"
                        tone={line.tone}
                        label={line.text}
                    />
                ))
            )}
        </li>
    );
}
