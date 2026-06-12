import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';

interface ErrorPageProps {
    status: number;
}

const MESSAGES: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Acceso denegado',
        description:
            'No tienes permisos para ver esta página. Si crees que es un error, contacta al administrador de tu equipo.',
    },
    404: {
        title: 'Página no encontrada',
        description: 'El recurso que buscas no existe o fue movido.',
    },
    500: {
        title: 'Error del servidor',
        description:
            'Algo salió mal de nuestro lado. Intenta de nuevo en unos minutos.',
    },
    503: {
        title: 'En mantenimiento',
        description:
            'SAM está en mantenimiento programado. Volvemos en unos minutos.',
    },
};

export default function ErrorPage({ status }: ErrorPageProps) {
    const { title, description } = MESSAGES[status] ?? MESSAGES[500];

    return (
        <div className="grid min-h-dvh place-items-center bg-background p-6">
            <Head title={title} />
            <div className="flex w-full max-w-md flex-col items-center text-center">
                <AppLogoIcon className="size-10 text-primary" />
                <div className="mt-6 font-mono text-[13px] font-semibold tracking-[0.2em] text-fg-3">
                    ERROR {status}
                </div>
                <h1 className="mt-2 text-xl font-semibold text-fg-1">
                    {title}
                </h1>
                <p className="mt-2 text-[14px] leading-relaxed text-fg-2">
                    {description}
                </p>
                <div className="mt-8 flex items-center gap-3">
                    <Button variant="outline" onClick={() => history.back()}>
                        Volver atrás
                    </Button>
                    <Button asChild>
                        <Link href="/">Ir al inicio</Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}
