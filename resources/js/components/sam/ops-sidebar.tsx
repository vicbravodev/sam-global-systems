import { router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    ChevronLeft,
    ChevronRight,
    FileClock,
    History,
    Inbox,
    LayoutGrid,
    MapPin,
    Plug,
    Radar,
    Settings,
    Shield,
    Truck,
    Users,
    Workflow,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as adminTenantsIndex } from '@/routes/admin/tenants';
import type { NavBadges } from '@/types/sam';

interface NavItemConfig {
    label: string;
    icon: React.ElementType;
    href: string;
    badge?: keyof NavBadges;
    pulseWhenInactive?: boolean;
}

interface NavGroup {
    title: string;
    items: NavItemConfig[];
}

interface OpsSidebarProps {
    navBadges: NavBadges;
    /** Render dentro del drawer móvil: ancho fluido y sin botón de colapso. */
    mobile?: boolean;
}

function NavItemButton({
    item,
    collapsed,
    isActive,
    badge,
    pulse,
}: {
    item: NavItemConfig;
    collapsed: boolean;
    isActive: boolean;
    badge?: number;
    pulse?: boolean;
}) {
    const Icon = item.icon;

    const button = (
        <button
            type="button"
            className={cn(
                'flex w-full cursor-pointer items-center gap-2.5 rounded-md border-none bg-transparent px-2.5 py-[7px]',
                'text-[13px] font-medium text-fg-2',
                'hover:bg-sidebar-accent hover:text-fg-1',
                'transition-colors duration-100',
                isActive &&
                    'bg-primary/20 text-fg-1 shadow-[inset_2px_0_0_theme(colors.primary)]',
                collapsed && 'justify-center px-0',
            )}
            onClick={() => {
                if (item.href !== '#') {
                    router.visit(item.href);
                }
            }}
        >
            <Icon className="size-4 shrink-0" />
            {!collapsed && (
                <>
                    <span className="flex-1 truncate text-left">
                        {item.label}
                    </span>
                    {badge !== undefined && badge > 0 && (
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 font-mono text-[10px] font-semibold',
                                isActive
                                    ? 'bg-primary text-primary-foreground'
                                    : pulse
                                      ? 'animate-[sam-badge-pulse_2s_ease-out_infinite] bg-severity-critical text-white'
                                      : 'bg-surface-3 text-fg-2',
                            )}
                        >
                            {badge}
                        </span>
                    )}
                </>
            )}
        </button>
    );

    if (collapsed) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{button}</TooltipTrigger>
                <TooltipContent side="right">{item.label}</TooltipContent>
            </Tooltip>
        );
    }

    return button;
}

export function OpsSidebar({ navBadges, mobile = false }: OpsSidebarProps) {
    const [collapsedState, setCollapsedState] = useState(false);
    const collapsed = mobile ? false : collapsedState;
    const page = usePage();
    const currentUrl = page.url;
    const currentTeam = page.props.currentTeam;
    const teamName = currentTeam?.name ?? 'SAM';
    const teamSlug = currentTeam?.slug ?? '';
    const isSuperAdmin = page.props.auth?.user?.global_role === 'super_admin';

    const logoInitials = teamName
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

    const dashboardHref = currentTeam ? dashboard(currentTeam.slug).url : '/';

    const navGroups: NavGroup[] = [
        {
            title: 'Operación',
            items: [
                {
                    label: 'Panel',
                    icon: LayoutGrid,
                    href: dashboardHref,
                },
                {
                    label: 'Incidentes',
                    icon: Inbox,
                    href: `/${teamSlug}/incidents`,
                    badge: 'inbox',
                    pulseWhenInactive: true,
                },
                {
                    label: 'Eventos',
                    icon: History,
                    href: `/${teamSlug}/events`,
                },
                {
                    label: 'Mapa en vivo',
                    icon: MapPin,
                    href: `/${teamSlug}/assets/map`,
                },
            ],
        },
        {
            title: 'Recursos',
            items: [
                {
                    label: 'Flota',
                    icon: Truck,
                    href: `/${teamSlug}/assets`,
                },
                {
                    label: 'Conductores',
                    icon: Users,
                    href: `/${teamSlug}/drivers`,
                },
            ],
        },
        {
            title: 'Inteligencia',
            items: [
                {
                    label: 'Reglas',
                    icon: Workflow,
                    href: `/${teamSlug}/rules`,
                },
                {
                    label: 'Automatizaciones',
                    icon: Radar,
                    href: `/${teamSlug}/automation`,
                },
                {
                    label: 'Analítica',
                    icon: BarChart3,
                    href: `/${teamSlug}/analytics`,
                },
            ],
        },
        {
            title: 'Configuración',
            items: [
                {
                    label: 'Integraciones',
                    icon: Plug,
                    href: `/${teamSlug}/integrations`,
                },
                {
                    label: 'Notificaciones',
                    icon: Bell,
                    href: `/${teamSlug}/notifications`,
                },
                {
                    label: 'Auditoría',
                    icon: FileClock,
                    href: `/${teamSlug}/audit`,
                },
                {
                    label: 'Facturación',
                    icon: Receipt,
                    href: `/${teamSlug}/billing`,
                },
                {
                    label: 'Equipo y roles',
                    icon: Users,
                    href: `/${teamSlug}/settings/roles`,
                },
                {
                    label: 'Configuración',
                    icon: Settings,
                    href: `/${teamSlug}/settings/tenant-config`,
                },
            ],
        },
    ];

    // Cross-tenant control panel: only the SaaS operator (super-admin) sees it.
    if (isSuperAdmin) {
        navGroups.push({
            title: 'Administración',
            items: [
                {
                    label: 'Super Admin',
                    icon: Shield,
                    href: adminTenantsIndex().url,
                },
            ],
        });
    }

    const isActive = (href: string) => {
        if (href === '#') {
            return false;
        }

        return currentUrl.startsWith(href);
    };

    return (
        <aside
            className={cn(
                'shrink-0 flex-col overflow-hidden border-r border-sidebar-border bg-sidebar',
                mobile
                    ? 'flex h-full w-full border-r-0'
                    : [
                          'hidden lg:flex',
                          'transition-[width] duration-200 ease-out',
                          collapsed ? 'w-[58px]' : 'w-[232px]',
                      ],
            )}
        >
            {/* Tenant block */}
            <div className="flex min-h-14 items-center gap-2.5 border-b border-sidebar-border px-3 py-3">
                <div className="grid size-8 shrink-0 place-items-center rounded-md bg-primary">
                    <span className="text-[11px] font-bold tracking-[0.05em] text-white">
                        {logoInitials}
                    </span>
                </div>
                {!collapsed && (
                    <>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-[13px] font-semibold text-fg-1">
                                {teamName}
                            </div>
                            <div className="mt-0.5 font-mono text-[11px] text-fg-3">
                                {teamSlug}
                            </div>
                        </div>
                        {!mobile && (
                            <button
                                type="button"
                                className="relative grid h-[30px] w-[30px] shrink-0 cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 hover:bg-sidebar-accent hover:text-fg-1"
                                onClick={() => setCollapsedState(true)}
                                aria-label="Colapsar sidebar"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                        )}
                    </>
                )}
            </div>

            {/* Nav */}
            <nav className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto p-2">
                {navGroups.map((group) => (
                    <div key={group.title}>
                        {!collapsed && (
                            <div className="px-2.5 pt-4 pb-1.5 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                                {group.title}
                            </div>
                        )}
                        {group.items.map((item) => {
                            const active = isActive(item.href);
                            const badgeValue = item.badge
                                ? navBadges[item.badge]
                                : undefined;
                            const pulse = item.pulseWhenInactive && !active;

                            return (
                                <NavItemButton
                                    key={item.label}
                                    item={item}
                                    collapsed={collapsed}
                                    isActive={active}
                                    badge={badgeValue}
                                    pulse={pulse}
                                />
                            );
                        })}
                    </div>
                ))}
            </nav>

            {/* Expand button when collapsed */}
            {collapsed && (
                <div className="mb-2 flex justify-center">
                    <button
                        type="button"
                        className="relative grid h-[30px] w-[30px] cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 hover:bg-sidebar-accent hover:text-fg-1"
                        onClick={() => setCollapsedState(false)}
                        aria-label="Expandir sidebar"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                </div>
            )}
        </aside>
    );
}
