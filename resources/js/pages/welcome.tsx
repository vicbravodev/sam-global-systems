import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth, currentTeam } = usePage().props;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : '/';

    return (
        <>
            <Head title="Bienvenido" />
            <div className="flex min-h-dvh flex-col bg-background text-fg-1">
                <header className="flex items-center justify-between px-6 py-5 sm:px-10">
                    <div className="flex items-center gap-2.5">
                        <AppLogoIcon className="size-8 text-primary" />
                        <span className="text-[15px] font-semibold tracking-tight">
                            SAM
                        </span>
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={dashboardUrl}>Ir al panel</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild size="sm" variant="ghost">
                                    <Link href={login()}>Iniciar sesión</Link>
                                </Button>
                                {canRegister && (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={register()}>
                                            Crear cuenta
                                        </Link>
                                    </Button>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                <main className="flex flex-1 flex-col items-center justify-center px-6 text-center">
                    <div className="max-w-2xl">
                        <p className="font-mono text-[12px] font-semibold tracking-[0.18em] text-fg-3 uppercase">
                            Monitorista virtual para flotas
                        </p>
                        <h1 className="mt-4 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            Cada alerta investigada.
                            <br />
                            Solo lo real escala.
                        </h1>
                        <p className="mx-auto mt-5 max-w-xl text-[15px] leading-relaxed text-fg-2">
                            SAM recibe las alertas de tu flota, pide el video,
                            analiza la evidencia con IA y verifica por voz con
                            el operador antes de escalar. El ruido se descarta
                            documentado; las emergencias llegan a quien deben
                            llegar.
                        </p>
                        <div className="mt-8 flex items-center justify-center gap-3">
                            {auth.user ? (
                                <Button asChild size="lg">
                                    <Link href={dashboardUrl}>Ir al panel</Link>
                                </Button>
                            ) : (
                                <Button asChild size="lg">
                                    <Link href={login()}>Iniciar sesión</Link>
                                </Button>
                            )}
                        </div>
                    </div>
                </main>

                <footer className="px-6 py-6 text-center text-[12px] text-fg-3">
                    SAM Global Systems · Monitoreo de flotas en México
                </footer>
            </div>
        </>
    );
}
