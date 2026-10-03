import { Link } from '@inertiajs/react';
import { DEMO_HREF } from '@/components/landing/copy';
import { PrimaryButton } from '@/components/landing/primary-button';
import { login } from '@/routes';
import type { dashboard } from '@/routes';

const NAV_LINKS = [
    { href: '#copiloto', label: 'Copiloto' },
    { href: '#guardia', label: 'Guardia nocturna' },
    { href: '#equipo', label: 'Tu equipo' },
    { href: '#camaras', label: 'Cámaras' },
    { href: '#avisos', label: 'Avisos' },
];

export interface LandingHeaderProps {
    authed: boolean;
    dashboardUrl: ReturnType<typeof dashboard> | string;
}

export function LandingHeader({ authed, dashboardUrl }: LandingHeaderProps) {
    return (
        <header className="sticky top-0 z-40 border-b border-brand-line/70 bg-brand-paper/80 backdrop-blur-md">
            <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-8">
                <a
                    href="#copiloto"
                    className="flex items-center gap-2.5"
                    aria-label="SAM, inicio"
                >
                    <img
                        src="/images/brand/sam-emblem.png"
                        alt=""
                        className="size-8"
                    />
                    <span className="text-lg font-semibold tracking-tight">
                        SAM
                    </span>
                </a>
                <nav className="hidden items-center gap-8 lg:flex">
                    {NAV_LINKS.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            className="text-base text-brand-ink-2 transition-colors hover:text-brand-ink"
                        >
                            {link.label}
                        </a>
                    ))}
                </nav>
                <div className="flex items-center gap-5">
                    {authed ? (
                        <PrimaryButton href={dashboardUrl} inertia>
                            Ir al panel
                        </PrimaryButton>
                    ) : (
                        <>
                            <Link
                                href={login()}
                                className="hidden text-base text-brand-ink-2 hover:text-brand-ink sm:inline"
                            >
                                Entrar
                            </Link>
                            <PrimaryButton href={DEMO_HREF}>
                                Pedir una demo
                            </PrimaryButton>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
