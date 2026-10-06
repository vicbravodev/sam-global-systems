import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Pistas de circuito que salen hacia los bordes del panel de marca, el mismo
 * trazo del emblema de SAM. Decorativas: un solo color de borde, sin
 * animación, desvanecidas hacia el centro para dejar limpio el logo.
 */
const TRACES = [
    'M0 96 H120 L156 132 H236',
    'M0 152 H84 L112 180 H188 L212 204 H262',
    'M0 236 H56 L92 272 H150',
    'M600 120 H492 L460 152 H380',
    'M600 196 H520 L488 228 H436 L412 252 H360',
    'M0 560 H96 L132 524 H214',
    'M0 628 H150 L182 660 H260',
    'M600 548 H468 L436 580 H352',
    'M600 640 H540 L504 676 H420 L396 700 H338',
    'M600 704 H558 L530 732 H470',
];

const NODES: Array<[number, number]> = [
    [236, 96],
    [262, 204],
    [150, 272],
    [380, 152],
    [360, 252],
    [214, 524],
    [260, 660],
    [352, 580],
    [338, 700],
    [470, 732],
];

function CircuitTraces() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 600 800"
            preserveAspectRatio="xMidYMid slice"
            className="pointer-events-none absolute inset-0 size-full text-border-strong"
            style={{
                maskImage:
                    'radial-gradient(ellipse 60% 55% at 50% 48%, transparent 35%, black 85%)',
            }}
        >
            <g fill="none" stroke="currentColor" strokeWidth={1.25}>
                {TRACES.map((d) => (
                    <path key={d} d={d} strokeLinejoin="round" />
                ))}
                {NODES.map(([cx, cy]) => (
                    <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={4} />
                ))}
            </g>
        </svg>
    );
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="relative hidden flex-col overflow-hidden border-r border-border bg-surface-3 lg:flex">
                <CircuitTraces />

                <div className="relative flex flex-1 flex-col items-center justify-center gap-4 px-10">
                    <Link
                        href={home()}
                        className="rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-surface-3 focus-visible:outline-none"
                    >
                        <AppLogo className="w-96 xl:w-112" />
                    </Link>
                    <p className="max-w-sm text-center text-base text-pretty text-fg-2">
                        Cada alerta de tu flota, atendida a tiempo.
                    </p>
                </div>

                <p className="sam-meta relative px-10 pb-8">
                    © {new Date().getFullYear()} SAM Global Systems
                </p>
            </aside>

            <main className="flex flex-col items-center justify-center px-6 py-10 sm:px-10">
                <div className="flex w-full max-w-sm flex-col gap-8">
                    <Link
                        href={home()}
                        className="self-center rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:hidden"
                    >
                        <AppLogo className="w-52" />
                    </Link>

                    <header className="flex flex-col gap-1.5">
                        <h1 className="sam-h1">{title}</h1>
                        {description && (
                            <p className="text-base text-pretty text-fg-3">
                                {description}
                            </p>
                        )}
                    </header>

                    {children}
                </div>
            </main>
        </div>
    );
}
