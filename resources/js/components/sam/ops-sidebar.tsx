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
    Sparkles,
    Shield,
    Truck,
    Users,
    Workflow,
    Receipt,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';
import { index as adminTenantsIndex } from '@/routes/admin/tenants';
import analyticsRoutes from '@/routes/analytics';
import assetRoutes from '@/routes/assets';
import auditRoutes from '@/routes/audit';
import automationRoutes from '@/routes/automation';
import billingRoutes from '@/routes/billing';
import copilotRoutes from '@/routes/copilot';
import driverRoutes from '@/routes/drivers';
import eventRoutes from '@/routes/events';
import incidentRoutes from '@/routes/incidents';
import integrationRoutes from '@/routes/integrations';
import notificationRoutes from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import rulesRoutes from '@/routes/rules';
import tenantConfigRoutes from '@/routes/tenant-config';
import type { NavBadges, NavPermissions } from '@/types/sam';

interface NavItemConfig {
    label: string;
    icon: React.ElementType;
    href: string;
    badge?: keyof NavBadges;
    pulseWhenInactive?: boolean;
    /** Permiso de sección requerido; sin él la entrada no se muestra. */
    can?: keyof NavPermissions;
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
                'text-sm font-medium text-fg-2',
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
                                'rounded-full px-1.5 py-0.5 font-mono text-3xs font-semibold',
                                isActive
                                    ? 'bg-primary text-primary-foreground'
                                    : pulse
                                      ? 'bg-severity-critical text-white motion-safe:animate-[sam-badge-pulse_2s_ease-out_infinite]'
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
    const copilotEnabled = Boolean(page.props.copilot?.enabled);
    const nav = page.props.nav;

    const dashboardHref = currentTeam
        ? dashboard(currentTeam.slug).url
        : home.url();

    const navGroups: NavGroup[] = useMemo(() => {
        const groups: NavGroup[] = [
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
                        can: 'incidents',
                        icon: Inbox,
                        href: incidentRoutes.index.url(teamSlug),
                        badge: 'inbox',
                        pulseWhenInactive: true,
                    },
                    {
                        label: 'Eventos',
                        can: 'events',
                        icon: History,
                        href: eventRoutes.index.url(teamSlug),
                    },
                    {
                        label: 'Mapa en vivo',
                        icon: MapPin,
                        href: assetRoutes.map.url(teamSlug),
                    },
                ],
            },
            {
                title: 'Recursos',
                items: [
                    {
                        label: 'Flota',
                        icon: Truck,
                        href: assetRoutes.index.url(teamSlug),
                    },
                    {
                        label: 'Conductores',
                        can: 'drivers',
                        icon: Users,
                        href: driverRoutes.index.url(teamSlug),
                    },
                ],
            },
            {
                title: 'Inteligencia',
                items: [
                    // Solo si el rol tiene `copilot.use` y el tenant tiene el
                    // módulo activo (feature `copilot`).
                    ...(copilotEnabled
                        ? [
                              {
                                  label: 'SAM Copilot',
                                  icon: Sparkles,
                                  href: copilotRoutes.index.url(teamSlug),
                              },
                          ]
                        : []),
                    {
                        label: 'Reglas',
                        can: 'rules',
                        icon: Workflow,
                        href: rulesRoutes.show.url(teamSlug),
                    },
                    {
                        label: 'Automatizaciones',
                        can: 'automation',
                        icon: Radar,
                        href: automationRoutes.show.url(teamSlug),
                    },
                    {
                        label: 'Analítica',
                        can: 'analytics',
                        icon: BarChart3,
                        href: analyticsRoutes.show.url(teamSlug),
                    },
                ],
            },
            {
                title: 'Configuración',
                items: [
                    {
                        label: 'Integraciones',
                        can: 'integrations',
                        icon: Plug,
                        href: integrationRoutes.index.url(teamSlug),
                    },
                    {
                        label: 'Bandeja de notificaciones',
                        can: 'notifications',
                        icon: Bell,
                        href: notificationRoutes.index.url(teamSlug),
                    },
                    {
                        label: 'Auditoría',
                        can: 'audit',
                        icon: FileClock,
                        href: auditRoutes.show.url(teamSlug),
                    },
                    {
                        label: 'Facturación',
                        can: 'billing',
                        icon: Receipt,
                        href: billingRoutes.show.url(teamSlug),
                    },
                    {
                        // C2: una sola entrada de Ajustes con sub-secciones
                        // (cuenta + tenant + equipo y roles) en vez de entradas
                        // sueltas y solapadas.
                        label: 'Ajustes',
                        icon: Settings,
                        // Sin permiso de configuración del tenant, Ajustes abre
                        // la cuenta personal (siempre accesible).
                        href: nav?.tenantConfig
                            ? tenantConfigRoutes.show.url(teamSlug)
                            : editProfile.url(),
                    },
                ],
            },
        ];

        // Cross-tenant control panel: only the SaaS operator (super-admin) sees it.
        if (isSuperAdmin) {
            groups.push({
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

        // Solo las secciones que el rol puede abrir: evita enlaces a páginas
        // que responden 403.
        return groups
            .map((group) => ({
                ...group,
                items: group.items.filter(
                    (item) =>
                        item.can === undefined || nav?.[item.can] === true,
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [dashboardHref, teamSlug, isSuperAdmin, copilotEnabled, nav]);

    // Un único ítem activo: entre todos los hrefs de nav, el candidato cuyo
    // segmento de ruta coincide (path === href o path.startsWith(href + '/'))
    // y es más largo gana. Evita que un grupo padre (p.ej. "Flota" en
    // /assets) y un hijo con prefijo compartido (p.ej. "Mapa en vivo" en
    // /assets/map) se iluminen a la vez.
    const path = currentUrl.split(/[?#]/)[0] ?? currentUrl;

    const activeHref = useMemo(() => {
        const allHrefs = navGroups.flatMap((group) =>
            group.items.map((item) => item.href),
        );

        return allHrefs
            .filter(
                (href) =>
                    href !== '#' &&
                    (path === href || path.startsWith(href + '/')),
            )
            .sort((a, b) => b.length - a.length)[0];
    }, [navGroups, path]);

    const isActive = (href: string) => href !== '#' && href === activeHref;

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
                <AppLogoIcon className="size-8" />
                {!collapsed && (
                    <>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold text-fg-1">
                                {teamName}
                            </div>
                            <div className="mt-0.5 font-mono text-2xs text-fg-3">
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
                            <div className="sam-caps px-2.5 pt-4 pb-1.5">
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
