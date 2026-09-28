import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CirclePause,
    CirclePlay,
    Hourglass,
    Plus,
    Workflow,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { submit, useAutomationBase } from '@/components/sam/automation/api';
import { isRunning } from '@/components/sam/automation/copy';
import { ExecutionsList } from '@/components/sam/automation/executions-list';
import type {
    AutomationPageProps,
    ExecutionStatusFilter,
    WorkflowRow,
} from '@/components/sam/automation/types';
import { WorkflowEditorDialog } from '@/components/sam/automation/workflow-editor-dialog';
import { WorkflowListRow } from '@/components/sam/automation/workflow-row';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { TabBar } from '@/components/sam/tab-bar';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { formatNumber } from '@/lib/format';
import { deleteJson, postJson, putJson } from '@/lib/sam-fetch';

type TabKey = 'workflows' | 'executions';
type WorkflowFilter = 'active' | 'inactive';

interface ExecutionFilters {
    status: ExecutionStatusFilter | null;
    workflow: number | null;
}

const EXECUTION_SEGMENTS: {
    value: ExecutionStatusFilter;
    label: string;
    dot: string;
}[] = [
    { value: 'failed', label: 'Fallidas', dot: 'bg-severity-critical' },
    {
        value: 'pending',
        label: 'Esperan confirmación',
        dot: 'bg-severity-high',
    },
    { value: 'in_progress', label: 'En curso', dot: 'bg-severity-info' },
    { value: 'completed', label: 'Hechas', dot: 'bg-severity-low' },
    { value: 'cancelled', label: 'Canceladas', dot: 'bg-fg-3' },
];

const EMPTY_SUMMARY: NonNullable<AutomationPageProps['summary']> = {
    workflows: { total: 0, active: 0, inactive: 0 },
    executions: {
        total: 0,
        failed: 0,
        pending: 0,
        in_progress: 0,
        completed: 0,
        cancelled: 0,
    },
};

export default function AutomationIndex() {
    useBroadcastReload({
        'action.executed': ['executions', 'runStats', 'summary'],
    });

    const props = usePage().props as unknown as AutomationPageProps;
    const base = useAutomationBase();
    const workflows = props.workflows ?? [];
    const summary = props.summary ?? EMPTY_SUMMARY;
    const serverFilters: ExecutionFilters = props.executionFilters ?? {
        status: null,
        workflow: null,
    };

    const [tab, setTab] = useState<TabKey>(
        serverFilters.status !== null || serverFilters.workflow !== null
            ? 'executions'
            : 'workflows',
    );
    const [workflowFilter, setWorkflowFilter] = useState<WorkflowFilter | null>(
        null,
    );
    const [execFilters, setExecFilters] =
        useState<ExecutionFilters>(serverFilters);
    const [editor, setEditor] = useState<{
        open: boolean;
        workflow: WorkflowRow | null;
    }>({ open: false, workflow: null });
    const [deleting, setDeleting] = useState<WorkflowRow | null>(null);
    const [toggling, setToggling] = useState<number | null>(null);

    const applyExecFilters = (next: ExecutionFilters) => {
        setExecFilters(next);
        router.reload({
            only: ['executions', 'executionFilters'],
            data: {
                execution_status: next.status ?? undefined,
                execution_workflow: next.workflow ?? undefined,
            },
        });
    };

    const showWorkflows = (filter: WorkflowFilter | null) => {
        setTab('workflows');
        setWorkflowFilter(filter);
    };

    const showExecutions = (status: ExecutionStatusFilter | null) => {
        setTab('executions');

        if (status !== execFilters.status || execFilters.workflow !== null) {
            applyExecFilters({ status, workflow: null });
        }
    };

    const toggle = async (workflow: WorkflowRow, next: boolean) => {
        if (base === null || toggling !== null) {
            return;
        }

        setToggling(workflow.id);
        // `status` y `is_active` van juntos: el motor sólo corre las que
        // tienen ambos en activo (un borrador encendido no hacía nada).
        await submit(
            putJson(`${base}/workflows/${workflow.id}`, {
                is_active: next,
                status: next ? 'active' : 'inactive',
            }),
            next ? 'Automatización encendida.' : 'Automatización apagada.',
        );
        setToggling(null);
    };

    const runNow = (workflow: WorkflowRow) => {
        if (base === null) {
            return;
        }

        void submit(
            postJson(`${base}/workflows/${workflow.id}/trigger`, {
                source_reference_id: `manual-${Date.now()}`,
            }),
            'Automatización en marcha. Sigue su avance en Ejecuciones.',
        );
    };

    const remove = async (workflow: WorkflowRow) => {
        if (base === null) {
            return;
        }

        const result = await submit(
            deleteJson(`${base}/workflows/${workflow.id}`),
            'Automatización eliminada.',
        );

        if (result.ok) {
            setDeleting(null);
        }
    };

    const visibleWorkflows = workflows.filter((workflow) =>
        workflowFilter === null
            ? true
            : workflowFilter === 'active'
              ? isRunning(workflow)
              : !isRunning(workflow),
    );

    const filteredWorkflowName =
        execFilters.workflow !== null
            ? (workflows.find((w) => w.id === execFilters.workflow)?.name ??
              'Automatización')
            : null;

    const openCreate = () => setEditor({ open: true, workflow: null });

    return (
        <>
            <Head title="Automatizaciones" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHeader
                    title="Automatizaciones"
                    description="Reglas que actúan solas cuando pasa algo: avisan a tu equipo, asignan o escalan incidentes."
                    meta={
                        <span className="text-xs text-fg-3">
                            <span className="font-medium text-fg-1">
                                {summary.workflows.total}
                            </span>{' '}
                            en total ·{' '}
                            <span className="text-severity-low">
                                {summary.workflows.active} encendidas
                            </span>
                        </span>
                    }
                    actions={
                        props.canManage ? (
                            <Button size="sm" onClick={openCreate}>
                                <Plus size={14} />
                                Nueva automatización
                            </Button>
                        ) : undefined
                    }
                    className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
                />

                <PulseStrip>
                    <PulseStat
                        label="Encendidas"
                        value={summary.workflows.active}
                        icon={CirclePlay}
                        tone={summary.workflows.active > 0 ? 'ok' : 'neutral'}
                        hint="se activan solas"
                        onClick={() =>
                            showWorkflows(
                                workflowFilter === 'active' &&
                                    tab === 'workflows'
                                    ? null
                                    : 'active',
                            )
                        }
                        active={
                            tab === 'workflows' && workflowFilter === 'active'
                        }
                    />
                    <PulseStat
                        label="Apagadas"
                        value={summary.workflows.inactive}
                        icon={CirclePause}
                        hint="no hacen nada"
                        onClick={() =>
                            showWorkflows(
                                workflowFilter === 'inactive' &&
                                    tab === 'workflows'
                                    ? null
                                    : 'inactive',
                            )
                        }
                        active={
                            tab === 'workflows' && workflowFilter === 'inactive'
                        }
                    />
                    <PulseStat
                        label="Acciones 30 d"
                        value={formatNumber(summary.executions.total)}
                        icon={Activity}
                        tone="info"
                        hint="hechas por automatizaciones"
                        onClick={() => showExecutions(null)}
                        active={
                            tab === 'executions' &&
                            execFilters.status === null &&
                            execFilters.workflow === null
                        }
                    />
                    <PulseStat
                        label="Fallidas"
                        value={summary.executions.failed}
                        icon={AlertTriangle}
                        tone={
                            summary.executions.failed > 0
                                ? 'critical'
                                : 'neutral'
                        }
                        hint={
                            summary.executions.failed > 0
                                ? 'revisa y reintenta'
                                : 'sin fallos en 30 días'
                        }
                        onClick={() => showExecutions('failed')}
                        active={
                            tab === 'executions' &&
                            execFilters.status === 'failed'
                        }
                    />
                    <PulseStat
                        label="Esperan confirmación"
                        value={summary.executions.pending}
                        icon={Hourglass}
                        tone={
                            summary.executions.pending > 0 ? 'warn' : 'neutral'
                        }
                        hint={
                            summary.executions.pending > 0
                                ? 'necesitan tu visto bueno'
                                : 'nada pendiente'
                        }
                        live={summary.executions.pending > 0}
                        onClick={() => showExecutions('pending')}
                        active={
                            tab === 'executions' &&
                            execFilters.status === 'pending'
                        }
                    />
                </PulseStrip>

                <TabBar
                    aria-label="Secciones de automatizaciones"
                    className="shrink-0 px-5"
                    value={tab}
                    onChange={(key) => setTab(key as TabKey)}
                    items={[
                        {
                            key: 'workflows',
                            label: 'Automatizaciones',
                            count: summary.workflows.total,
                        },
                        {
                            key: 'executions',
                            label: 'Ejecuciones',
                            count: summary.executions.total,
                        },
                    ]}
                />

                {tab === 'workflows' ? (
                    <>
                        {workflows.length > 0 && (
                            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-2">
                                <SegmentedFilter
                                    aria-label="Filtrar automatizaciones"
                                    allLabel="Todas"
                                    allCount={summary.workflows.total}
                                    value={workflowFilter}
                                    onChange={(value) =>
                                        setWorkflowFilter(
                                            value as WorkflowFilter | null,
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'active',
                                            label: 'Encendidas',
                                            count: summary.workflows.active,
                                            dot: 'bg-severity-low',
                                        },
                                        {
                                            value: 'inactive',
                                            label: 'Apagadas',
                                            count: summary.workflows.inactive,
                                            dot: 'bg-fg-3',
                                        },
                                    ]}
                                />
                                <span className="text-2xs text-fg-3">
                                    Usa el interruptor para encender o apagar
                                    cada una.
                                </span>
                            </div>
                        )}

                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {workflows.length === 0 ? (
                                <EmptyState
                                    icon={Workflow}
                                    title="Todavía no hay automatizaciones"
                                    description="Una automatización trabaja por ti: por ejemplo, cuando se crea un incidente de prioridad alta manda un WhatsApp al monitorista de guardia, sin que nadie tenga que hacerlo a mano."
                                    action={
                                        props.canManage ? (
                                            <Button
                                                size="sm"
                                                onClick={openCreate}
                                            >
                                                <Plus size={14} />
                                                Crear la primera
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : visibleWorkflows.length === 0 ? (
                                <EmptyState
                                    icon={Workflow}
                                    title={
                                        workflowFilter === 'active'
                                            ? 'Ninguna encendida'
                                            : 'Ninguna apagada'
                                    }
                                    description={
                                        workflowFilter === 'active'
                                            ? 'Enciende una automatización con su interruptor para que empiece a trabajar.'
                                            : 'Todas tus automatizaciones están trabajando.'
                                    }
                                    action={
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setWorkflowFilter(null)
                                            }
                                        >
                                            Ver todas
                                        </Button>
                                    }
                                />
                            ) : (
                                <ul className="divide-y divide-border">
                                    {visibleWorkflows.map((workflow) => (
                                        <WorkflowListRow
                                            key={workflow.id}
                                            workflow={workflow}
                                            stat={props.runStats?.[workflow.id]}
                                            options={props.options}
                                            conditionFields={
                                                props.triggerConditionFields[
                                                    workflow.triggerType ?? ''
                                                ] ?? []
                                            }
                                            canManage={props.canManage}
                                            toggling={toggling === workflow.id}
                                            onToggle={(wf, next) =>
                                                void toggle(wf, next)
                                            }
                                            onEdit={(wf) =>
                                                setEditor({
                                                    open: true,
                                                    workflow: wf,
                                                })
                                            }
                                            onRunNow={runNow}
                                            onShowExecutions={(wf) => {
                                                setTab('executions');
                                                applyExecFilters({
                                                    status: null,
                                                    workflow: wf.id,
                                                });
                                            }}
                                            onDelete={setDeleting}
                                        />
                                    ))}
                                </ul>
                            )}
                        </div>
                    </>
                ) : (
                    <>
                        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border px-5 py-2">
                            <SegmentedFilter
                                aria-label="Filtrar ejecuciones por resultado"
                                allLabel="Todas"
                                allCount={summary.executions.total}
                                value={execFilters.status}
                                onChange={(value) =>
                                    applyExecFilters({
                                        ...execFilters,
                                        status: value as ExecutionStatusFilter | null,
                                    })
                                }
                                options={EXECUTION_SEGMENTS.map((segment) => ({
                                    ...segment,
                                    count: summary.executions[segment.value],
                                }))}
                            />
                            {filteredWorkflowName && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        applyExecFilters({
                                            ...execFilters,
                                            workflow: null,
                                        })
                                    }
                                    className="flex items-center gap-1 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-1 text-2xs font-medium text-primary"
                                    aria-label={`Quitar filtro: ${filteredWorkflowName}`}
                                >
                                    {filteredWorkflowName}
                                    <X size={12} />
                                </button>
                            )}
                            <span className="ml-auto text-2xs text-fg-3">
                                Últimos 30 días
                            </span>
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto">
                            <ExecutionsList
                                executions={props.executions ?? []}
                                actionTypes={props.options.actionTypes}
                                canManage={props.canManage}
                                filtered={
                                    execFilters.status !== null ||
                                    execFilters.workflow !== null
                                }
                            />
                            {(props.executions ?? []).length >= 100 && (
                                <p className="px-5 py-3 text-center text-2xs text-fg-3">
                                    Se muestran las 100 más recientes. Usa los
                                    filtros para encontrar otras.
                                </p>
                            )}
                        </div>
                    </>
                )}
            </div>

            <WorkflowEditorDialog
                open={editor.open}
                workflow={editor.workflow}
                options={props.options}
                triggerConditionFields={props.triggerConditionFields}
                teamTargets={props.teamTargets}
                onOpenChange={(open) =>
                    !open && setEditor({ open: false, workflow: null })
                }
            />

            <ConfirmDialog
                open={deleting !== null}
                title="Eliminar automatización"
                description={
                    deleting
                        ? `«${deleting.name}» dejará de actuar y desaparecerá de la lista. Las acciones que ya hizo seguirán en Ejecuciones. Esta acción no se puede deshacer.`
                        : ''
                }
                onConfirm={() => {
                    if (deleting) {
                        return remove(deleting);
                    }
                }}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
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
