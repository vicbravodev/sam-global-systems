import { Head, Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { logoutForgettingDevice } from '@/lib/web-push';
import { logout } from '@/routes';

interface OtherTeam {
    name: string;
    slug: string;
    url: string;
}

interface TenantSuspendedProps {
    teamName: string;
    otherTeams: OtherTeam[];
    billingUrl: string | null;
}

export default function TenantSuspended({
    teamName,
    otherTeams,
    billingUrl,
}: TenantSuspendedProps) {
    return (
        <div className="grid min-h-dvh place-items-center bg-background p-6">
            <Head title="Cuenta suspendida" />
            <div className="flex w-full max-w-md flex-col items-center text-center">
                <AppLogo className="h-24" />
                <h1 className="mt-6 text-xl font-semibold text-fg-1">
                    Cuenta suspendida
                </h1>
                <p className="mt-2 text-base leading-relaxed text-fg-2">
                    El acceso de <strong>{teamName}</strong> a SAM está
                    suspendido. Seguimos atendiendo los botones de pánico de tu
                    flota, pero la consola no está disponible.
                </p>
                <p className="mt-2 text-base leading-relaxed text-fg-2">
                    Para reactivarla, paga tus facturas pendientes o contacta a
                    SAM.
                </p>

                {billingUrl !== null && (
                    <Button asChild className="mt-6">
                        <Link href={billingUrl}>Ver y pagar facturas</Link>
                    </Button>
                )}

                {otherTeams.length > 0 && (
                    <div className="mt-8 w-full">
                        <div className="text-sm text-fg-3">
                            Entrar a otra empresa
                        </div>
                        <ul className="mt-2 flex flex-col gap-2">
                            {otherTeams.map((team) => (
                                <li key={team.slug}>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Link href={team.url}>{team.name}</Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="mt-8">
                    <Button asChild variant="ghost">
                        <Link
                            href={logout()}
                            as="button"
                            onClick={(event) => {
                                event.preventDefault();
                                void logoutForgettingDevice();
                            }}
                        >
                            Cerrar sesión
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}
