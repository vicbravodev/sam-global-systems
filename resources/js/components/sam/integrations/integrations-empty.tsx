import { Plug, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';

const STEPS = [
    {
        title: 'Crea una clave en tu proveedor',
        body: 'En Samsara: Ajustes → API Tokens → Add an API Token. Te guiamos paso a paso.',
    },
    {
        title: 'Pégala en SAM',
        body: 'Se guarda cifrada. SAM prueba que funcione y trae tu flota.',
    },
    {
        title: 'Elige qué unidades vigilar',
        body: 'Tus unidades llegan a Flota; enciende las que quieras monitorear.',
    },
];

interface Props {
    canManage: boolean;
    onConnect: () => void;
}

/** First-run state: what an integration is for and the three steps to one. */
export function IntegrationsEmpty({ canManage, onConnect }: Props) {
    return (
        <div className="mx-auto flex max-w-2xl flex-col items-center gap-6 px-2 py-12 text-center">
            <div className="flex flex-col items-center gap-2">
                <div className="mb-1 grid size-11 place-items-center rounded-lg bg-surface-2 text-fg-3">
                    <Plug className="size-5" aria-hidden />
                </div>
                <h2 className="text-lg font-semibold text-fg-1">
                    Conecta tu proveedor de rastreo
                </h2>
                <p className="max-w-md text-sm text-fg-3">
                    SAM necesita leer los datos de tus unidades (ubicación,
                    eventos de seguridad, alertas) para vigilar tu flota. Se
                    configura una sola vez.
                </p>
            </div>

            <ol className="grid w-full gap-3 text-left sm:grid-cols-3">
                {STEPS.map((step, index) => (
                    <li
                        key={step.title}
                        className="flex flex-col gap-1.5 rounded-lg border border-border bg-surface-1 p-4"
                    >
                        <span className="grid size-6 place-items-center rounded-full border border-border bg-surface-2 text-2xs font-semibold text-fg-2 tabular-nums">
                            {index + 1}
                        </span>
                        <span className="text-sm font-semibold text-fg-1">
                            {step.title}
                        </span>
                        <span className="text-xs leading-relaxed text-fg-3">
                            {step.body}
                        </span>
                    </li>
                ))}
            </ol>

            {canManage ? (
                <Button onClick={onConnect}>
                    <Plus size={14} /> Conectar proveedor
                </Button>
            ) : (
                <p className="text-xs text-fg-3">
                    Pide a un administrador de tu cuenta que conecte el
                    proveedor.
                </p>
            )}
        </div>
    );
}
