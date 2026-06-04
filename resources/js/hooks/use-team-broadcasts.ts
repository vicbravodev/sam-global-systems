import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { createEcho } from '@/echo';
import type {
    TeamBroadcastEvent,
    TeamBroadcastEventMap,
} from '@/types/realtime';

const TEAM_EVENTS: TeamBroadcastEvent[] = [
    'asset.location_updated',
    'asset.status_changed',
    'usage.updated',
    'ai.evaluation_completed',
    'decisions.decision_made',
    'action.executed',
    'incidents.created',
];

export type TeamBroadcastDetail<
    E extends TeamBroadcastEvent = TeamBroadcastEvent,
> = {
    event: E;
    payload: TeamBroadcastEventMap[E];
};

export const TEAM_BROADCAST_EVENT_NAME = 'sam:team-broadcast';

export function useTeamBroadcastsSubscription(): void {
    const currentTeam = usePage().props.currentTeam;
    const teamId = currentTeam?.id ?? null;

    useEffect(() => {
        if (teamId === null) {
            return;
        }

        const echo = createEcho();

        if (!echo) {
            return;
        }

        const channelName = `accounts.${teamId}`;
        const channel = echo.private(channelName);

        const handlers = TEAM_EVENTS.map((event) => {
            const handler = (payload: TeamBroadcastEventMap[typeof event]) => {
                window.dispatchEvent(
                    new CustomEvent<TeamBroadcastDetail>(
                        TEAM_BROADCAST_EVENT_NAME,
                        {
                            detail: { event, payload },
                        },
                    ),
                );
            };

            channel.listen(`.${event}`, handler);

            return { event, handler };
        });

        return () => {
            for (const { event, handler } of handlers) {
                channel.stopListening(`.${event}`, handler);
            }

            echo.leaveChannel(`private-${channelName}`);
        };
    }, [teamId]);
}
