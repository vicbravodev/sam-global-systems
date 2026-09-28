import { usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Bell,
    BellRing,
    Brain,
    CalendarClock,
    Lock,
    Palette,
    Siren,
    SlidersHorizontal,
    Stamp,
    Timer,
    TrendingUp,
    User,
    Users,
    UsersRound,
} from 'lucide-react';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teamsIndex } from '@/routes/teams';

/**
 * Secciones de la página de configuración de la empresa. Todas viven en la
 * misma página Inertia (`settings/tenant-config`) y se eligen con
 * `?seccion=`; el slug es español porque se ve en la barra de direcciones.
 */
export const COMPANY_SECTIONS = [
    {
        key: 'emergencias',
        title: 'Emergencias',
        description:
            'Qué hace SAM ante un botón de pánico o un evento crítico.',
        icon: Siren,
    },
    {
        key: 'ia',
        title: 'Respuesta de la IA',
        description:
            'Cuánto decide la IA por sí sola y cuándo pide revisión humana.',
        icon: Brain,
    },
    {
        key: 'avisos',
        title: 'Avisos',
        description:
            'Por qué vías y a partir de qué gravedad avisa SAM a tu equipo.',
        icon: BellRing,
    },
    {
        key: 'escalamiento',
        title: 'Escalamiento',
        description:
            'A quién más se avisa, y cuándo, si nadie atiende un incidente.',
        icon: TrendingUp,
    },
    {
        key: 'guardias',
        title: 'Guardias',
        description:
            'Quién está de turno cada día y a qué hora recibe los incidentes.',
        icon: CalendarClock,
    },
    {
        key: 'marca',
        title: 'Marca',
        description:
            'Logo, colores y firma que aparecen en correos y reportes.',
        icon: Stamp,
    },
    {
        key: 'avanzado',
        title: 'Avanzado',
        description:
            'Ajustes finos de monitoreo y el historial de cambios de la configuración.',
        icon: SlidersHorizontal,
    },
] as const;

export type CompanySectionKey = (typeof COMPANY_SECTIONS)[number]['key'];

export const DEFAULT_COMPANY_SECTION: CompanySectionKey = 'emergencias';

export function isCompanySection(
    value: string | null | undefined,
): value is CompanySectionKey {
    return COMPANY_SECTIONS.some((section) => section.key === value);
}

export interface SettingsNavItem {
    key: string;
    title: string;
    href: string;
    icon: LucideIcon;
    active: boolean;
}

export interface SettingsNavGroup {
    title: string;
    items: SettingsNavItem[];
}

/** Sección activa de la configuración de la empresa según `?seccion=`. */
export function companySectionFromUrl(url: string): CompanySectionKey {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const section = new URLSearchParams(query).get('seccion');

    return isCompanySection(section) ? section : DEFAULT_COMPANY_SECTION;
}

function pathOf(url: string): string {
    const path = url.split('?')[0].split('#')[0];

    return path.length > 1 ? path.replace(/\/$/, '') : path;
}

/**
 * Navegación única de Ajustes: "Tu cuenta" (personal) y "Tu empresa"
 * (configuración compartida del equipo activo). La consumen la barra lateral
 * de escritorio y el selector compacto de móvil.
 */
export function useSettingsNav(): SettingsNavGroup[] {
    const page = usePage();
    const currentPath = pathOf(page.url);
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;
    const nav = page.props.nav;

    const matches = (href: string, nested = false) => {
        const target = pathOf(href);

        return (
            currentPath === target ||
            (nested && currentPath.startsWith(`${target}/`))
        );
    };

    const account: SettingsNavItem[] = [
        {
            key: 'profile',
            title: 'Perfil',
            href: toUrl(editProfile()),
            icon: User,
        },
        {
            key: 'security',
            title: 'Seguridad',
            href: toUrl(editSecurity()),
            icon: Lock,
        },
        {
            key: 'appearance',
            title: 'Apariencia',
            href: toUrl(editAppearance()),
            icon: Palette,
        },
        {
            key: 'my-notifications',
            title: 'Mis avisos',
            href: toUrl(editNotifications()),
            icon: Bell,
        },
        {
            key: 'teams',
            title: 'Mis equipos',
            href: toUrl(teamsIndex()),
            icon: UsersRound,
        },
    ].map((item) => ({ ...item, active: matches(item.href, true) }));

    const groups: SettingsNavGroup[] = [{ title: 'Tu cuenta', items: account }];

    if (teamSlug === null) {
        return groups;
    }

    // Las secciones de la empresa requieren el permiso correspondiente (sin
    // él la página responde 403), así que sólo se listan si se pueden abrir.
    const company: SettingsNavItem[] = [];
    const configBase = `/${teamSlug}/settings/tenant-config`;
    const onConfigPage = currentPath === configBase;
    const activeSection = companySectionFromUrl(page.url);

    if (nav?.tenantConfig) {
        for (const section of COMPANY_SECTIONS) {
            if (section.key === 'avanzado') {
                continue;
            }

            company.push({
                key: section.key,
                title: section.title,
                href:
                    section.key === DEFAULT_COMPANY_SECTION
                        ? configBase
                        : `${configBase}?seccion=${section.key}`,
                icon: section.icon,
                active: onConfigPage && activeSection === section.key,
            });

            // Los tiempos de respuesta viven en su propia página, pero para
            // quien configura son parte del mismo flujo que el escalamiento.
            if (section.key === 'escalamiento') {
                company.push({
                    key: 'tiempos',
                    title: 'Tiempos de respuesta',
                    href: `${configBase}/slas`,
                    icon: Timer,
                    active: matches(`${configBase}/slas`),
                });
            }
        }
    }

    if (nav?.roles) {
        const rolesHref = `/${teamSlug}/settings/roles`;

        company.push({
            key: 'roles',
            title: 'Equipo y roles',
            href: rolesHref,
            icon: Users,
            active: matches(rolesHref, true),
        });
    }

    if (nav?.tenantConfig) {
        company.push({
            key: 'avanzado',
            title: 'Avanzado',
            href: `${configBase}?seccion=avanzado`,
            icon: SlidersHorizontal,
            active: onConfigPage && activeSection === 'avanzado',
        });
    }

    if (company.length > 0) {
        groups.push({ title: 'Tu empresa', items: company });
    }

    return groups;
}
