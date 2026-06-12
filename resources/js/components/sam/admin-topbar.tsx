import { usePage } from '@inertiajs/react';
import { ChevronRight, Menu, Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

interface AdminTopbarProps {
    breadcrumbs?: BreadcrumbItem[];
    onOpenMobileNav?: () => void;
}

/**
 * Slim topbar for the cross-tenant super-admin console. Unlike OpsTopbar it has
 * no command palette, search or realtime status — the operator console is not
 * scoped to a single tenant's live operations.
 */
export function AdminTopbar({
    breadcrumbs = [],
    onOpenMobileNav,
}: AdminTopbarProps) {
    const page = usePage();
    const user = page.props.auth.user;
    const { appearance, updateAppearance } = useAppearance();
    const isDark = appearance === 'dark';
    const getInitials = useInitials();
    const userInitials = getInitials(user.name);

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

            {breadcrumbs.length > 0 && (
                <nav
                    className="flex items-center gap-1.5"
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

            <div className="flex-1" />

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
                <div className="min-w-0">
                    <div className="max-w-[120px] truncate text-[12px] font-semibold text-fg-1">
                        {user.name}
                    </div>
                    <div className="mt-0.5 font-mono text-[10px] text-fg-3">
                        Operador SAM
                    </div>
                </div>
            </div>
        </header>
    );
}
