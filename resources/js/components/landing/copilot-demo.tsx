import { ArrowUp, Check, LoaderCircle } from 'lucide-react';
import {
    AnimatePresence,
    motion,
    useInView,
    useReducedMotion,
} from 'motion/react';
import { Fragment, useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { CopilotCard } from './copilot-demo-cards';
import type { CardKind } from './copilot-demo-cards';

type ScriptId = 'night' | 'fuel' | 'compare' | 't118' | 'other';

type Script = {
    question: string;
    steps: string[];
    answer: string;
    card?: CardKind;
    followups: ScriptId[];
};

/* Conversación de ejemplo con una flota ficticia. Las respuestas imitan lo
   que el Copiloto hace con datos reales: consulta, cruza y explica. */
const SCRIPTS: Record<ScriptId, Script> = {
    night: {
        question: '¿Qué pasó anoche?',
        steps: [
            'Revisando los eventos de 20:00 a 06:00',
            'Revisando incidentes abiertos',
        ],
        answer: 'Fue una noche tranquila, con una excepción. Hubo **2 botones de pánico**: el de la T-214 fue un error del operador y ya está cerrado; el de la **T-600 sigue abierto** y tu jefe de turno ya lo tomó. También hubo 14 excesos de velocidad, 9 de ellos de la T-118.',
        card: 'night',
        followups: ['t118', 'fuel'],
    },
    t118: {
        question: '¿Qué pasa con la T-118?',
        steps: ['Revisando los excesos de velocidad de la T-118'],
        answer: 'Los 9 excesos fueron en la Carr. 57, entre las 23:00 y la 01:00, con un máximo de **104 km/h en zona de 80**. Es el mismo conductor, R. Salinas, que ya tuvo 3 avisos este mes. Te sugiero una plática de seguridad.',
        card: 'speed',
        followups: ['fuel', 'night'],
    },
    fuel: {
        question: '¿Qué unidad gastó más diésel esta semana y por qué?',
        steps: [
            'Comparando el combustible de la flota',
            'Revisando la actividad de la T-555',
        ],
        answer: 'La **T-555 consumió 412 litros**, 38 % más que el promedio de la flota. La causa probable son **9 horas con el motor encendido sin moverse** el martes, en el patio de Tultitlán. Te sugiero revisarlo con su conductor, J. Pérez.',
        card: 'fuel',
        followups: ['compare', 'night'],
    },
    compare: {
        question: '¿Y comparado con la T-600?',
        steps: ['Revisando el combustible de la T-600'],
        answer: 'La T-600 consumió **290 litros, 30 % menos** que la T-555, y solo estuvo 1 hora encendida sin avanzar en toda la semana.',
        card: 'compare',
        followups: ['t118', 'night'],
    },
    other: {
        question: '',
        steps: ['Buscando en la flota de ejemplo'],
        answer: 'En esta demostración solo tengo los datos de una flota de ejemplo. Con tu flota conectada puedes preguntarme lo que quieras: **dónde está una unidad, qué pasó en un turno o quién necesita atención**.',
        followups: ['night', 'fuel'],
    },
};

const STARTERS: ScriptId[] = ['night', 'fuel', 't118'];

type Turn = {
    id: number;
    script: ScriptId;
    question: string;
    stepsDone: number;
    words: number;
    done: boolean;
};

function matchScript(text: string): ScriptId {
    const q = text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

    if (/t-?600|compar/.test(q)) {
        return 'compare';
    }

    if (/diesel|combustible|gasto|gasolina|litros/.test(q)) {
        return 'fuel';
    }

    if (/t-?118|velocidad/.test(q)) {
        return 't118';
    }

    if (/anoche|noche|turno|paso/.test(q)) {
        return 'night';
    }

    return 'other';
}

const EASE = [0.16, 1, 0.3, 1] as const;

/**
 * La conversación del hero. Se reproduce sola una vez al entrar en pantalla
 * y después responde a los chips o a lo que el visitante escriba. Con
 * reduced-motion cada respuesta aparece completa, sin tipeo.
 */
export function CopilotDemo({ className }: { className?: string }) {
    const reduce = useReducedMotion() ?? false;
    const rootRef = useRef<HTMLDivElement>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const inView = useInView(rootRef, { once: true, amount: 0.4 });
    const [turns, setTurns] = useState<Turn[]>([]);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const runToken = useRef(0);
    const nextId = useRef(1);

    const sleep = (ms: number, token: number) =>
        new Promise<boolean>((resolve) => {
            window.setTimeout(
                () => resolve(runToken.current === token),
                reduce ? 0 : ms,
            );
        });

    const ask = useCallback(
        async (script: ScriptId, typedQuestion?: string) => {
            const token = ++runToken.current;
            const question = typedQuestion ?? SCRIPTS[script].question;
            setBusy(true);

            // El visitante "escribe" la pregunta en el compositor.
            if (!typedQuestion && !reduce) {
                for (let i = 1; i <= question.length; i++) {
                    setDraft(question.slice(0, i));

                    if (!(await sleep(28, token))) {
                        return;
                    }
                }

                if (!(await sleep(260, token))) {
                    return;
                }
            }

            setDraft('');
            const id = nextId.current++;
            const total = SCRIPTS[script].answer.split(' ').length;
            setTurns((prev) => [
                ...prev.slice(-2),
                {
                    id,
                    script,
                    question,
                    stepsDone: reduce ? 99 : 0,
                    words: reduce ? total : 0,
                    done: reduce,
                },
            ]);

            if (reduce) {
                setBusy(false);

                return;
            }

            const update = (patch: Partial<Turn>) =>
                setTurns((prev) =>
                    prev.map((t) => (t.id === id ? { ...t, ...patch } : t)),
                );

            for (let s = 1; s <= SCRIPTS[script].steps.length; s++) {
                if (!(await sleep(s === 1 ? 700 : 950, token))) {
                    return;
                }

                update({ stepsDone: s });
            }

            if (!(await sleep(350, token))) {
                return;
            }

            for (let w = 1; w <= total; w++) {
                update({ words: w });

                if (!(await sleep(38, token))) {
                    return;
                }
            }

            update({ done: true });
            setBusy(false);
        },
        // sleep reads refs only; reduce is the one real dependency.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [reduce],
    );

    useEffect(() => {
        if (inView && turns.length === 0) {
            void ask('night');
        }
    }, [inView, turns.length, ask]);

    useEffect(() => () => void runToken.current++, []);

    // Sigue la respuesta hacia abajo, pero nunca deja la pregunta del turno
    // actual fuera de vista por arriba.
    useEffect(() => {
        const el = scrollRef.current;
        const current = el?.lastElementChild as HTMLElement | null;

        if (el && current) {
            el.scrollTo({
                top: Math.min(
                    current.offsetTop - 16,
                    el.scrollHeight - el.clientHeight,
                ),
                behavior: reduce ? 'auto' : 'smooth',
            });
        }
    }, [turns, reduce]);

    const last = turns.at(-1);
    const chips: ScriptId[] =
        last && last.done ? SCRIPTS[last.script].followups : STARTERS;

    const submit = (e: { preventDefault: () => void }) => {
        e.preventDefault();
        const text = draft.trim();

        if (!text || busy) {
            return;
        }

        void ask(matchScript(text), text);
    };

    return (
        <div
            ref={rootRef}
            className={cn(
                'theme-light flex h-[36rem] flex-col overflow-hidden rounded-[1.25rem] border border-brand-line bg-white shadow-[0_1px_0_rgba(3,24,38,0.04),0_30px_60px_-20px_rgba(0,94,125,0.28)] lg:h-[40rem]',
                className,
            )}
        >
            <div className="flex items-center gap-3 border-b border-brand-line px-5 py-3.5">
                <img
                    src="/images/brand/sam-emblem.png"
                    alt=""
                    className="size-8 rounded-lg"
                />
                <div className="min-w-0">
                    <p className="text-base font-semibold text-brand-ink">
                        Copiloto SAM
                    </p>
                    <p className="truncate text-xs text-brand-ink-3">
                        Flota de ejemplo, Transportes Regio del Norte
                    </p>
                </div>
            </div>

            <div
                ref={scrollRef}
                className="scrollbar-none relative flex-1 space-y-6 overflow-y-auto px-5 py-5"
                aria-live="polite"
            >
                {turns.length === 0 && (
                    <p className="pt-24 text-center text-base text-brand-ink-3">
                        Pregúntale algo a tu flota.
                    </p>
                )}
                {turns.map((turn) => (
                    <TurnView key={turn.id} turn={turn} reduce={reduce} />
                ))}
            </div>

            <div className="border-t border-brand-line bg-brand-paper/60 px-4 pt-3 pb-4">
                <div className="scrollbar-none mb-3 flex gap-2 overflow-x-auto">
                    <AnimatePresence mode="popLayout" initial={false}>
                        {!busy &&
                            chips.map((id, i) => (
                                <motion.button
                                    key={`${last?.id ?? 0}-${id}`}
                                    type="button"
                                    layout
                                    initial={
                                        reduce ? false : { opacity: 0, y: 8 }
                                    }
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.96 }}
                                    transition={{
                                        duration: 0.4,
                                        delay: i * 0.06,
                                        ease: EASE,
                                    }}
                                    onClick={() => void ask(id)}
                                    className="shrink-0 rounded-full border border-brand-line bg-white px-3.5 py-1.5 text-sm whitespace-nowrap text-brand-petrol transition-colors hover:border-brand-teal hover:bg-brand-mist focus-visible:outline-2 focus-visible:outline-brand-teal active:scale-[0.98]"
                                >
                                    {SCRIPTS[id].question}
                                </motion.button>
                            ))}
                    </AnimatePresence>
                </div>
                <form
                    onSubmit={submit}
                    className="flex items-center gap-2 rounded-xl border border-brand-line bg-white py-1.5 pr-1.5 pl-4 focus-within:border-brand-teal"
                >
                    <label htmlFor="copilot-demo-input" className="sr-only">
                        Pregúntale a SAM
                    </label>
                    <input
                        id="copilot-demo-input"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        readOnly={busy}
                        placeholder="Pregúntale a SAM sobre tu flota"
                        autoComplete="off"
                        className="min-w-0 flex-1 bg-transparent text-md text-brand-ink outline-none placeholder:text-brand-ink-3"
                    />
                    <button
                        type="submit"
                        disabled={busy || !draft.trim()}
                        aria-label="Enviar pregunta"
                        className="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-teal text-white transition-[background-color,transform] hover:bg-brand-petrol active:scale-95 disabled:bg-brand-line disabled:text-brand-ink-3"
                    >
                        <ArrowUp className="size-4" strokeWidth={2} />
                    </button>
                </form>
            </div>
        </div>
    );
}

function TurnView({ turn, reduce }: { turn: Turn; reduce: boolean }) {
    const script = SCRIPTS[turn.script];
    const words = script.answer.split(' ');
    const thinking = turn.stepsDone < script.steps.length;
    const shown = words.slice(0, turn.words).join(' ');

    return (
        <div className="space-y-4">
            <motion.p
                initial={reduce ? false : { opacity: 0, y: 10, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ duration: 0.45, ease: EASE }}
                className="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-brand-petrol px-4 py-2.5 text-md text-white"
            >
                {turn.question}
            </motion.p>

            <ul className="space-y-1.5">
                {script.steps.map((step, i) =>
                    i <= turn.stepsDone ? (
                        <motion.li
                            key={step}
                            initial={reduce ? false : { opacity: 0, x: -6 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ duration: 0.35, ease: EASE }}
                            className={cn(
                                'flex items-center gap-2 text-sm transition-colors',
                                i < turn.stepsDone
                                    ? 'text-brand-ink-3'
                                    : 'text-brand-petrol',
                            )}
                        >
                            {i < turn.stepsDone ? (
                                <Check
                                    className="size-3.5 text-brand-aqua"
                                    strokeWidth={2.5}
                                />
                            ) : (
                                <LoaderCircle
                                    className="size-3.5 animate-spin"
                                    strokeWidth={2}
                                />
                            )}
                            {step}
                            {i === turn.stepsDone && '…'}
                        </motion.li>
                    ) : null,
                )}
            </ul>

            {!thinking && (
                <div className="space-y-4">
                    <p className="max-w-[60ch] text-md leading-relaxed text-brand-ink-2">
                        <RichText text={shown} />
                        {!turn.done && (
                            <span className="ml-0.5 inline-block h-4 w-[2px] translate-y-0.5 animate-pulse bg-brand-teal" />
                        )}
                    </p>
                    <AnimatePresence>
                        {turn.done && script.card && (
                            <motion.div
                                initial={
                                    reduce
                                        ? false
                                        : { opacity: 0, y: 14, scale: 0.98 }
                                }
                                animate={{ opacity: 1, y: 0, scale: 1 }}
                                transition={{
                                    type: 'spring',
                                    stiffness: 180,
                                    damping: 22,
                                }}
                            >
                                <CopilotCard kind={script.card} />
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            )}
        </div>
    );
}

/* `**negritas**` sobre texto parcial: un par abierto se muestra ya en negrita. */
function RichText({ text }: { text: string }) {
    const parts = text.split('**');

    return (
        <>
            {parts.map((part, i) =>
                i % 2 === 1 ? (
                    <strong key={i} className="font-semibold text-brand-ink">
                        {part}
                    </strong>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}
