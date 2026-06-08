import type { Auth } from '@/types/auth';
import type { Impersonation, Team } from '@/types/teams';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            impersonation: Impersonation | null;
            [key: string]: unknown;
        };
    }
}
