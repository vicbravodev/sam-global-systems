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

        return () => {
            subscription.stopListening(event, handler);
            echo.leaveChannel(
                `${kind === 'public' ? '' : kind === 'presence' ? 'presence-' : 'private-'}${channel}`,
            );
        };
    }, [channel, event, kind]);

    return payload;
}
