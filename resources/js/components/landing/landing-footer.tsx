import { Link } from '@inertiajs/react';
import { CONTACT_EMAIL, CONTACT_PHONE } from '@/components/landing/copy';
import { login } from '@/routes';

export function LandingFooter() {
    return (
        <footer className="bg-brand-paper">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-5 py-10 text-sm text-brand-ink-3 sm:px-8 md:flex-row md:items-center md:justify-between">
                <div className="flex items-center gap-3">
                    <img
                        src="/images/brand/sam-emblem.png"
                        alt=""
                        className="size-7"
                    />
                    <span>
                        <span className="font-semibold text-brand-ink">
                            SAM
                        </span>
                        , Sistema Automatizado de Monitoreo. Nuevo León, México.
                    </span>
                </div>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                    <a
                        href={`mailto:${CONTACT_EMAIL}`}
                        className="hover:text-brand-ink"
                    >
                        {CONTACT_EMAIL}
                    </a>
                    <a
                        href="tel:+528117658890"
                        className="hover:text-brand-ink"
                    >
                        {CONTACT_PHONE}
                    </a>
                    <Link href={login()} className="hover:text-brand-ink">
                        Entrar
                    </Link>
                </div>
            </div>
        </footer>
    );
}
