import { useEffect, useState } from 'react';
import { createEcho } from '@/echo';

type ChannelKind = 'public' | 'private' | 'presence';

type Options = {
    kind?: ChannelKind;
};

export function useEchoChannel<T>(
    channel: string | null | undefined,
    event: string,
    { kind = 'private' }: Options = {},
): T | null {
    const [payload, setPayload] = useState<T | null>(null);

    useEffect(() => {
        if (!channel) {
            return;
        }

        const echo = createEcho();

        if (!echo) {
            return;
        }

        const subscription =
            kind === 'public'
                ? echo.channel(channel)
                : kind === 'presence'
                  ? echo.join(channel)
                  : echo.private(channel);

        const handler = (data: T) => {
            setPayload(data);
        };

        subscription.listen(event, handler);

        // Only detach this listener: channels are shared (the team channel
        // also feeds useTeamBroadcastsSubscription), so leaving it here would
        // silently cut every other subscriber off.
        return () => {
            subscription.stopListening(event, handler);
        };
    }, [channel, event, kind]);

    return payload;
}
