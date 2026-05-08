import { useTeamBroadcastsSubscription } from '@/hooks/use-team-broadcasts';

export function RealtimeBootstrap() {
    useTeamBroadcastsSubscription();

    return null;
}
