import type Pusher from 'pusher-js';
import { createEcho } from '@/echo';
import type { PusherEcho } from '@/echo';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: PusherEcho;
    }
}

export function bootstrapEcho(): PusherEcho | null {
    const echo = createEcho();

    if (echo && typeof window !== 'undefined') {
        window.Echo = echo;
    }

    return echo;
}

bootstrapEcho();
