import { CheckCircle2, Circle } from 'lucide-react';
import type { TabKey } from '@/components/sam/admin/tenant/tabs';
import type { Setup } from '@/components/sam/admin/tenant/types';
import { Panel } from '@/components/sam/panel';
import { timezoneLabel } from '@/lib/timezones';
import { cn } from '@/lib/utils';

export function SetupChecklist({
    setup,
    onGo,
}: {
    setup: Setup;
    onGo: (tab: TabKey) => void;
}) {
    const ready = setup.completed === setup.total;

    return (
        <Panel
            size="lg"
            bodyClassName="p-4"
            title={ready ? 'Listo para operar' : 'Puesta en marcha'}
            description={
                ready
                    ? 'Todo lo necesario para que su monitoreo funcione está en su lugar.'
                    : 'Lo que falta para que este cliente opere de punta a punta.'
            }
            action={
                <span className="flex items-center gap-2 text-xs text-fg-3 tabular-nums">
                    <span
                        className="h-1.5 w-20 overflow-hidden rounded-full bg-surface-3"
                        aria-hidden="true"
                    >
                        <span
                            className={cn(
                                'block h-full rounded-full',
                                ready ? 'bg-health-ok' : 'bg-primary',
                            )}
                            style={{
                                width: `${(setup.completed / Math.max(1, setup.total)) * 100}%`,
                            }}
                        />
                    </span>
                    {setup.completed} de {setup.total}
                </span>
            }
        >
            <ol className="grid gap-3 sm:grid-cols-2">
                {setup.steps.map((step) => (
                    <li key={step.key} className="flex gap-2.5">
                        {step.done ? (
                            <CheckCircle2
                                className="mt-0.5 size-4 shrink-0 text-health-ok"
                                aria-label="Hecho"
                            />
                        ) : (
                            <Circle
                                className="mt-0.5 size-4 shrink-0 text-fg-3"
                                aria-label="Pendiente"
                            />
                        )}
                        <div className="min-w-0">
                            <p
                                className={cn(
                                    'text-sm font-medium',
                                    step.done ? 'text-fg-2' : 'text-fg-1',
                                )}
                            >
                                {step.label}
                            </p>
                            <p className="text-xs text-fg-3">
                                {step.key === 'timezone' && step.done
                                    ? timezoneLabel(step.detail)
                                    : step.detail}
                            </p>
                            {!step.done && step.key === 'owner' ? (
                                <button
                                    type="button"
                                    className="mt-1 text-xs font-medium text-primary hover:underline"
                                    onClick={() => onGo('members')}
                                >
                                    Ir a miembros
                                </button>
                            ) : null}
                            {!step.done && step.key === 'timezone' ? (
                                <button
                                    type="button"
                                    className="mt-1 text-xs font-medium text-primary hover:underline"
                                    onClick={() => onGo('settings')}
                                >
                                    Definir zona horaria
                                </button>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}
