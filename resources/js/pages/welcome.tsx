import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Camera,
    Check,
    Eye,
    Inbox,
    MapPin,
    MessageSquare,
    PhoneCall,
    Radio,
    Search,
    ShieldCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { StatusPill } from '@/components/sam/status-pill';
import { Button } from '@/components/ui/button';
import { useReveal } from '@/hooks/use-reveal';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

const CONTACT_EMAIL = 'contacto@samglobaltechnologies.com';
const DEMO_HREF = `mailto:${CONTACT_EMAIL}?subject=Solicitud%20de%20demo%20SAM`;

/* Scroll-reveal wrapper. Subtle fade + lift; collapses to static under
   prefers-reduced-motion (handled inside useReveal). */
function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: ReactNode;
    delay?: number;
    className?: string;
}) {
    const { ref, visible } = useReveal<HTMLDivElement>();

    return (
        <div
            ref={ref}
            style={{ transitionDelay: `${delay}ms` }}
            className={cn(
                'transition-all duration-700 ease-(--ease-out) motion-reduce:translate-y-0 motion-reduce:opacity-100 motion-reduce:transition-none',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-3 opacity-0',
                className,
            )}
        >
            {children}
        </div>
    );
}

const NAV_LINKS = [
    { href: '#problema', label: 'El problema' },
    { href: '#caracteristicas', label: 'Características' },
    { href: '#como-funciona', label: 'Cómo funciona' },
    { href: '#casos', label: 'Casos' },
    { href: '#resultados', label: 'Resultados' },
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

const STEPS = [
    {
        icon: Radio,
        title: 'Recibe',
        body: 'Conecta tus dispositivos Samsara y SAM escucha cada evento.',
    },
    {
        icon: Search,
        title: 'Investiga',
        body: 'Revisa ubicación, historial del conductor y cámaras en segundos.',
    },
    {
        icon: Activity,
        title: 'Resume',
        body: 'Arma un resumen claro de qué está pasando y qué tan grave es.',
    },
    {
        icon: PhoneCall,
        title: 'Verifica',
        body: 'Confirma por voz con el operador antes de escalar nada.',
    },
    {
        icon: ShieldCheck,
        title: 'Decide',
        body: 'Notifica solo cuando hace falta. Lo demás queda documentado.',
    },
];

const SEGMENTS = [
    'Transporte de carga',
    'Logística y última milla',
    'Seguridad patrimonial',
    'Flotas de pasajeros',
    'Renta de equipo pesado',
    'Operaciones 24/7',
];

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    void canRegister;
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
                <header className="sticky top-0 z-40 border-b border-border/70 bg-background/80 backdrop-blur-md">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-5 sm:px-8">
                        <a
                            href="#top"
                            className="flex items-center gap-2.5"
                            aria-label="SAM, inicio"
                        >
                            <AppLogoIcon className="size-7 text-primary" />
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
                        <div className="mx-auto grid max-w-6xl gap-12 px-5 pt-16 pb-20 sm:px-8 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-12 lg:pt-24 lg:pb-28">
                            <div className="relative">
                                <p className="font-mono text-2xs font-semibold tracking-caps text-primary uppercase">
                                    Monitorista virtual para flotas
                                </p>
                                <h1 className="mt-5 text-3xl font-semibold tracking-tight text-balance text-pretty sm:text-[2.5rem] sm:leading-[1.1] lg:text-[2.75rem]">
                                    Cada alerta investigada.
                                    <br />
                                    Solo lo real llega a ti.
                                </h1>
                                <p className="mt-5 max-w-md text-md leading-relaxed text-fg-2">
                                    SAM recibe cada alerta de tu flota, revisa
                                    cámara y ubicación, y verifica antes de
                                    escalar. El ruido queda documentado.
                                </p>
                                <div className="mt-8 flex flex-wrap items-center gap-3">
                                    <Button asChild size="lg">
                                        <a href={DEMO_HREF}>
                                            Solicitar demo
                                            <ArrowRight strokeWidth={1.75} />
                                        </a>
                                    </Button>
                                    <Button asChild size="lg" variant="outline">
                                        <a href="#como-funciona">
                                            Ver cómo funciona
                                        </a>
                                    </Button>
                                </div>
                                <p className="mt-6 flex items-center gap-2 text-xs text-fg-3">
                                    <span
                                        className="size-1.5 rounded-full bg-health-ok"
                                        aria-hidden="true"
                                    />
                                    Compatible con dispositivos Samsara
                                </p>
                            </div>

                            <Reveal delay={120}>
                                <TriageVignette />
                            </Reveal>
                        </div>
                    </section>

                    {/* ---------- Problema ---------- */}
                    <section
                        id="problema"
                        className="scroll-mt-20 border-t border-border bg-surface-3"
                    >
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                            <Reveal>
                                <h2 className="max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                    Tu equipo no puede revisar todo con la misma
                                    atención
                                </h2>
                            </Reveal>
                            <div className="mt-12 grid gap-12 lg:grid-cols-[1fr_0.85fr] lg:items-center lg:gap-16">
                                <Reveal>
                                    <ul className="divide-y divide-border border-t border-border">
                                        {PROBLEMS.map((p) => (
                                            <li
                                                key={p.title}
                                                className="flex gap-4 py-4"
                                            >
                                                <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-border-strong" />
                                                <div>
                                                    <h3 className="text-base font-medium text-fg-1">
                                                        {p.title}
                                                    </h3>
                                                    <p className="mt-0.5 text-sm leading-relaxed text-fg-3">
                                                        {p.body}
                                                    </p>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </Reveal>
                                <Reveal delay={120}>
                                    <NoiseVsSignal />
                                </Reveal>
                            </div>
                        </div>
                    </section>

                    {/* ---------- Características (bento) ---------- */}
                    <section
                        id="caracteristicas"
                        className="scroll-mt-20 border-t border-border"
                    >
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                            <Reveal>
                                <h2 className="max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                    Todo lo que necesitas para proteger tu flota
                                </h2>
                                <p className="mt-3 max-w-lg text-md leading-relaxed text-fg-2">
                                    Una capa de criterio sobre tus dispositivos,
                                    despierta solo cuando algo lo amerita.
                                </p>
                            </Reveal>
                            <Reveal delay={100}>
                                <FeatureBento />
                            </Reveal>
                        </div>
                    </section>

                    {/* ---------- Cómo funciona (pipeline) ---------- */}
                    <section
                        id="como-funciona"
                        className="scroll-mt-20 border-t border-border bg-surface-3"
                    >
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                            <Reveal>
                                <p className="font-mono text-2xs font-semibold tracking-caps text-primary uppercase">
                                    Cómo funciona
                                </p>
                                <h2 className="mt-4 max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                    Simple. Automático. Verificado.
                                </h2>
                            </Reveal>
                            <div className="mt-14 grid gap-px overflow-hidden rounded-xl border border-border bg-border md:grid-cols-5">
                                {STEPS.map((step, i) => {
                                    const StepIcon = step.icon;

                                    return (
                                        <Reveal
                                            key={step.title}
                                            delay={i * 70}
                                            className="bg-surface-1"
                                        >
                                            <div className="flex h-full flex-col gap-3 p-6">
                                                <div className="flex items-center justify-between">
                                                    <span className="flex size-9 items-center justify-center rounded-md bg-primary/12 text-primary">
                                                        <StepIcon
                                                            className="size-4.5"
                                                            strokeWidth={1.75}
                                                        />
                                                    </span>
                                                    <span className="font-mono text-2xs text-fg-disabled">
                                                        0{i + 1}
                                                    </span>
                                                </div>
                                                <h3 className="text-base font-semibold text-fg-1">
                                                    {step.title}
                                                </h3>
                                                <p className="text-sm leading-relaxed text-fg-3">
                                                    {step.body}
                                                </p>
                                            </div>
                                        </Reveal>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    {/* ---------- Casos / ejemplos reales ---------- */}
                    <section
                        id="casos"
                        className="scroll-mt-20 border-t border-border"
                    >
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                            <Reveal>
                                <h2 className="max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                    Así responde SAM en cada situación
                                </h2>
                            </Reveal>
                            <div className="mt-12 grid gap-5 md:grid-cols-3">
                                {CASES.map((c, i) => (
                                    <Reveal key={c.title} delay={i * 90}>
                                        <CaseCard {...c} />
                                    </Reveal>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* ---------- Resultados / antes y después ---------- */}
                    <section
                        id="resultados"
                        className="scroll-mt-20 border-t border-border bg-surface-3"
                    >
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                            <div className="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:items-center lg:gap-16">
                                <Reveal>
                                    <div>
                                        <div className="text-[3.5rem] leading-none font-semibold tracking-tight text-primary tabular-nums">
                                            80%
                                        </div>
                                        <p className="mt-3 max-w-xs text-md leading-relaxed text-fg-2">
                                            menos falsas alarmas llegando a tu
                                            equipo desde el primer día.
                                        </p>
                                    </div>
                                </Reveal>
                                <Reveal delay={120}>
                                    <BeforeAfter />
                                </Reveal>
                            </div>
                        </div>
                    </section>

                    {/* ---------- Para quién ---------- */}
                    <section className="border-t border-border">
                        <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-24">
                            <Reveal>
                                <div className="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                                    <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                        Pensado para quien no puede permitirse
                                        perder una emergencia
                                    </h2>
                                    <div className="flex flex-wrap gap-2.5">
                                        {SEGMENTS.map((s) => (
                                            <span
                                                key={s}
                                                className="rounded-full border border-border bg-surface-1 px-3.5 py-2 text-sm text-fg-2"
                                            >
                                                {s}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </Reveal>
                        </div>
                    </section>

                    {/* ---------- CTA final ---------- */}
                    <section className="border-t border-border bg-surface-3">
                        <div className="mx-auto max-w-6xl px-5 py-24 sm:px-8 lg:py-32">
                            <Reveal className="mx-auto max-w-2xl text-center">
                                <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                    ¿Listo para proteger tu flota de verdad?
                                </h2>
                                <p className="mx-auto mt-4 max-w-md text-md leading-relaxed text-fg-2">
                                    Te mostramos SAM con las alertas de tu
                                    propia operación en una llamada de 30
                                    minutos.
                                </p>
                                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                                    <Button asChild size="lg">
                                        <a href={DEMO_HREF}>
                                            Solicitar demo
                                            <ArrowRight strokeWidth={1.75} />
                                        </a>
                                    </Button>
                                    {!auth.user && (
                                        <Button
                                            asChild
                                            size="lg"
                                            variant="outline"
                                        >
                                            <Link href={login()}>
                                                Iniciar sesión
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </Reveal>
                        </div>
                    </section>
                </main>

                {/* ---------- Footer ---------- */}
                <footer className="border-t border-border">
                    <div className="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                        <div className="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
                            <div>
                                <div className="flex items-center gap-2.5">
                                    <AppLogoIcon className="size-7 text-primary" />
                                    <span className="text-md font-semibold tracking-tight">
                                        SAM
                                    </span>
                                </div>
                                <p className="mt-4 max-w-xs text-sm leading-relaxed text-fg-3">
                                    Sistema Automatizado de Monitoreo. Monitoreo
                                    inteligente para tu flota.
                                </p>
                            </div>
                            <FooterCol
                                title="Producto"
                                links={[
                                    {
                                        label: 'Características',
                                        href: '#caracteristicas',
                                    },
                                    {
                                        label: 'Cómo funciona',
                                        href: '#como-funciona',
                                    },
                                    { label: 'Casos', href: '#casos' },
                                    {
                                        label: 'Resultados',
                                        href: '#resultados',
                                    },
                                ]}
                            />
                            <FooterCol
                                title="Contacto"
                                links={[
                                    {
                                        label: CONTACT_EMAIL,
                                        href: `mailto:${CONTACT_EMAIL}`,
                                    },
                                    {
                                        label: '+52 81 1765 8890',
                                        href: 'tel:+528117658890',
                                    },
                                    { label: 'Nuevo León, México' },
                                ]}
                            />
                            <FooterCol
                                title="Acceso"
                                links={[
                                    { label: 'Iniciar sesión', href: '/login' },
                                    {
                                        label: 'Solicitar demo',
                                        href: DEMO_HREF,
                                    },
                                ]}
                            />
                        </div>
                        <div className="mt-12 border-t border-border pt-6 text-xs text-fg-disabled">
                            © 2026 SAM, Sistema Automatizado de Monitoreo. Todos
                            los derechos reservados.
                        </div>
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
            <div className="absolute -top-40 left-1/2 size-[42rem] -translate-x-1/4 rounded-full bg-primary/10 blur-[120px]" />
            <div
                className="absolute inset-0 [background-image:linear-gradient(var(--border-strong)_1px,transparent_1px),linear-gradient(90deg,var(--border-strong)_1px,transparent_1px)] [background-size:64px_64px] opacity-[0.04]"
                style={{
                    maskImage:
                        'radial-gradient(ellipse 80% 60% at 50% 0%, black, transparent)',
                    WebkitMaskImage:
                        'radial-gradient(ellipse 80% 60% at 50% 0%, black, transparent)',
                }}
            />
        </div>
    );
}

/* Real component vignette built from the actual SAM design system (StatusPill,
   SeverityBadge). Not a faked screenshot, the genuine UI language. */
function TriageVignette() {
    return (
        <div className="rounded-xl border border-border bg-surface-1 shadow-lg">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <div className="flex items-center gap-2 text-2xs font-medium tracking-label text-fg-3 uppercase">
                    <Inbox className="size-3.5" strokeWidth={1.75} />
                    Bandeja de incidentes
                </div>
                <span className="flex items-center gap-1.5 text-2xs text-fg-3">
                    <span className="size-1.5 rounded-full bg-health-ok" />
                    En vivo
                </span>
            </div>
            <div className="divide-y divide-border">
                <div className="bg-severity-critical-bg/40 px-4 py-3.5">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-semibold text-fg-1">
                            Botón de pánico · Unidad 14
                        </span>
                        <SeverityBadge level="critical" />
                    </div>
                    <p className="mt-1.5 text-xs leading-relaxed text-fg-2">
                        Conductor activó pánico en Av. Constitución. Video y
                        ubicación confirmados. Llamando al operador.
                    </p>
                    <div className="mt-2.5 flex items-center gap-2">
                        <StatusPill state="escalated" />
                        <span className="flex items-center gap-1 text-2xs text-fg-3">
                            <PhoneCall className="size-3" strokeWidth={1.75} />
                            Llamada en curso
                        </span>
                    </div>
                </div>
                <div className="px-4 py-3.5 opacity-80">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-medium text-fg-2">
                            Frenado brusco · Unidad 07
                        </span>
                        <SeverityBadge level="low" />
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <StatusPill state="discarded" />
                        <span className="text-2xs text-fg-3">
                            Sin riesgo. Documentado.
                        </span>
                    </div>
                </div>
                <div className="px-4 py-3.5 opacity-70">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-medium text-fg-2">
                            Posible colisión · Unidad 22
                        </span>
                        <SeverityBadge level="medium" />
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <StatusPill state="in-progress" />
                        <span className="text-2xs text-fg-3">
                            Vigilando hasta confirmar.
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* Signal-vs-noise illustration: most events are noise (muted), a few are real
   (severity-colored). Communicates the core value prop visually. */
function NoiseVsSignal() {
    const REAL = new Set([23, 47, 71]);
    const dots = Array.from({ length: 96 }, (_, i) => i);

    return (
        <div className="rounded-xl border border-border bg-surface-1 p-6">
            <div className="grid grid-cols-12 gap-2.5">
                {dots.map((i) => {
                    const real = REAL.has(i);

                    return (
                        <span
                            key={i}
                            className={cn(
                                'aspect-square rounded-full',
                                real
                                    ? 'bg-severity-critical'
                                    : 'bg-border-strong/40',
                            )}
                        />
                    );
                })}
            </div>
            <div className="mt-5 flex items-center justify-between text-xs">
                <span className="flex items-center gap-1.5 text-fg-3">
                    <span className="size-2 rounded-full bg-border-strong/40" />
                    Ruido filtrado
                </span>
                <span className="flex items-center gap-1.5 font-medium text-fg-1">
                    <span className="size-2 rounded-full bg-severity-critical" />
                    Emergencias reales
                </span>
            </div>
        </div>
    );
}

function FeatureBento() {
    return (
        <div className="mt-12 grid gap-px overflow-hidden rounded-xl border border-border bg-border md:grid-cols-3 md:grid-rows-2">
            {/* Large lead cell */}
            <div className="bg-surface-1 p-7 md:col-span-2 md:row-span-1">
                <FeatureIcon icon={Search} />
                <h3 className="mt-4 text-lg font-semibold text-fg-1">
                    Investiga cada alerta por ti
                </h3>
                <p className="mt-2 max-w-md text-sm leading-relaxed text-fg-3">
                    Cruza ubicación, historial del conductor y cámaras a bordo
                    en segundos para entender qué pasó antes de molestarte.
                </p>
                <div className="mt-5 flex flex-wrap gap-2">
                    <MiniChip icon={MapPin} label="Ubicación" />
                    <MiniChip icon={Camera} label="Dashcam" />
                    <MiniChip icon={Activity} label="Historial" />
                </div>
            </div>
            {/* Multichannel */}
            <div className="bg-surface-1 p-7">
                <FeatureIcon icon={PhoneCall} />
                <h3 className="mt-4 text-base font-semibold text-fg-1">
                    Notificación multicanal
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-fg-3">
                    Llamada para emergencias, WhatsApp para lo importante, SMS
                    de respaldo.
                </p>
                <div className="mt-4 flex gap-2 text-fg-2">
                    <PhoneCall className="size-4" strokeWidth={1.75} />
                    <MessageSquare className="size-4" strokeWidth={1.75} />
                    <Radio className="size-4" strokeWidth={1.75} />
                </div>
            </div>
            {/* Inbox with real status pills */}
            <div className="bg-surface-1 p-7">
                <FeatureIcon icon={Inbox} />
                <h3 className="mt-4 text-base font-semibold text-fg-1">
                    Bandeja por prioridad
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-fg-3">
                    Cada incidente clasificado por estado, listo para actuar.
                </p>
                <div className="mt-4 flex flex-wrap gap-1.5">
                    <StatusPill state="escalated" />
                    <StatusPill state="in-progress" />
                    <StatusPill state="resolved" />
                </div>
            </div>
            {/* Natural language */}
            <div className="bg-surface-1 p-7">
                <FeatureIcon icon={MessageSquare} />
                <h3 className="mt-4 text-base font-semibold text-fg-1">
                    Pregunta en lenguaje natural
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-fg-3">
                    "¿Dónde está la unidad 12?" y SAM responde al instante.
                </p>
            </div>
            {/* Continuous watch */}
            <div className="bg-surface-1 p-7">
                <FeatureIcon icon={Eye} />
                <h3 className="mt-4 text-base font-semibold text-fg-1">
                    Vigilancia continua
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-fg-3">
                    Si algo no está claro, SAM sigue mirando hasta resolverlo.
                </p>
            </div>
        </div>
    );
}

function FeatureIcon({ icon: I }: { icon: typeof Search }) {
    return (
        <span className="flex size-10 items-center justify-center rounded-lg bg-primary/12 text-primary">
            <I className="size-5" strokeWidth={1.75} />
        </span>
    );
}

function MiniChip({ icon: I, label }: { icon: typeof MapPin; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-2 px-2.5 py-1 text-2xs font-medium text-fg-2">
            <I className="size-3.5" strokeWidth={1.75} />
            {label}
        </span>
    );
}

const CASES = [
    {
        title: 'Botón de pánico',
        severity: 'critical' as const,
        status: 'escalated' as const,
        body: 'Verifica ubicación y video, confirma con el operador y escala con una llamada en segundos.',
        outcome: 'Llamada inmediata',
        icon: PhoneCall,
    },
    {
        title: 'Frenado brusco repetido',
        severity: 'low' as const,
        status: 'discarded' as const,
        body: 'Revisa el contexto, concluye que no hay riesgo y lo descarta dejando el registro documentado.',
        outcome: 'Sin interrumpir al turno',
        icon: Check,
    },
    {
        title: 'Posible colisión sin confirmar',
        severity: 'medium' as const,
        status: 'in-progress' as const,
        body: 'Cuando la evidencia no es clara, SAM mantiene el caso abierto y sigue vigilando hasta confirmar.',
        outcome: 'Seguimiento activo',
        icon: Eye,
    },
];

function CaseCard({
    title,
    severity,
    status,
    body,
    outcome,
    icon: I,
}: (typeof CASES)[number]) {
    return (
        <div className="flex h-full flex-col rounded-xl border border-border bg-surface-1 p-6 transition-colors hover:border-border-strong">
            <div className="flex items-center justify-between">
                <SeverityBadge level={severity} />
                <StatusPill state={status} />
            </div>
            <h3 className="mt-4 text-base font-semibold text-fg-1">{title}</h3>
            <p className="mt-2 flex-1 text-sm leading-relaxed text-fg-3">
                {body}
            </p>
            <div className="mt-5 flex items-center gap-2 border-t border-border pt-4 text-sm font-medium text-fg-2">
                <I className="size-4 text-primary" strokeWidth={1.75} />
                {outcome}
            </div>
        </div>
    );
}

const BEFORE = [
    'Cientos de avisos sin filtrar cada día',
    'El turno decide qué revisar y qué ignorar',
    'Emergencias que se descubren tarde',
    'Equipo de monitoreo creciendo en costo',
];

const AFTER = [
    'Solo las alertas que de verdad importan',
    'Criterio consistente las 24 horas',
    'Emergencias verificadas y escaladas al instante',
    'El ruido descartado queda documentado',
];

function BeforeAfter() {
    return (
        <div className="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2">
            <div className="bg-surface-1 p-6">
                <h3 className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                    Sin SAM
                </h3>
                <ul className="mt-4 space-y-3">
                    {BEFORE.map((b) => (
                        <li
                            key={b}
                            className="flex gap-2.5 text-sm leading-relaxed text-fg-3"
                        >
                            <span className="mt-2 size-1.5 shrink-0 rounded-full bg-border-strong" />
                            {b}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="bg-surface-1 p-6">
                <h3 className="text-2xs font-semibold tracking-caps text-primary uppercase">
                    Con SAM
                </h3>
                <ul className="mt-4 space-y-3">
                    {AFTER.map((a) => (
                        <li
                            key={a}
                            className="flex gap-2.5 text-sm leading-relaxed text-fg-1"
                        >
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-health-ok"
                                strokeWidth={2}
                            />
                            {a}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function FooterCol({
    title,
    links,
}: {
    title: string;
    links: { label: string; href?: string }[];
}) {
    return (
        <div>
            <h3 className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                {title}
            </h3>
            <ul className="mt-4 space-y-2.5">
                {links.map((link) => (
                    <li key={link.label}>
                        {link.href ? (
                            <a
                                href={link.href}
                                className="text-sm text-fg-2 transition-colors hover:text-fg-1"
                            >
                                {link.label}
                            </a>
                        ) : (
                            <span className="text-sm text-fg-3">
                                {link.label}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
