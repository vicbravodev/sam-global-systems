import type { Auth } from '@/types/auth';
import type { NavBadges, NavPermissions } from '@/types/sam';
import type { AdminBadges, Impersonation, Team } from '@/types/teams';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            impersonation: Impersonation | null;
            adminBadges: AdminBadges | null;
            navBadges: NavBadges | null;
            nav: NavPermissions | null;
            copilot: { enabled: boolean; canViewUsage: boolean } | null;
            tenantSetup: {
                ready: boolean;
                phone: boolean;
                email: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}
