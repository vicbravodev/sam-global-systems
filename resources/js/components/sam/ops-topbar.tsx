import { usePage } from '@inertiajs/react';
import { Bell, ChevronRight, Menu, Moon, Search, Sun } from 'lucide-react';
import { useState } from 'react';
import { RealtimeStatus } from '@/components/sam/realtime-status';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { useAppearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

interface OpsTopbarProps {
    breadcrumbs?: BreadcrumbItem[];
    onOpenCommandPalette?: () => void;
    onOpenMobileNav?: () => void;
}

export function OpsTopbar({
    breadcrumbs = [],
    onOpenCommandPalette,
    onOpenMobileNav,
}: OpsTopbarProps) {
    const page = usePage();
    const user = page.props.auth.user;
    const { appearance, updateAppearance } = useAppearance();
    const isDark = appearance === 'dark';
    const [realtimeState, setRealtimeState] = useState<RealtimeState>('ok');
    const getInitials = useInitials();
    const userInitials = getInitials(user.name);

    const toggleRealtime = () => {
        setRealtimeState((prev) => (prev === 'ok' ? 'down' : 'ok'));
    };

    return (
        <header className="flex h-[52px] shrink-0 items-center gap-2.5 border-b border-border bg-background px-3 sm:px-4">
            {/* Mobile nav trigger */}
            <button
                type="button"
                className="grid h-[30px] w-[30px] shrink-0 cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 transition-colors duration-100 hover:bg-surface-2 hover:text-fg-1 lg:hidden"
                onClick={onOpenMobileNav}
                aria-label="Abrir menú de navegación"
            >
                <Menu className="size-4" />
            </button>

            {/* Breadcrumbs */}
            {breadcrumbs.length > 0 && (
                <nav
                    className="hidden items-center gap-1.5 sm:flex"
                    aria-label="Breadcrumb"
                >
                    {breadcrumbs.map((crumb, idx) => {
                        const isLast = idx === breadcrumbs.length - 1;

                        return (
                            <span
                                key={`${idx}-${crumb.title}`}
                                className="flex items-center gap-1.5"
                            >
                                {idx > 0 && (
                                    <ChevronRight
                                        className="size-3 text-fg-3"
                                        aria-hidden="true"
                                    />
                                )}
                                <span
                                    className={cn(
                                        'text-[12px] font-medium',
                                        isLast
                                            ? 'font-semibold text-fg-1'
                                            : 'text-fg-3',
                                    )}
                                >
                                    {crumb.title}
                                </span>
                            </span>
                        );
                    })}
                </nav>
            )}

            {/* Spacer */}
            <div className="flex-1" />

            {/* Search button (icon-only on small screens) */}
            <button
                type="button"
                className="grid h-[30px] w-[30px] shrink-0 cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 transition-colors duration-100 hover:bg-surface-2 hover:text-fg-1 md:hidden"
                onClick={onOpenCommandPalette}
                aria-label="Buscar"
            >
                <Search className="size-4" />
            </button>
            <button
                type="button"
                className="hidden w-[280px] cursor-pointer items-center gap-2 rounded-md border border-border bg-surface-2 px-2.5 py-1.5 transition-colors duration-100 hover:bg-surface-3 md:flex"
                onClick={onOpenCommandPalette}
                aria-label="Buscar"
            >
                <Search className="size-3.5 shrink-0 text-fg-3" />
                <span className="flex-1 text-left text-[12px] text-fg-3">
                    Buscar incidentes, activos…
                </span>
                <kbd className="rounded-sm border border-b-2 border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-fg-2">
                    ⌘K
                </kbd>
            </button>

            {/* Realtime status */}
            <button
                type="button"
                className="cursor-pointer border-none bg-transparent p-0"
                onClick={toggleRealtime}
                aria-label="Estado de conexión en tiempo real"
            >
                <RealtimeStatus state={realtimeState} />
            </button>

            {/* Notification bell */}
            <button
                type="button"
                className="relative grid h-[30px] w-[30px] cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 transition-colors duration-100 hover:bg-surface-2 hover:text-fg-1"
                aria-label="Notificaciones"
            >
                <Bell className="size-4" />
                <span className="absolute top-1 right-1.5 h-1.5 w-1.5 rounded-full border-[1.5px] border-background bg-severity-critical" />
            </button>

            {/* Theme toggle */}
            <button
                type="button"
                className="relative grid h-[30px] w-[30px] cursor-pointer place-items-center rounded-md border border-transparent bg-transparent text-fg-2 transition-colors duration-100 hover:bg-surface-2 hover:text-fg-1"
                onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
                aria-label={
                    isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'
                }
            >
                {isDark ? (
                    <Sun className="size-4" />
                ) : (
                    <Moon className="size-4" />
                )}
            </button>

            {/* User pill */}
            <div className="flex items-center gap-2 rounded-full border border-border pr-2 pl-0.5">
                <div className="grid size-[26px] shrink-0 place-items-center rounded-full bg-primary">
                    <span className="text-[11px] font-semibold text-white">
                        {userInitials}
                    </span>
                </div>
                <div className="hidden min-w-0 sm:block">
                    <div className="max-w-[100px] truncate text-[12px] font-semibold text-fg-1">
                        {user.name}
                    </div>
                    <div className="mt-0.5 font-mono text-[10px] text-fg-3">
                        Supervisor
                    </div>
                </div>
            </div>
        </header>
    );
}
