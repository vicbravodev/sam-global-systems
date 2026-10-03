import { Head, Link, usePage } from '@inertiajs/react';
import {
    BellRing,
    Ear,
    FileBarChart,
    Gauge,
    Map as MapIcon,
    PhoneCall,
    Plug,
    ScanSearch,
    ShieldCheck,
} from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import type { ReactNode } from 'react';
import { AlertPhone } from '@/components/landing/alert-phone';
import { CabinVision } from '@/components/landing/cabin-vision';
import { CopilotDemo } from '@/components/landing/copilot-demo';
import { MonitorInbox } from '@/components/landing/monitor-inbox';
import { NightWatch } from '@/components/landing/night-watch';
import { TheftGuard } from '@/components/landing/theft-guard';
import { cn } from '@/lib/utils';
import { dashboard, home, login } from '@/routes';

const CONTACT_EMAIL = 'contacto@samglobaltechnologies.com';
const CONTACT_PHONE = '+52 81 1765 8890';
const DEMO_HREF = `mailto:${CONTACT_EMAIL}?subject=Solicitud%20de%20demo%20SAM`;
const EASE = [0.16, 1, 0.3, 1] as const;

const NAV_LINKS = [
    { href: '#copiloto', label: 'Copiloto' },
    { href: '#guardia', label: 'Guardia nocturna' },
    { href: '#equipo', label: 'Tu equipo' },
    { href: '#camaras', label: 'Cámaras' },
    { href: '#avisos', label: 'Avisos' },
];

/* Lo que viene incluido y no necesita demo interactiva. */
const READY = [
    {
        icon: Plug,
        title: 'Conecta tu Samsara en minutos',
        body: 'Tus unidades y conductores se dan de alta solos, y SAM te avisa si la conexión deja de recibir.',
    },
    {
        icon: ShieldCheck,
        title: 'Protocolo listo de fábrica',
        body: 'Pánico, escalación en tres niveles y tiempos de respuesta ya configurados. Ajústalos cuando quieras.',
    },
    {
        icon: MapIcon,
        title: 'Toda tu flota en un mapa en vivo',
        body: 'Cada unidad con su rumbo y su estado, y el caso abierto a un clic.',
    },
    {
        icon: Gauge,
        title: 'Riesgo de cada conductor, día a día',
        body: 'Una calificación de 0 a 100 y un aviso cuando alguien empieza a manejar peor, antes del accidente.',
    },
    {
        icon: FileBarChart,
        title: 'Reportes para dirección',
        body: 'Operación diaria, cumplimiento de tiempos de respuesta y riesgo por unidad, en PDF o Excel.',
    },
    {
        icon: BellRing,
        title: 'Alertas imposibles de ignorar',
        body: 'Una emergencia en pantalla suena, parpadea y no se va hasta que alguien la atiende.',
    },
];

const STEPS = [
    {
        icon: Ear,
        title: 'Escucha',
        body: 'Recibe cada evento de tus unidades en el momento en que pasa.',
    },
    {
        icon: ScanSearch,
        title: 'Investiga',
        body: 'Revisa ubicación, video de las cámaras y el historial del conductor.',
    },
    {
        icon: PhoneCall,
        title: 'Confirma',
        body: 'Si hace falta, llama al operador antes de molestar a nadie más.',
    },
    {
        icon: BellRing,
        title: 'Avisa',
        body: 'Solo a la persona correcta, con el caso ya armado. Lo demás queda registrado.',
    },
];

const QUESTIONS = [
    '¿Qué pasó anoche?',
    '¿Qué unidad gastó más diésel esta semana y por qué?',
    '¿Hay alguna unidad sin señal ahora mismo?',
    '¿Qué incidentes siguen abiertos?',
    '¿Qué conductores acumulan más excesos de velocidad este mes?',
    '¿Cuántas horas estuvo detenida la T-555 el martes?',
    'Compárame la T-555 con la T-600',
    '¿Cuántos kilómetros hizo la flota esta semana?',
    '¿Qué paradas no programadas hubo hoy?',
    '¿Cuántos pánicos tuvimos este mes y cómo se resolvieron?',
    '¿Qué ruta hizo la T-118 ayer en la noche?',
    '¿Qué unidad tuvo más incidentes esta semana?',
];

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : home();

    return (
        <>
            <Head title="SAM · Tu flota vigilada día y noche">
                <meta
                    name="description"
                    content="SAM vigila tus unidades día y noche, investiga cada alerta y te responde en español con los datos de tu operación. Solo te llama cuando de verdad importa."
                />
            </Head>

            <div className="theme-light min-h-dvh scroll-smooth bg-brand-paper text-brand-ink antialiased [color-scheme:light]">
                <Header
                    authed={Boolean(auth.user)}
                    dashboardUrl={dashboardUrl}
                />

                <main>
                    {/* ---------- Hero: el Copiloto en vivo ---------- */}
                    <section
                        id="copiloto"
                        className="relative scroll-mt-16 overflow-hidden"
                    >
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute top-0 right-0 h-full w-[60%] bg-[radial-gradient(60%_55%_at_60%_45%,var(--color-brand-mist),transparent)]"
                        />
                        <div className="relative mx-auto grid max-w-7xl grid-cols-1 items-center gap-12 px-5 pt-12 pb-20 sm:px-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-16 lg:pt-16 lg:pb-28">
                            <HeroCopy authed={Boolean(auth.user)} />
                            <motion.div
                                className="min-w-0"
                                initial={{ opacity: 0, y: 24 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{
                                    duration: 0.9,
                                    delay: 0.25,
                                    ease: EASE,
                                }}
                            >
                                <CopilotDemo />
                            </motion.div>
                        </div>
                    </section>

                    {/* ---------- Guardia nocturna ---------- */}
                    <section
                        id="guardia"
                        className="scroll-mt-16 bg-brand-night text-white"
                    >
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <div className="max-w-2xl">
                                <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                                    Mientras duermes, SAM hace la guardia.
                                </h2>
                                <p className="mt-6 max-w-xl text-lg leading-relaxed text-night-muted">
                                    Una noche de ejemplo de una flota de 48
                                    unidades: 147 eventos revisados y una sola
                                    llamada, la que importaba. Arrastra la barra
                                    para recorrerla.
                                </p>
                            </div>
                            <div className="mt-14">
                                <NightWatch />
                            </div>

                            <div
                                id="antirrobo"
                                className="mt-28 scroll-mt-24 border-t border-white/10 pt-20"
                            >
                                <h2 className="max-w-2xl text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                                    Y si una unidad se comporta raro, SAM lo
                                    nota.
                                </h2>
                                <p className="mt-5 max-w-xl text-lg leading-relaxed text-night-muted">
                                    No espera a que alguien reporte un robo.
                                    Vigila las señales que lo anticipan y,
                                    cuando la unidad vuelve a la normalidad,
                                    cierra el caso solo.
                                </p>
                                <div className="mt-14">
                                    <TheftGuard />
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* ---------- El monitorista: casos, no alertas ---------- */}
                    <section id="equipo" className="scroll-mt-16">
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <div className="max-w-3xl">
                                <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                                    Tu equipo atiende casos, no alertas.
                                </h2>
                                <p className="mt-6 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                                    SAM hace la primera revisión y le entrega a
                                    tu monitorista solo lo que importa: ordenado
                                    por urgencia, con el tiempo de respuesta
                                    corriendo y la evidencia ya reunida.
                                    Pruébalo: toma el caso del pánico.
                                </p>
                            </div>
                            <div className="mt-14">
                                <MonitorInbox />
                            </div>
                            <ul className="mt-12 grid gap-x-12 gap-y-6 text-base leading-relaxed text-brand-ink-2 md:grid-cols-3">
                                <li>
                                    <span className="font-semibold text-brand-ink">
                                        Nadie trabaja el mismo caso dos veces.
                                    </span>{' '}
                                    Quien lo toma se lo queda y los demás lo
                                    ven.
                                </li>
                                <li>
                                    <span className="font-semibold text-brand-ink">
                                        Los tiempos de respuesta los pones tú.
                                    </span>{' '}
                                    Por prioridad, y SAM escala si se vencen.
                                </li>
                                <li>
                                    <span className="font-semibold text-brand-ink">
                                        Tu criterio mejora a SAM.
                                    </span>{' '}
                                    Cuando tu equipo corrige un veredicto, la IA
                                    lo toma en cuenta.
                                </li>
                            </ul>
                        </div>
                    </section>

                    {/* ---------- Visión: SAM mira las cámaras ---------- */}
                    <section
                        id="camaras"
                        className="scroll-mt-16 border-y border-brand-line bg-white"
                    >
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <div className="max-w-3xl">
                                <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                                    SAM revisa las cámaras antes que tú.
                                </h2>
                                <p className="mt-6 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                                    Pide las fotos y el video de los segundos
                                    alrededor de cada evento, mira dentro y
                                    fuera de la cabina y te lo resume en una
                                    frase.
                                </p>
                            </div>
                            <div className="mt-14">
                                <CabinVision />
                            </div>
                        </div>
                    </section>

                    {/* ---------- Cómo decide ---------- */}
                    <section id="como-decide" className="scroll-mt-16">
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <h2 className="max-w-3xl text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                                Revisa cada alerta como lo haría tu mejor
                                monitorista.
                            </h2>
                            <DecisionPath />
                            <p className="mt-16 max-w-2xl border-l-2 border-brand-alert pl-5 text-lg leading-relaxed text-brand-ink-2">
                                Un botón de pánico, un choque o una volcadura no
                                esperan a nadie:{' '}
                                <span className="font-semibold text-brand-ink">
                                    se abren como emergencia en el mismo momento
                                </span>
                                , mientras SAM sigue reuniendo la evidencia.
                            </p>
                        </div>
                    </section>

                    {/* ---------- Qué le puedes preguntar ---------- */}
                    <section className="overflow-hidden border-y border-brand-line bg-white py-24 lg:py-28">
                        <div className="mx-auto max-w-7xl px-5 sm:px-8">
                            <h2 className="max-w-3xl text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                                Pregúntale lo que le preguntarías a tu equipo.
                            </h2>
                            <p className="mt-5 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                                Responde con los datos de tu flota, en español y
                                en segundos. Si algo no lo sabe, te lo dice.
                            </p>
                        </div>
                        <QuestionDrift />
                    </section>

                    {/* ---------- Avisos ---------- */}
                    <section id="avisos" className="scroll-mt-16">
                        <div className="mx-auto grid max-w-7xl grid-cols-1 items-center gap-16 px-5 py-24 sm:px-8 lg:grid-cols-[1fr_0.8fr] lg:py-32">
                            <div>
                                <h2 className="max-w-xl text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                                    Te avisa por donde sí vas a contestar.
                                </h2>
                                <dl className="mt-12 grid max-w-lg gap-8">
                                    <Notice title="Primero confirma con el operador.">
                                        SAM le llama y él marca 1 si la
                                        emergencia es real o 2 si fue un error.
                                        Si nadie contesta, escala.
                                    </Notice>
                                    <Notice title="Una emergencia te llama por teléfono.">
                                        A cualquier hora, y lo importante te
                                        llega por WhatsApp con lo que SAM ya
                                        verificó.
                                    </Notice>
                                    <Notice title="Respondes sin abrir nada.">
                                        Contesta el mensaje con SI, NO o ESC
                                        para confirmar, descartar o escalar.
                                    </Notice>
                                    <Notice title="Si un canal falla, usa el siguiente.">
                                        Llamada, WhatsApp o SMS, y confirma que
                                        el aviso de verdad llegó.
                                    </Notice>
                                </dl>
                            </div>
                            <AlertPhone />
                        </div>
                    </section>

                    {/* ---------- Incluido desde el primer día ---------- */}
                    <section className="border-t border-brand-line">
                        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-14 px-5 py-24 sm:px-8 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20 lg:py-32">
                            <h2 className="max-w-md text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                                Todo lo demás viene incluido desde el primer
                                día.
                            </h2>
                            <ul className="grid gap-x-12 gap-y-10 sm:grid-cols-2">
                                {READY.map((item, i) => (
                                    <motion.li
                                        key={item.title}
                                        initial={{ opacity: 0, y: 10 }}
                                        whileInView={{ opacity: 1, y: 0 }}
                                        viewport={{ once: true, amount: 0.6 }}
                                        transition={{
                                            duration: 0.5,
                                            delay: (i % 2) * 0.08,
                                            ease: EASE,
                                        }}
                                    >
                                        <item.icon
                                            className="size-5 text-brand-teal"
                                            strokeWidth={1.75}
                                        />
                                        <h3 className="mt-3 text-lg font-semibold tracking-tight">
                                            {item.title}
                                        </h3>
                                        <p className="mt-1.5 text-base leading-relaxed text-brand-ink-2">
                                            {item.body}
                                        </p>
                                    </motion.li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    {/* ---------- CTA ---------- */}
                    <section className="bg-brand-mist">
                        <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                            <h2 className="max-w-4xl text-4xl font-semibold tracking-poster text-balance lg:text-6xl">
                                Tu próxima emergencia no debería depender de
                                quién esté despierto.
                            </h2>
                            <div className="mt-12 flex flex-col gap-8 sm:flex-row sm:items-center sm:gap-12">
                                <PrimaryButton href={DEMO_HREF} large>
                                    Pedir una demo
                                </PrimaryButton>
                                <p className="max-w-sm text-base leading-relaxed text-brand-ink-2">
                                    Te la mostramos con las alertas de tu propia
                                    operación, en una llamada de 30 minutos.
                                </p>
                            </div>
                        </div>
                    </section>
                </main>

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
                                , Sistema Automatizado de Monitoreo. Nuevo León,
                                México.
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
                            <Link
                                href={login()}
                                className="hover:text-brand-ink"
                            >
                                Entrar
                            </Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}

/* ============================ Piezas ============================ */

function Header({
    authed,
    dashboardUrl,
}: {
    authed: boolean;
    dashboardUrl: ReturnType<typeof dashboard> | string;
}) {
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

function HeroCopy({ authed }: { authed: boolean }) {
    const reduce = useReducedMotion();
    const lines = ['Pregúntale', 'a tu flota.'];

    return (
        <div className="min-w-0">
            <h1 className="text-5xl font-semibold tracking-poster sm:text-6xl">
                {lines.map((line, i) => (
                    <span key={line} className="block overflow-hidden pb-2">
                        <motion.span
                            className="block"
                            initial={reduce ? false : { y: '105%' }}
                            animate={{ y: 0 }}
                            transition={{
                                duration: 0.9,
                                delay: 0.05 + i * 0.1,
                                ease: EASE,
                            }}
                        >
                            {line}
                        </motion.span>
                    </span>
                ))}
            </h1>
            <motion.p
                className="mt-6 max-w-md text-lg leading-relaxed text-brand-ink-2"
                initial={reduce ? false : { opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.7, delay: 0.3, ease: EASE }}
            >
                SAM vigila tus unidades día y noche, investiga cada alerta y te
                responde con los datos reales de tu operación.
            </motion.p>
            <motion.div
                className="mt-10 flex flex-wrap items-center gap-6"
                initial={reduce ? false : { opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.7, delay: 0.4, ease: EASE }}
            >
                {!authed && (
                    <PrimaryButton href={DEMO_HREF} large>
                        Pedir una demo
                    </PrimaryButton>
                )}
                <a
                    href="#guardia"
                    className="text-md font-medium text-brand-petrol underline decoration-brand-teal/40 underline-offset-4 transition-colors hover:decoration-brand-teal"
                >
                    Ver una noche con SAM
                </a>
            </motion.div>
        </div>
    );
}

function PrimaryButton({
    href,
    children,
    large,
    inertia,
}: {
    href: ReturnType<typeof dashboard> | string;
    children: ReactNode;
    large?: boolean;
    inertia?: boolean;
}) {
    const className = cn(
        'inline-flex items-center justify-center rounded-full bg-brand-teal font-medium whitespace-nowrap text-white shadow-[0_8px_20px_-8px_rgba(0,128,159,0.6)] transition-[background-color,transform] duration-200 hover:bg-brand-petrol focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal active:scale-[0.97]',
        large ? 'h-12 px-7 text-md' : 'h-10 px-5 text-base',
    );

    return inertia ? (
        <Link href={href} className={className}>
            {children}
        </Link>
    ) : (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
        >
            {children}
        </a>
    );
}

/* Los cuatro pasos son una secuencia real, así que van numerados y unidos por
   una ruta que se dibuja al entrar en pantalla. */
function DecisionPath() {
    const reduce = useReducedMotion();

    return (
        <div className="relative mt-16">
            <svg
                aria-hidden="true"
                viewBox="0 0 1000 40"
                preserveAspectRatio="none"
                className="absolute top-6 left-[12.5%] hidden h-10 w-[75%] -translate-y-1/2 lg:block"
            >
                <motion.path
                    d="M0,20 C120,4 210,36 333,20 S546,4 666,20 S880,36 1000,20"
                    fill="none"
                    stroke="var(--color-brand-teal)"
                    strokeWidth={2}
                    strokeDasharray="1 0"
                    vectorEffect="non-scaling-stroke"
                    initial={reduce ? false : { pathLength: 0 }}
                    whileInView={{ pathLength: 1 }}
                    viewport={{ once: true, amount: 0.6 }}
                    transition={{ duration: 1.6, ease: EASE }}
                />
            </svg>
            <ol className="relative grid gap-12 sm:grid-cols-2 lg:grid-cols-4 lg:gap-8">
                {STEPS.map((step, i) => (
                    <motion.li
                        key={step.title}
                        className="lg:text-center"
                        initial={reduce ? false : { opacity: 0, y: 16 }}
                        whileInView={{ opacity: 1, y: 0 }}
                        viewport={{ once: true, amount: 0.6 }}
                        transition={{
                            duration: 0.6,
                            delay: 0.15 + i * 0.28,
                            ease: EASE,
                        }}
                    >
                        <span className="relative inline-grid size-12 place-items-center rounded-full border border-brand-line bg-white text-brand-teal shadow-sm">
                            <step.icon className="size-5" strokeWidth={1.75} />
                            <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-brand-ink text-2xs font-semibold text-white">
                                {i + 1}
                            </span>
                        </span>
                        <h3 className="mt-5 text-xl font-semibold tracking-tight">
                            {step.title}
                        </h3>
                        <p className="mt-2 text-base leading-relaxed text-brand-ink-2 lg:mx-auto lg:max-w-[16rem]">
                            {step.body}
                        </p>
                    </motion.li>
                ))}
            </ol>
        </div>
    );
}

/* Única marquesina de la página: dos filas en sentidos opuestos, para dar
   la sensación de amplitud sin pedir atención a cada pregunta. */
function QuestionDrift() {
    const half = Math.ceil(QUESTIONS.length / 2);
    const rows = [QUESTIONS.slice(0, half), QUESTIONS.slice(half)];

    return (
        <div className="sam-marquee-wrap mt-14 space-y-3 [mask-image:linear-gradient(to_right,transparent,black_6%,black_94%,transparent)]">
            {rows.map((row, r) => (
                <ul
                    key={r}
                    className={cn(
                        'sam-marquee flex w-max gap-3 motion-reduce:w-auto motion-reduce:flex-wrap motion-reduce:px-5',
                        r === 1 && '[animation-direction:reverse]',
                    )}
                >
                    {[...row, ...row].map((q, i) => (
                        <li
                            key={`${q}-${i}`}
                            aria-hidden={i >= row.length}
                            className="rounded-full border border-brand-line bg-brand-paper px-5 py-3 text-md whitespace-nowrap text-brand-ink-2 motion-reduce:[&:nth-child(n+7)]:hidden"
                        >
                            {q}
                        </li>
                    ))}
                </ul>
            ))}
        </div>
    );
}

function Notice({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-lg font-semibold">{title}</dt>
            <dd className="mt-1.5 text-base leading-relaxed text-brand-ink-2">
                {children}
            </dd>
        </div>
    );
}
