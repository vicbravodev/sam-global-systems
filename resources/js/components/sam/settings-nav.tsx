import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

type NavGroup = {
    title: string;
    items: NavItem[];
};

/**
 * C2: sub-navegación unificada de "Ajustes". Agrupa las secciones de cuenta
 * (personales, no team-scoped) y las del tenant (team-scoped) en un solo lugar,
 * en vez de cuatro entradas sueltas y solapadas en el sidebar.
 */
export function SettingsNav() {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const page = usePage();
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const groups: NavGroup[] = [
        {
            title: 'Cuenta',
            items: [
                { title: 'Perfil', href: edit(), icon: null },
                { title: 'Seguridad', href: editSecurity(), icon: null },
                { title: 'Apariencia', href: editAppearance(), icon: null },
                {
                    title: 'Mis notificaciones',
                    href: editNotifications(),
                    icon: null,
                },
                { title: 'Equipos', href: teams(), icon: null },
            ],
        },
    ];

    // Las secciones del tenant requieren un equipo activo en contexto.
    if (teamSlug !== null) {
        groups.push({
            title: 'Tenant',
            items: [
                {
                    title: 'Configuración',
                    href: `/${teamSlug}/settings/tenant-config`,
                    icon: null,
                },
                {
                    title: 'Equipo y roles',
                    href: `/${teamSlug}/settings/roles`,
                    icon: null,
                },
            ],
        });
    }

    return (
        <nav
            className="flex flex-col gap-4"
            aria-label="Ajustes"
            data-test="settings-nav"
        >
            {groups.map((group) => (
                <div key={group.title} className="flex flex-col gap-1">
                    <span className="px-2 text-2xs tracking-caps text-fg-3 uppercase">
                        {group.title}
                    </span>
                    {group.items.map((item, index) => (
                        <Button
                            key={`${toUrl(item.href)}-${index}`}
                            size="sm"
                            variant="ghost"
                            asChild
                            className={cn('w-full justify-start', {
                                'bg-surface-2 text-fg-1': isCurrentOrParentUrl(
                                    item.href,
                                ),
                            })}
                        >
                            <Link href={item.href}>{item.title}</Link>
                        </Button>
                    ))}
                </div>
            ))}
        </nav>
    );
}
