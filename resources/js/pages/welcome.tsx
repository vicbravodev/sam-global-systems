import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    ArrowUpRight,
    Check,
    Mail,
    MapPin,
    Phone,
    PhoneCall,
    Radio,
    Search,
    ShieldCheck,
    X,
    Zap,
} from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { FeatureBento } from '@/components/landing/feature-bento';
import { LiveTriage } from '@/components/landing/live-triage';
import { Reveal } from '@/components/landing/reveal';
import { ScenarioExplorer } from '@/components/landing/scenario-explorer';
import { SignalField } from '@/components/landing/signal-field';
import { StatusPill } from '@/components/sam/status-pill';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const CONTACT_EMAIL = 'contacto@samglobaltechnologies.com';
const CONTACT_PHONE = '+52 81 1765 8890';
const DEMO_HREF = `mailto:${CONTACT_EMAIL}?subject=Solicitud%20de%20demo%20SAM`;

const NAV_LINKS = [
    { href: '#problema', label: 'El problema' },
    { href: '#caracteristicas', label: 'Características' },
    { href: '#como-funciona', label: 'Cómo funciona' },
    { href: '#casos', label: 'Casos' },
    { href: '#resultados', label: 'Resultados' },
];

/* Hechos del producto (no métricas inventadas): así se comporta el pipeline. */
const FACTS = [
    {
        icon: Zap,
        text: 'Pánico, colisión y vuelco abren incidente al instante, sin esperar evaluación.',
    },
    {
        icon: PhoneCall,
        text: 'Llamada, WhatsApp y SMS, con respaldo automático entre canales.',
    },
    {
        icon: Radio,
        text: 'Conexión directa con Samsara: eventos, video y ubicación.',
    },
];

const PROBLEMS = [
    {
        title: 'Fatiga de alertas',
        body: 'Cientos de notificaciones al día diluyen lo que de verdad importa.',
    },
    {
        title: 'Respuestas lentas',
        body: 'Revisar cada aviso a mano cuesta minutos en situaciones urgentes.',
    },
    {
        title: 'Criterio desigual',
        body: 'La evaluación cambia según quién esté de turno esa noche.',
    },
    {
        title: 'Emergencias enterradas',
        body: 'Un evento crítico se pierde bajo el volumen de avisos menores.',
    },
    {
        title: 'Costo que no escala',
        body: 'Un equipo de monitoreo 24/7 es caro y difícil de crecer.',
    },
];

const STEPS: {
    icon: typeof Radio;
    title: string;
    body: string;
    artifact: ReactNode;
}[] = [
    {
        icon: Radio,
        title: 'Recibe',
        body: 'Conecta tus dispositivos Samsara y SAM escucha cada evento de cada unidad.',
        artifact: <StatusPill state="new" />,
    },
    {
        icon: Search,
        title: 'Investiga',
        body: 'Revisa ubicación, historial del conductor y cámaras en segundos.',
        artifact: <StatusPill state="triaging" />,
    },
    {
        icon: Activity,
        title: 'Resume',
        body: 'Arma un resumen claro de qué está pasando y qué tan grave es.',
        artifact: <StatusPill state="assigned" label="Evaluado" />,
    },
    {
        icon: PhoneCall,
        title: 'Verifica',
        body: 'Confirma por voz con el operador antes de escalar nada.',
        artifact: <StatusPill state="in-progress" label="Verificando" />,
    },
    {
        icon: ShieldCheck,
        title: 'Decide',
        body: 'Notifica solo cuando hace falta. Lo demás queda documentado.',
        artifact: (
            <span className="flex gap-1.5">
                <StatusPill state="escalated" />
                <StatusPill state="discarded" />
            </span>
        ),
    },
];

const COMPARISON = [
    [
        'Cientos de avisos sin filtrar cada día',
        'Solo las alertas que de verdad importan',
    ],
    [
        'El turno decide qué revisar y qué ignorar',
        'Criterio consistente las 24 horas',
    ],
    [
        'Emergencias que se descubren tarde',
        'Emergencias verificadas y escaladas al instante',
    ],
    [
        'Equipo de monitoreo creciendo en costo',
        'El ruido descartado queda documentado',
    ],
];

const SEGMENTS = [
    'Transporte de carga',
    'Logística y última milla',
    'Seguridad patrimonial',
    'Flotas de pasajeros',
    'Renta de equipo pesado',
    'Operaciones 24/7',
];

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : '/';

    return (
        <>
            <Head title="SAM · Monitoreo inteligente para flotas">
                <meta
                    name="description"
                    content="SAM investiga cada alerta de tu flota, revisa cámara y ubicación, y verifica antes de escalar. Solo las emergencias reales llegan a tu equipo."
                />
            </Head>

            <div className="min-h-dvh scroll-smooth bg-background text-fg-1 antialiased">
                {/* ---------- Nav ---------- */}
                <header className="sticky top-0 z-40 border-b border-border/60 bg-background/75 backdrop-blur-md">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-8">
                        <a
                            href="#top"
                            className="flex items-center gap-2.5"
                            aria-label="SAM, inicio"
                        >
                            <AppLogoIcon className="size-8" />
                            <span className="text-md font-semibold tracking-tight">
                                SAM
                            </span>
                        </a>
                        <nav className="hidden items-center gap-7 lg:flex">
                            {NAV_LINKS.map((link) => (
                                <a
                                    key={link.href}
                                    href={link.href}
                                    className="text-sm text-fg-2 transition-colors hover:text-fg-1"
                                >
                                    {link.label}
                                </a>
                            ))}
                        </nav>
                        <div className="flex items-center gap-2">
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={dashboardUrl}>Ir al panel</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="ghost"
                                        className="hidden sm:inline-flex"
                                    >
                                        <Link href={login()}>
                                            Iniciar sesión
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm">
                                        <a href={DEMO_HREF}>Solicitar demo</a>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </header>

                <main id="top">
                    {/* ---------- Hero ---------- */}
                    <section className="relative overflow-hidden">
                        <HeroBackdrop />
                        <div className="relative mx-auto grid max-w-7xl grid-cols-1 items-center gap-14 px-5 pt-14 pb-16 sm:px-8 lg:min-h-[min(calc(100dvh-4rem),54rem)] lg:grid-cols-[minmax(0,1fr)_30rem] lg:gap-16 lg:pt-12 lg:pb-20 xl:grid-cols-[minmax(0,1fr)_32rem]">
                            <div className="sam-row-in min-w-0">
                                <p className="font-mono text-2xs font-semibold tracking-caps text-primary uppercase">
                                    Monitorista virtual para flotas
                                </p>
                                <h1 className="mt-6 text-3xl font-semibold tracking-display sm:text-4xl lg:text-3xl xl:text-4xl">
                                    <span className="block">
                                        Cada alerta investigada.
                                    </span>
                                    <span className="block text-fg-3">
                                        Solo lo real llega a ti.
                                    </span>
                                </h1>
                                <p className="mt-6 max-w-md text-md leading-relaxed text-fg-2 sm:text-lg sm:leading-relaxed">
                                    SAM revisa cámara y ubicación de cada alerta
                                    y verifica antes de escalar. El ruido queda
                                    documentado.
                                </p>
                                <div className="mt-9 flex flex-wrap items-center gap-3">
                                    <Button
                                        asChild
                                        size="lg"
                                        className="group active:translate-y-px"
                                    >
                                        <a href={DEMO_HREF}>
                                            Solicitar demo
                                            <ArrowRight
                                                strokeWidth={1.75}
                                                className="transition-transform duration-300 ease-(--ease-out) group-hover:translate-x-0.5"
                                            />
                                        </a>
                                    </Button>
                                    <Button
                                        asChild
                                        size="lg"
                                        variant="ghost"
                                        className="text-fg-2"
                                    >
                                        <a href="#como-funciona">
                                            Ver cómo funciona
                                        </a>
                                    </Button>
                                </div>
                            </div>

                            <div
                                className="sam-row-in relative min-w-0"
                                style={{ animationDelay: '160ms' }}
                            >
                                <div
                                    aria-hidden="true"
                                    className="absolute -inset-6 -z-10 rounded-3xl bg-primary/8 blur-2xl"
                                />
                                <LiveTriage />
                            </div>
                        </div>
                    </section>

                    {/* ---------- Hechos del producto ---------- */}
                    <section className="border-y border-border bg-surface-3/60">
                        <ul className="mx-auto grid max-w-7xl divide-y divide-border px-5 sm:px-8 md:grid-cols-3 md:divide-x md:divide-y-0">
                            {FACTS.map((fact) => (
                                <li
                                    key={fact.text}
                                    className="flex items-start gap-3 py-6 md:px-8 md:first:pl-0 md:last:pr-0"
                                >
                                    <fact.icon
                                        className="mt-0.5 size-4 shrink-0 text-primary"
                                        strokeWidth={1.75}
                                    />
                                    <p className="text-base leading-relaxed text-fg-2">
                                        {fact.text}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {/* ---------- Problema ---------- */}
                    <section id="problema" className="scroll-mt-16">
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <Reveal>
                                <h2 className="max-w-3xl text-2xl font-semibold tracking-display text-balance sm:text-3xl lg:text-4xl">
                                    Tu equipo no puede revisar todo con la misma
                                    atención.
                                </h2>
                                <p className="mt-5 max-w-xl text-md leading-relaxed text-fg-2">
                                    De cada cien eventos, casi todos son ruido.
                                    El trabajo es encontrar los pocos que no lo
                                    son, a cualquier hora.
                                </p>
                            </Reveal>

                            <Reveal delay={100} className="mt-14">
                                <SignalField />
                            </Reveal>

                            <div className="mt-20 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-5 lg:gap-0">
                                {PROBLEMS.map((p, i) => (
                                    <Reveal
                                        key={p.title}
                                        delay={i * 70}
                                        className="lg:border-l lg:border-border lg:px-6 lg:first:border-l-0 lg:first:pl-0"
                                    >
                                        <h3 className="text-base font-semibold text-fg-1">
                                            {p.title}
                                        </h3>
                                        <p className="mt-2 text-sm leading-relaxed text-fg-3">
                                            {p.body}
                                        </p>
                                    </Reveal>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* ---------- Cómo funciona (pipeline) ---------- */}
                    <section
                        id="como-funciona"
                        className="scroll-mt-16 border-t border-border bg-surface-3/60"
                    >
                        <div className="mx-auto grid max-w-7xl gap-14 px-5 py-24 sm:px-8 lg:grid-cols-[0.85fr_1.15fr] lg:gap-20 lg:py-32">
                            <div className="lg:sticky lg:top-32 lg:self-start">
                                <Reveal>
                                    <p className="font-mono text-2xs font-semibold tracking-caps text-primary uppercase">
                                        Cómo funciona
                                    </p>
                                    <h2 className="mt-5 text-2xl font-semibold tracking-display text-balance sm:text-3xl lg:text-4xl">
                                        Simple. Automático. Verificado.
                                    </h2>
                                    <p className="mt-5 max-w-sm text-md leading-relaxed text-fg-2">
                                        El mismo recorrido para cada evento, de
                                        día o de noche, con el mismo criterio.
                                    </p>
                                </Reveal>
                            </div>

                            <ol className="relative">
                                <span
                                    aria-hidden="true"
                                    className="absolute top-5 bottom-5 left-5 w-px bg-border"
                                />
                                <span
                                    aria-hidden="true"
                                    className="sam-draw absolute top-5 bottom-5 left-5 w-px bg-primary"
                                />
                                {STEPS.map((step, i) => (
                                    <li
                                        key={step.title}
                                        className="pb-12 last:pb-0"
                                    >
                                        <Reveal
                                            delay={i * 60}
                                            className="relative grid grid-cols-[2.5rem_1fr] gap-5"
                                        >
                                            <span className="relative z-10 flex size-10 items-center justify-center rounded-lg border border-border bg-surface-1 text-primary shadow-sm">
                                                <step.icon
                                                    className="size-4.5"
                                                    strokeWidth={1.75}
                                                />
                                            </span>
                                            <div className="pt-1.5">
                                                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <h3 className="text-lg font-semibold tracking-tight text-fg-1">
                                                        {step.title}
                                                    </h3>
                                                    {step.artifact}
                                                </div>
                                                <p className="mt-2 max-w-md text-base leading-relaxed text-fg-3">
                                                    {step.body}
                                                </p>
                                            </div>
                                        </Reveal>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>

                    {/* ---------- Características (bento) ---------- */}
                    <section
                        id="caracteristicas"
                        className="scroll-mt-16 border-t border-border"
                    >
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <Reveal>
                                <h2 className="max-w-2xl text-2xl font-semibold tracking-display text-balance sm:text-3xl lg:text-4xl">
                                    Una capa de criterio sobre tus dispositivos.
                                </h2>
                                <p className="mt-5 max-w-lg text-md leading-relaxed text-fg-2">
                                    Despierta a tu equipo solo cuando algo lo
                                    amerita.
                                </p>
                            </Reveal>
                            <div className="mt-14">
                                <FeatureBento />
                            </div>
                        </div>
                    </section>

                    {/* ---------- Casos ---------- */}
                    <section
                        id="casos"
                        className="scroll-mt-16 border-t border-border bg-surface-3/60"
                    >
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <Reveal>
                                <h2 className="max-w-2xl text-2xl font-semibold tracking-display text-balance sm:text-3xl lg:text-4xl">
                                    Así responde SAM en cada situación.
                                </h2>
                            </Reveal>
                            <Reveal delay={100} className="mt-12">
                                <ScenarioExplorer />
                            </Reveal>
                        </div>
                    </section>

                    {/* ---------- Resultados ---------- */}
                    <section
                        id="resultados"
                        className="scroll-mt-16 border-t border-border"
                    >
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <Reveal className="flex flex-col gap-6 sm:flex-row sm:items-end sm:gap-10">
                                <span className="font-mono text-5xl font-medium tracking-display text-primary tabular-nums lg:text-6xl">
                                    80%
                                </span>
                                <p className="max-w-sm pb-2 text-lg leading-snug text-fg-2">
                                    menos falsas alarmas llegando a tu equipo
                                    desde el primer día.
                                </p>
                            </Reveal>

                            <Reveal delay={120} className="mt-16">
                                <div className="overflow-hidden rounded-xl border border-border">
                                    <div className="grid grid-cols-2 border-b border-border bg-surface-2 text-2xs font-semibold tracking-caps uppercase">
                                        <span className="px-5 py-3 text-fg-3 sm:px-7">
                                            Sin SAM
                                        </span>
                                        <span className="border-l border-border px-5 py-3 text-primary sm:px-7">
                                            Con SAM
                                        </span>
                                    </div>
                                    {COMPARISON.map(([before, after]) => (
                                        <div
                                            key={before}
                                            className="grid grid-cols-2 border-b border-border last:border-b-0"
                                        >
                                            <p className="flex gap-3 px-5 py-5 text-sm leading-relaxed text-fg-3 sm:px-7 sm:text-base">
                                                <X
                                                    className="mt-0.5 size-4 shrink-0 text-fg-disabled"
                                                    strokeWidth={1.75}
                                                />
                                                {before}
                                            </p>
                                            <p className="flex gap-3 border-l border-border bg-surface-1 px-5 py-5 text-sm leading-relaxed font-medium text-fg-1 sm:px-7 sm:text-base">
                                                <Check
                                                    className="mt-0.5 size-4 shrink-0 text-health-ok"
                                                    strokeWidth={2}
                                                />
                                                {after}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </Reveal>
                        </div>
                    </section>

                    {/* ---------- Para quién ---------- */}
                    <section className="border-t border-border py-20 lg:py-24">
                        <Reveal className="mx-auto max-w-7xl px-5 sm:px-8">
                            <h2 className="max-w-xl text-xl font-semibold tracking-tight text-balance sm:text-2xl">
                                Pensado para quien no puede permitirse perder
                                una emergencia.
                            </h2>
                        </Reveal>
                        <SegmentsMarquee />
                    </section>

                    {/* ---------- CTA final ---------- */}
                    <section className="px-5 pb-24 sm:px-8 lg:pb-32">
                        <Reveal className="mx-auto max-w-7xl">
                            <div className="relative overflow-hidden rounded-xl border border-border bg-[linear-gradient(120deg,color-mix(in_oklch,var(--primary)_16%,var(--surface-1)),var(--surface-1)_60%)]">
                                <div className="grid gap-12 p-8 sm:p-12 lg:grid-cols-[1.3fr_0.7fr] lg:items-end lg:p-16">
                                    <div>
                                        <h2 className="max-w-xl text-2xl font-semibold tracking-display text-balance sm:text-3xl lg:text-4xl">
                                            ¿Listo para proteger tu flota de
                                            verdad?
                                        </h2>
                                        <p className="mt-5 max-w-md text-md leading-relaxed text-fg-2">
                                            Te mostramos SAM con las alertas de
                                            tu propia operación en una llamada
                                            de 30 minutos.
                                        </p>
                                        <div className="mt-9 flex flex-wrap items-center gap-3">
                                            <Button
                                                asChild
                                                size="lg"
                                                className="group active:translate-y-px"
                                            >
                                                <a href={DEMO_HREF}>
                                                    Solicitar demo
                                                    <ArrowRight
                                                        strokeWidth={1.75}
                                                        className="transition-transform duration-300 ease-(--ease-out) group-hover:translate-x-0.5"
                                                    />
                                                </a>
                                            </Button>
                                            {!auth.user && (
                                                <Button
                                                    asChild
                                                    size="lg"
                                                    variant="ghost"
                                                    className="text-fg-2"
                                                >
                                                    <Link href={login()}>
                                                        Iniciar sesión
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    <ul className="flex flex-col gap-4 border-t border-border pt-8 text-base lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10">
                                        <ContactLine
                                            icon={Mail}
                                            href={`mailto:${CONTACT_EMAIL}`}
                                        >
                                            {CONTACT_EMAIL}
                                        </ContactLine>
                                        <ContactLine
                                            icon={Phone}
                                            href="tel:+528117658890"
                                        >
                                            {CONTACT_PHONE}
                                        </ContactLine>
                                        <ContactLine icon={MapPin}>
                                            Nuevo León, México
                                        </ContactLine>
                                    </ul>
                                </div>
                            </div>
                        </Reveal>
                    </section>
                </main>

                {/* ---------- Footer ---------- */}
                <footer className="border-t border-border">
                    <div className="mx-auto flex max-w-7xl flex-col gap-8 px-5 py-10 sm:px-8 md:flex-row md:items-center md:justify-between">
                        <div className="flex items-center gap-3">
                            <AppLogoIcon className="size-7" />
                            <p className="text-sm text-fg-3">
                                <span className="font-semibold text-fg-1">
                                    SAM
                                </span>{' '}
                                Sistema Automatizado de Monitoreo
                            </p>
                        </div>
                        <nav className="flex flex-wrap gap-x-6 gap-y-3">
                            {NAV_LINKS.map((link) => (
                                <a
                                    key={link.href}
                                    href={link.href}
                                    className="text-sm text-fg-3 transition-colors hover:text-fg-1"
                                >
                                    {link.label}
                                </a>
                            ))}
                            <Link
                                href={login()}
                                className="text-sm text-fg-3 transition-colors hover:text-fg-1"
                            >
                                Iniciar sesión
                            </Link>
                        </nav>
                    </div>
                    <div className="mx-auto max-w-7xl border-t border-border px-5 py-6 text-xs text-fg-disabled sm:px-8">
                        © 2026 SAM, Sistema Automatizado de Monitoreo. Todos los
                        derechos reservados.
                    </div>
                </footer>
            </div>
        </>
    );
}

/* ============================ Sub-components ============================ */

function HeroBackdrop() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            <div className="absolute -top-48 right-[-10%] size-[46rem] rounded-full bg-primary/10 blur-[140px]" />
            <div
                className="absolute inset-0 [background-image:radial-gradient(var(--border-strong)_1px,transparent_1px)] [background-size:22px_22px] opacity-35"
                style={{
                    maskImage:
                        'radial-gradient(ellipse 70% 70% at 75% 40%, black, transparent)',
                    WebkitMaskImage:
                        'radial-gradient(ellipse 70% 70% at 75% 40%, black, transparent)',
                }}
            />
        </div>
    );
}

/* Única marquesina de la página: los segmentos no requieren atención
   individual, solo transmitir amplitud. Con reduced-motion se muestra fija. */
function SegmentsMarquee() {
    const items = [...SEGMENTS, ...SEGMENTS];

    return (
        <div className="sam-marquee-wrap relative mt-10 overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_8%,black_92%,transparent)]">
            <ul className="sam-marquee flex w-max gap-3 motion-reduce:w-auto motion-reduce:flex-wrap motion-reduce:px-5">
                {items.map((s, i) => (
                    <li
                        key={`${s}-${i}`}
                        aria-hidden={i >= SEGMENTS.length}
                        className="rounded-full border border-border bg-surface-1 px-5 py-2.5 text-base whitespace-nowrap text-fg-2 motion-reduce:[&:nth-child(n+7)]:hidden"
                    >
                        {s}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ContactLine({
    icon: Icon,
    href,
    children,
}: {
    icon: typeof Mail;
    href?: string;
    children: ReactNode;
}) {
    const content = (
        <>
            <Icon className="size-4 shrink-0 text-fg-3" strokeWidth={1.75} />
            <span className="truncate">{children}</span>
            {href && (
                <ArrowUpRight
                    className="size-3.5 shrink-0 text-fg-3 opacity-0 transition-opacity group-hover:opacity-100"
                    strokeWidth={1.75}
                />
            )}
        </>
    );

    return (
        <li>
            {href ? (
                <a
                    href={href}
                    className="group flex items-center gap-3 text-fg-2 transition-colors hover:text-fg-1"
                >
                    {content}
                </a>
            ) : (
                <span className="flex items-center gap-3 text-fg-3">
                    {content}
                </span>
            )}
        </li>
    );
}
