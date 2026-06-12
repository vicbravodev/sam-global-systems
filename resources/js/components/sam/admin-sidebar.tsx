import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    FileClock,
    LogOut,
    Radio,
    Shield,
    UsersRound,
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

interface NavItemConfig {
    label: string;
    icon: React.ElementType;
    href: string;
    badge?: number;
    pulseWhenInactive?: boolean;
}

interface NavGroup {
    title: string;
    items: NavItemConfig[];
}

function NavItemButton({
    item,
    collapsed,
    isActive,
}: {
    item: NavItemConfig;
    collapsed: boolean;
    isActive: boolean;
}) {
    const Icon = item.icon;
    const pulse = item.pulseWhenInactive && !isActive;

    const link = (
        <Link
            href={item.href}
            prefetch
            className={cn(
                'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-[7px]',
                'text-sm font-medium text-fg-2',
                'hover:bg-sidebar-accent hover:text-fg-1',
                'transition-colors duration-100',
                isActive &&
                    'bg-primary/20 text-fg-1 shadow-[inset_2px_0_0_theme(colors.primary)]',
                collapsed && 'justify-center px-0',
            )}
        >
            <Icon className="size-4 shrink-0" />
            {!collapsed && (
                <>
                    <span className="flex-1 truncate text-left">
                        {item.label}
                    </span>
                    {item.badge !== undefined && item.badge > 0 && (
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 font-mono text-3xs font-semibold',
                                isActive
                                    ? 'bg-primary text-primary-foreground'
                                    : pulse
                                      ? 'animate-[sam-badge-pulse_2s_ease-out_infinite] bg-severity-critical text-white'
                                      : 'bg-surface-3 text-fg-2',
                            )}
                        >
                            {item.badge}
                        </span>
                    )}
                </>
            )}
        </Link>
    );

    if (collapsed) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{link}</TooltipTrigger>
                <TooltipContent side="right">{item.label}</TooltipContent>
            </Tooltip>
        );
    }

    return link;
}

export function AdminSidebar() {
    const [collapsed, setCollapsed] = useState(false);
    const page = usePage();
    const currentUrl = page.url;
    const badges = page.props.adminBadges;
    const currentTeam = page.props.currentTeam;
    const backToAppHref = currentTeam ? dashboard(currentTeam.slug).url : '/';

    const navGroups: NavGroup[] = [
        {
            title: 'Consola SaaS',
            items: [
                {
                    label: 'Tenants',
                    icon: Building2,
                    href: adminTenantsIndex().url,
                    badge:
                        (badges?.tenantsPastDue ?? 0) +
                        (badges?.tenantsTrialing ?? 0),
                    pulseWhenInactive: (badges?.tenantsPastDue ?? 0) > 0,
                },
                {
                    label: 'Planes',
                    icon: CreditCard,
                    href: '/admin/plans',
                },
                {
                    label: 'Operadores',
                    icon: UsersRound,
                    href: '/admin/operators',
                },
                {
                    label: 'Canales',
                    icon: Radio,
                    href: '/admin/channels',
                },
                {
                    label: 'Auditoría',
                    icon: FileClock,
                    href: '/admin/audit',
                },
            ],
        },
    ];

    const isActive = (href: string) => currentUrl.startsWith(href);

    return (
        <aside
            className={cn(
                'flex shrink-0 flex-col overflow-hidden border-r border-sidebar-border bg-sidebar',
                'transition-[width] duration-200 ease-out',
                collapsed ? 'w-[58px]' : 'w-[232px]',
            )}
        >
            {/* Operator block */}
            <div className="flex min-h-14 items-center gap-2.5 border-b border-sidebar-border px-3 py-3">
                <div className="grid size-8 shrink-0 place-items-center rounded-md bg-primary">
                    <Shield className="size-4 text-white" />
                </div>
                {!collapsed && (
                    <>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold text-fg-1">
                                SAM · Operador
                            </div>
                            <div className="mt-0.5 font-mono text-2xs text-fg-3">
                                consola saas
                            </div>
                        </div>
                        <button
                            type="button"
                            className="relative grid h-[30px] w-[30px] shrink-0 cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 hover:bg-sidebar-accent hover:text-fg-1"
                            onClick={() => setCollapsed(true)}
                            aria-label="Colapsar sidebar"
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                    </>
                )}
            </div>

            {/* Nav */}
            <nav className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto p-2">
                {navGroups.map((group) => (
                    <div key={group.title}>
                        {!collapsed && (
                            <div className="px-2.5 pt-4 pb-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                {group.title}
                            </div>
                        )}
                        {group.items.map((item) => (
                            <NavItemButton
                                key={item.label}
                                item={item}
                                collapsed={collapsed}
                                isActive={isActive(item.href)}
                            />
                        ))}
                    </div>
                ))}
            </nav>

            {/* Footer: back to tenant app */}
            <div className="border-t border-sidebar-border p-2">
                <NavItemButton
                    item={{
                        label: 'Salir a la app',
                        icon: LogOut,
                        href: backToAppHref,
                    }}
                    collapsed={collapsed}
                    isActive={false}
                />
                {collapsed && (
                    <div className="mt-1 flex justify-center">
                        <button
                            type="button"
                            className="relative grid h-[30px] w-[30px] cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 hover:bg-sidebar-accent hover:text-fg-1"
                            onClick={() => setCollapsed(false)}
                            aria-label="Expandir sidebar"
                        >
                            <ChevronRight className="size-4" />
                        </button>
                    </div>
                )}
            </div>
        </aside>
    );
}
