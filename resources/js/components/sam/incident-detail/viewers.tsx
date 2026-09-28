import { usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useEffect, useState } from 'react';
import { UserAvatar } from '@/components/sam/incident-detail/user-avatar';
import { createEcho } from '@/echo';

type Viewer = { id: number; name: string };

const MAX_AVATARS = 3;

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * Who else has this incident open right now, over the presence channel
 * `incidents.{id}` (authorised to members of the incident's team). Lets two
 * operators see each other before both start working the same case.
 */
function useIncidentViewers(incidentId: number): Viewer[] {
    const [viewers, setViewers] = useState<Viewer[]>([]);

    useEffect(() => {
        const echo = createEcho();

        if (!echo) {
            return;
        }

        const name = `incidents.${incidentId}`;

        echo.join(name)
            .here((members: Viewer[]) => setViewers(members))
            .joining((member: Viewer) =>
                setViewers((current) =>
                    current.some((v) => v.id === member.id)
                        ? current
                        : [...current, member],
                ),
            )
            .leaving((member: Viewer) =>
                setViewers((current) =>
                    current.filter((v) => v.id !== member.id),
                ),
            );

        return () => {
            echo.leave(name);
            setViewers([]);
        };
    }, [incidentId]);

    return viewers;
}

export function IncidentViewers({ incidentId }: { incidentId: number }) {
    const me =
        (usePage().props.auth as { user?: { id?: number } | null } | undefined)
            ?.user?.id ?? null;
    const others = useIncidentViewers(incidentId).filter((v) => v.id !== me);

    if (others.length === 0) {
        return null;
    }

    const names = others.map((v) => v.name).join(', ');
    const extra = others.length - MAX_AVATARS;

    return (
        <div
            className="flex h-7 items-center gap-1.5 rounded-full border border-border bg-surface-2 py-0.5 pr-2.5 pl-1"
            title={`También viendo: ${names}`}
            aria-label={`También viendo: ${names}`}
        >
            <span className="flex -space-x-1.5">
                {others.slice(0, MAX_AVATARS).map((viewer) => (
                    <span
                        key={viewer.id}
                        className="rounded-full ring-2 ring-surface-2"
                    >
                        <UserAvatar
                            initials={initials(viewer.name)}
                            size={20}
                        />
                    </span>
                ))}
            </span>
            <Eye className="size-3 text-fg-3" aria-hidden="true" />
            <span className="text-2xs font-medium text-fg-2 tabular-nums">
                {extra > 0 ? `+${extra}` : others.length}
            </span>
        </div>
    );
}
