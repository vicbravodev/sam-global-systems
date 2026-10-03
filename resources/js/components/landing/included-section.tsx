import {
    BellRing,
    FileBarChart,
    Gauge,
    Map as MapIcon,
    Plug,
    ShieldCheck,
} from 'lucide-react';
import { motion } from 'motion/react';
import { EASE } from '@/components/landing/lib';

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

/* Incluido desde el primer día. */
export function IncludedSection() {
    return (
        <section className="border-t border-brand-line">
            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-14 px-5 py-24 sm:px-8 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20 lg:py-32">
                <h2 className="max-w-md text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                    Todo lo demás viene incluido desde el primer día.
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
    );
}
