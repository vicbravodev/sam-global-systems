import { Link, usePage } from '@inertiajs/react';
import { Inbox, LayoutGrid, Plug, Settings, Shield } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as adminTenantsIndex } from '@/routes/admin/tenants';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const currentTeam = page.props.currentTeam;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : '/';
    const teamSlug = currentTeam?.slug ?? '';
    const isSuperAdmin = page.props.auth?.user?.global_role === 'super_admin';

    // Only functional destinations live here. The richer operational nav lives
    // in OpsSidebar (the tenant workspace shell); modules without a page yet are
    // intentionally omitted instead of pointing at a dead dashboard link.
    const mainNavItems: NavItem[] = [
        { title: 'Panel', href: dashboardUrl, icon: LayoutGrid },
        ...(teamSlug
            ? [
                  {
                      title: 'Bandeja',
                      href: `/${teamSlug}/incidents`,
                      icon: Inbox,
                  },
                  {
                      title: 'Integraciones',
                      href: `/${teamSlug}/integrations`,
                      icon: Plug,
                  },
              ]
            : []),
    ];

    const footerNavItems: NavItem[] = [
        ...(isSuperAdmin
            ? [
                  {
                      title: 'Super Admin',
                      href: adminTenantsIndex().url,
                      icon: Shield,
                  },
              ]
            : []),
        { title: 'Configuración', href: '/settings/profile', icon: Settings },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavMain items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
