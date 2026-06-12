import { Head, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { postJson, putJson, readErrorMessage } from '@/lib/sam-fetch';

// ---- Types ----

interface WorkflowStep {
    order?: number;
    action_type?: string;
    execution_mode?: string;
    target_type?: string;
    target_reference?: string;
    delay_seconds?: number;
    [key: string]: unknown;
}

interface WorkflowRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    triggerType: string | null;
    triggerConditions: Record<string, unknown> | null;
    status: string | null;
    steps: WorkflowStep[];
    isActive: boolean;
}

interface ExecutionRow {
    id: number;
    actionType: string | null;
    status: string | null;
    executionMode: string | null;
    targetType: string | null;
    targetReference: string | null;
    incidentId: number | null;
    attempts: number;
    errorMessage: string | null;
    isStub: boolean;
    executedAt: string | null;
    createdAt: string | null;
}

interface AutomationPageProps {
    workflows: WorkflowRow[];
    executions: ExecutionRow[];
    options: {
        actionTypes: string[];
        triggerTypes: string[];
        statuses: string[];
    };
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    teamTargets: {
        users: { value: string; label: string; description?: string }[];
        roles: { value: string; label: string }[];
    };
    canManage: boolean;
}

const TABS = [
    { key: 'workflows', label: 'Workflows' },
    { key: 'executions', label: 'Ejecuciones' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const STATUS_COLOR: Record<string, string> = {
    completed: 'text-severity-low',
    failed: 'text-severity-critical',
    running: 'text-severity-medium',
    pending: 'text-severity-high',
    queued: 'text-fg-2',
    retrying: 'text-severity-medium',
    cancelled: 'text-fg-3',
};

function manualReference(): string {
    return `manual-${Date.now()}`;
}

function useTeamBase(): string | null {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    return slug ? `/${slug}/automation` : null;
}

async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<boolean> {
    try {
        const response = await promise;

        if (response.ok || response.status === 201 || response.status === 202) {
            toast.success(successMessage);
            router.reload();

            return true;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para esta acción.');
        } else {
            toast.error(
                (await readErrorMessage(response)) ??
                    'No se pudo completar la acción.',
            );
        }
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return false;
}

// ---- Workflows tab ----

interface StepDraft {
    action_type: string;
    target_type: string;
    target_reference: string;
    delay_seconds: string;
}

function WorkflowBuilder({
    options,
    triggerConditionFields,
    teamTargets,
    onCreated,
}: {
    options: AutomationPageProps['options'];
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    teamTargets: AutomationPageProps['teamTargets'];
    onCreated: () => void;
}) {
    const base = useTeamBase();
    const [form, setForm] = useState({
        code: '',
        name: '',
        triggerType: 'incident_created',
    });
    const [conditions, setConditions] = useState<Record<string, unknown>>({});
    const [steps, setSteps] = useState<StepDraft[]>([
        {
            action_type: 'send_email',
            target_type: 'role',
            target_reference: 'tenant_admin',
            delay_seconds: '0',
        },
    ]);

    const addStep = () =>
        setSteps((prev) => [
            ...prev,
            {
                action_type: 'send_email',
                target_type: 'role',
                target_reference: 'tenant_admin',
                delay_seconds: '0',
            },
        ]);

    const removeStep = (index: number) =>
        setSteps((prev) => prev.filter((_, i) => i !== index));

    const setStep = (index: number, key: keyof StepDraft, value: string) =>
        setSteps((prev) =>
            prev.map((step, i) =>
                i === index ? { ...step, [key]: value } : step,
            ),
        );

    const create = async () => {
        if (base === null) {
            return;
        }

        if (form.code === '' || form.name === '') {
            toast.error('Código y nombre son obligatorios.');

            return;
        }

        const ok = await submit(
            postJson(`${base}/workflows`, {
                code: form.code,
                name: form.name,
                trigger_type: form.triggerType,
                trigger_conditions_json:
                    Object.keys(conditions).length > 0 ? conditions : null,
                status: 'active',
                steps_json: steps.map((step, index) => ({
                    order: index + 1,
                    action_type: step.action_type,
                    execution_mode: 'async',
                    target_type: step.target_type,
                    target_reference: step.target_reference,
                    delay_seconds: Number(step.delay_seconds) || 0,
                })),
                is_active: true,
            }),
            'Workflow creado.',
        );

        if (ok) {
            onCreated();
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-md border border-border p-3">
            <div className="flex flex-wrap gap-2">
                <Input
                    placeholder="code (ej. critico-notifica)"
                    value={form.code}
                    onChange={(e) => setForm({ ...form, code: e.target.value })}
                    className="w-56 font-mono text-xs"
                />
                <Input
                    placeholder="Nombre"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    className="w-64 text-xs"
                />
                <select
                    value={form.triggerType}
                    onChange={(e) => {
                        setForm({ ...form, triggerType: e.target.value });
                        setConditions({});
                    }}
                    className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
                >
                    {options.triggerTypes.map((trigger) => (
                        <option key={trigger} value={trigger}>
                            trigger: {trigger}
                        </option>
                    ))}
                </select>
            </div>

            <span className="text-2xs text-fg-3 uppercase">
                Condiciones del disparador
            </span>
            <ConditionBuilder
                variant="flat-equality"
                fields={triggerConditionFields[form.triggerType] ?? []}
                allowUnknownFields
                value={conditions}
                onChange={setConditions}
            />

            <span className="text-2xs text-fg-3 uppercase">Pasos</span>
            {steps.map((step, index) => (
                <div
                    key={index}
                    className="flex flex-wrap items-center gap-2 rounded-md bg-surface-1 p-2"
                >
                    <span className="w-5 text-center font-mono text-2xs text-fg-3">
                        {index + 1}
                    </span>
                    <select
                        value={step.action_type}
                        onChange={(e) =>
                            setStep(index, 'action_type', e.target.value)
                        }
                        className="rounded-md border border-border bg-surface-2 px-2 py-1 text-xs"
                    >
                        {options.actionTypes.map((action) => (
                            <option key={action} value={action}>
                                {action}
                            </option>
                        ))}
                    </select>
                    <select
                        value={step.target_type}
                        onChange={(e) =>
                            setStep(index, 'target_type', e.target.value)
                        }
                        className="rounded-md border border-border bg-surface-2 px-2 py-1 text-xs"
                    >
                        {['role', 'user', 'email', 'phone', 'url'].map(
                            (target) => (
                                <option key={target} value={target}>
                                    {target}
                                </option>
                            ),
                        )}
                    </select>
                    {step.target_type === 'user' ? (
                        <Combobox
                            options={teamTargets.users}
                            value={
                                step.target_reference === ''
                                    ? null
                                    : step.target_reference
                            }
                            onChange={(value) =>
                                setStep(index, 'target_reference', value ?? '')
                            }
                            placeholder="Usuario del equipo…"
                            className="w-56"
                        />
                    ) : step.target_type === 'role' ? (
                        <Combobox
                            options={teamTargets.roles}
                            value={
                                step.target_reference === ''
                                    ? null
                                    : step.target_reference
                            }
                            onChange={(value) =>
                                setStep(index, 'target_reference', value ?? '')
                            }
                            placeholder="Rol del equipo…"
                            className="w-56"
                        />
                    ) : (
                        <Input
                            placeholder="destino (email, tel, url…)"
                            value={step.target_reference}
                            onChange={(e) =>
                                setStep(
                                    index,
                                    'target_reference',
                                    e.target.value,
                                )
                            }
                            className="w-56 text-xs"
                        />
                    )}
                    <Input
                        type="number"
                        title="delay en segundos"
                        value={step.delay_seconds}
                        onChange={(e) =>
                            setStep(index, 'delay_seconds', e.target.value)
                        }
                        className="w-20 text-xs"
                    />
                    {steps.length > 1 && (
                        <button
                            type="button"
                            onClick={() => removeStep(index)}
                            className="text-fg-3 hover:text-severity-critical"
                            aria-label="Quitar paso"
                        >
                            <Trash2 size={13} />
                        </button>
                    )}
                </div>
            ))}

            <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={addStep}>
                    <Plus size={12} />
                    Añadir paso
                </Button>
                <Button size="sm" onClick={create}>
                    Crear workflow
                </Button>
            </div>
        </div>
    );
}

function WorkflowsTab({
    workflows,
    options,
    triggerConditionFields,
    teamTargets,
    canManage,
}: {
    workflows: WorkflowRow[];
    options: AutomationPageProps['options'];
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    teamTargets: AutomationPageProps['teamTargets'];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [creating, setCreating] = useState(false);

    const toggleActive = (workflow: WorkflowRow) => {
        if (base === null) {
            return;
        }

        void submit(
            putJson(`${base}/workflows/${workflow.id}`, {
                is_active: !workflow.isActive,
            }),
            workflow.isActive ? 'Workflow desactivado.' : 'Workflow activado.',
        );
    };

    const triggerNow = (workflow: WorkflowRow) => {
        if (base === null) {
            return;
        }

        void submit(
            postJson(`${base}/workflows/${workflow.id}/trigger`, {
                source_reference_id: manualReference(),
            }),
            'Workflow disparado.',
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex justify-end">
                {canManage && (
                    <Button
                        size="sm"
                        variant={creating ? 'ghost' : 'outline'}
                        onClick={() => setCreating(!creating)}
                    >
                        {creating ? 'Cancelar' : 'Nuevo workflow'}
                    </Button>
                )}
            </div>

            {creating && (
                <WorkflowBuilder
                    options={options}
                    triggerConditionFields={triggerConditionFields}
                    teamTargets={teamTargets}
                    onCreated={() => setCreating(false)}
                />
            )}

            {workflows.length === 0 && !creating && (
                <Card>
                    <CardContent className="py-6 text-center text-xs text-fg-3">
                        Sin workflows — crea el primero para automatizar
                        notificaciones y acciones sobre incidentes.
                    </CardContent>
                </Card>
            )}

            {workflows.map((workflow) => (
                <Card key={workflow.id}>
                    <CardHeader>
                        <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                            {workflow.name}
                            <Badge variant="outline" className="text-fg-3">
                                {workflow.triggerType}
                            </Badge>
                            <Badge
                                variant="outline"
                                className={
                                    workflow.isActive
                                        ? 'text-severity-low'
                                        : 'text-fg-3'
                                }
                            >
                                {workflow.isActive ? 'activo' : 'inactivo'}
                            </Badge>
                            <span className="ml-auto flex gap-1.5">
                                {canManage &&
                                    workflow.triggerType ===
                                        'manual_trigger' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => triggerNow(workflow)}
                                        >
                                            Disparar
                                        </Button>
                                    )}
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => toggleActive(workflow)}
                                    >
                                        {workflow.isActive
                                            ? 'Desactivar'
                                            : 'Activar'}
                                    </Button>
                                )}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="flex flex-col gap-1 text-xs text-fg-2">
                            {workflow.steps.map((step, index) => (
                                <li
                                    key={index}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="font-mono text-2xs text-fg-3">
                                        {index + 1}.
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="font-mono text-3xs"
                                    >
                                        {String(step.action_type ?? '—')}
                                    </Badge>
                                    <span className="text-fg-3">→</span>
                                    {String(step.target_type ?? '')}{' '}
                                    <span className="font-mono text-2xs">
                                        {String(step.target_reference ?? '')}
                                    </span>
                                    {Number(step.delay_seconds ?? 0) > 0 && (
                                        <span className="text-fg-3">
                                            (+{String(step.delay_seconds)}s)
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

// ---- Executions tab ----

function ExecutionsTab({
    executions,
    canManage,
}: {
    executions: ExecutionRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();

    const act = (
        execution: ExecutionRow,
        action: 'retry' | 'confirm' | 'cancel',
    ) => {
        if (base === null) {
            return;
        }

        void submit(
            postJson(`${base}/executions/${execution.id}/${action}`, {}),
            action === 'retry'
                ? 'Reintento encolado.'
                : action === 'confirm'
                  ? 'Ejecución confirmada.'
                  : 'Ejecución cancelada.',
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm uppercase">
                    Últimas ejecuciones ({executions.length})
                </CardTitle>
            </CardHeader>
            <CardContent>
                {executions.length === 0 ? (
                    <p className="text-xs text-fg-3">
                        Aún sin ejecuciones de acciones.
                    </p>
                ) : (
                    <table className="w-full text-left text-xs">
                        <thead className="text-2xs text-fg-3 uppercase">
                            <tr>
                                <th className="py-1.5 pr-4">#</th>
                                <th className="py-1.5 pr-4">Acción</th>
                                <th className="py-1.5 pr-4">Destino</th>
                                <th className="py-1.5 pr-4">Estado</th>
                                <th className="py-1.5 pr-4">Intentos</th>
                                <th className="py-1.5 pr-4">Error</th>
                                <th className="py-1.5 pr-4">Cuándo</th>
                                <th className="py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {executions.map((execution) => (
                                <tr
                                    key={execution.id}
                                    className="border-t border-border/50 text-fg-2"
                                >
                                    <td className="py-2 pr-4 font-mono text-2xs">
                                        {execution.id}
                                    </td>
                                    <td className="py-2 pr-4 font-mono text-2xs">
                                        {execution.actionType}
                                        {execution.isStub && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1 text-3xs text-fg-3"
                                            >
                                                stub
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {execution.targetType}:{' '}
                                        <span className="font-mono text-2xs">
                                            {execution.targetReference ?? '—'}
                                        </span>
                                    </td>
                                    <td
                                        className={`py-2 pr-4 ${STATUS_COLOR[execution.status ?? ''] ?? 'text-fg-3'}`}
                                    >
                                        {execution.status}
                                    </td>
                                    <td className="py-2 pr-4 tabular-nums">
                                        {execution.attempts}
                                    </td>
                                    <td
                                        className="max-w-56 truncate py-2 pr-4 text-2xs"
                                        title={execution.errorMessage ?? ''}
                                    >
                                        {execution.errorMessage ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4 font-mono text-2xs whitespace-nowrap">
                                        {execution.executedAt
                                            ? new Date(
                                                  execution.executedAt,
                                              ).toLocaleString('es')
                                            : '—'}
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        {canManage &&
                                            execution.status === 'failed' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        act(execution, 'retry')
                                                    }
                                                >
                                                    Reintentar
                                                </Button>
                                            )}
                                        {canManage &&
                                            execution.status === 'pending' && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            act(
                                                                execution,
                                                                'confirm',
                                                            )
                                                        }
                                                    >
                                                        Confirmar
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            act(
                                                                execution,
                                                                'cancel',
                                                            )
                                                        }
                                                    >
                                                        Cancelar
                                                    </Button>
                                                </>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Page ----

export default function AutomationIndex() {
    const page = usePage();
    const props = page.props as unknown as AutomationPageProps;
    const [tab, setTab] = useState<TabKey>('workflows');

    return (
        <>
            <Head title="Automatizaciones" />
            <div className="flex flex-col gap-4 p-5">
                <div>
                    <h1 className="text-md font-semibold text-fg-1">
                        Automatizaciones
                    </h1>
                    <p className="text-xs text-fg-3">
                        Workflows que reaccionan a decisiones e incidentes, y el
                        historial de acciones ejecutadas.
                    </p>
                </div>

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-sm transition-colors ${
                                tab === item.key
                                    ? 'border-b-2 border-primary font-medium text-fg-1'
                                    : 'text-fg-3 hover:text-fg-1'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === 'workflows' && (
                    <WorkflowsTab
                        workflows={props.workflows}
                        options={props.options}
                        triggerConditionFields={props.triggerConditionFields}
                        teamTargets={props.teamTargets}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'executions' && (
                    <ExecutionsTab
                        executions={props.executions}
                        canManage={props.canManage}
                    />
                )}
            </div>
        </>
    );
}

AutomationIndex.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Automatizaciones',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/automation`
                : '/automation',
        },
    ],
});
