import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { DemoRequestForm } from '@/components/landing/demo-request-form';
import { LandingFooter } from '@/components/landing/landing-footer';
import { home } from '@/routes';

interface DemoRequestPageProps {
    fleetSizes: string[];
}

const NEXT_STEPS = [
    'Te escribimos en menos de un día hábil para agendar.',
    'En una llamada de 30 minutos te mostramos SAM con alertas de tu propia operación.',
    'Sin compromiso: decides después de verlo funcionando.',
];

export default function DemoRequestPage({ fleetSizes }: DemoRequestPageProps) {
    return (
        <>
            <Head title="Pedir una demo · SAM">
                <meta
                    name="description"
                    content="Agenda una demo de SAM: te mostramos cómo vigila tu flota día y noche con las alertas de tu propia operación."
                />
            </Head>

            <div className="theme-light flex min-h-dvh flex-col bg-brand-paper text-brand-ink antialiased [color-scheme:light]">
                <header className="border-b border-brand-line/70">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-8">
                        <Link
                            href={home()}
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
                        </Link>
                        <Link
                            href={home()}
                            className="inline-flex items-center gap-1.5 text-base text-brand-ink-2 hover:text-brand-ink"
                        >
                            <ArrowLeft className="size-4" />
                            Volver al sitio
                        </Link>
                    </div>
                </header>

                <main className="mx-auto grid w-full max-w-7xl flex-1 gap-12 px-5 py-14 sm:px-8 lg:grid-cols-[1fr_1.15fr] lg:gap-20 lg:py-24">
                    <div>
                        <h1 className="text-4xl font-semibold tracking-poster text-balance lg:text-5xl">
                            Pide tu demo de SAM
                        </h1>
                        <p className="mt-5 max-w-md text-md leading-relaxed text-brand-ink-2">
                            Cuéntanos un poco de tu flota y te contactamos para
                            mostrarte cómo SAM la vigila día y noche.
                        </p>
                        <ol className="mt-10 grid max-w-md gap-5">
                            {NEXT_STEPS.map((step, index) => (
                                <li key={step} className="flex gap-4">
                                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-mist font-semibold text-brand-petrol">
                                        {index + 1}
                                    </span>
                                    <span className="pt-1 text-base leading-relaxed text-brand-ink-2">
                                        {step}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </div>

                    <div>
                        <DemoRequestForm fleetSizes={fleetSizes} />
                    </div>
                </main>

                <LandingFooter />
            </div>
        </>
    );
}
