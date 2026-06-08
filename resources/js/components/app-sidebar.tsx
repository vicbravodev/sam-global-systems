import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    FileClock,
    Inbox,
    LayoutGrid,
    Plug,
    Settings,
    Shield,
    Truck,
    Users,
    Workflow,
} from 'lucide-react';
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
    const dashboardUrl = page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug)
        : '/';
    const isSuperAdmin =
        page.props.auth?.user?.global_role === 'super_admin';

    // TODO: each non-dashboard module currently points at dashboardUrl until
    // its controllers ship (Bandeja → Incidents API, Activos → Assets API, …).
    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', href: dashboardUrl, icon: LayoutGrid },
        { title: 'Bandeja', href: dashboardUrl, icon: Inbox },
        { title: 'Activos', href: dashboardUrl, icon: Truck },
        { title: 'Conductores', href: dashboardUrl, icon: Users },
        { title: 'Reglas', href: dashboardUrl, icon: Workflow },
        { title: 'Integraciones', href: dashboardUrl, icon: Plug },
        { title: 'Analítica', href: dashboardUrl, icon: BarChart3 },
        { title: 'Auditoría', href: dashboardUrl, icon: FileClock },
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
        { title: 'Configuración', href: dashboardUrl, icon: Settings },
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
