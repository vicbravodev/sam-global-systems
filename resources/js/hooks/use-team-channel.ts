import { usePage } from '@inertiajs/react';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import type {
    TeamBroadcastEvent,
    TeamBroadcastEventMap,
} from '@/types/realtime';

export function useTeamChannel<E extends TeamBroadcastEvent>(
    event: E,
): TeamBroadcastEventMap[E] | null {
    const currentTeam = usePage().props.currentTeam;
    const channel = currentTeam ? `accounts.${currentTeam.id}` : null;

    return useEchoChannel<TeamBroadcastEventMap[E]>(channel, `.${event}`);
}
