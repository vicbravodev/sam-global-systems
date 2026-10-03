import { cn } from '@/lib/utils';

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

/* Qué le puedes preguntar. */
export function QuestionsSection() {
    return (
        <section className="overflow-hidden border-y border-brand-line bg-white py-24 lg:py-28">
            <div className="mx-auto max-w-7xl px-5 sm:px-8">
                <h2 className="max-w-3xl text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                    Pregúntale lo que le preguntarías a tu equipo.
                </h2>
                <p className="mt-5 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                    Responde con los datos de tu flota, en español y en
                    segundos. Si algo no lo sabe, te lo dice.
                </p>
            </div>
            <QuestionDrift />
        </section>
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
