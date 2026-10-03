import { Plus, Workflow } from 'lucide-react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import type {
    AutomationOptions,
    AutomationSummary,
    WorkflowFilter,
    WorkflowRow,
    WorkflowRunStat,
} from './types';
import { WorkflowListRow } from './workflow-row';

export interface WorkflowsPanelProps {
    workflows: WorkflowRow[];
    /** `workflows` after the on/off filter. */
    visibleWorkflows: WorkflowRow[];
    summary: AutomationSummary;
    workflowFilter: WorkflowFilter | null;
    setWorkflowFilter: (filter: WorkflowFilter | null) => void;
    runStats: Record<number, WorkflowRunStat> | undefined;
    options: AutomationOptions;
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    canManage: boolean;
    toggling: number | null;
    onCreate: () => void;
    onToggle: (workflow: WorkflowRow, next: boolean) => void;
    onEdit: (workflow: WorkflowRow) => void;
    onRunNow: (workflow: WorkflowRow) => void;
    onShowExecutions: (workflow: WorkflowRow) => void;
    onDelete: (workflow: WorkflowRow) => void;
}

/** "Automatizaciones" tab: on/off filter and the workflow list. */
export function WorkflowsPanel({
    workflows,
    visibleWorkflows,
    summary,
    workflowFilter,
    setWorkflowFilter,
    runStats,
    options,
    triggerConditionFields,
    canManage,
    toggling,
    onCreate: openCreate,
    onToggle,
    onEdit,
    onRunNow,
    onShowExecutions,
    onDelete,
}: WorkflowsPanelProps) {
    return (
        <>
            {workflows.length > 0 && (
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-2">
                    <SegmentedFilter
                        aria-label="Filtrar automatizaciones"
                        allLabel="Todas"
                        allCount={summary.workflows.total}
                        value={workflowFilter}
                        onChange={(value) =>
                            setWorkflowFilter(value as WorkflowFilter | null)
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
                        Usa el interruptor para encender o apagar cada una.
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
                            canManage ? (
                                <Button size="sm" onClick={openCreate}>
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
                                onClick={() => setWorkflowFilter(null)}
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
                                stat={runStats?.[workflow.id]}
                                options={options}
                                conditionFields={
                                    triggerConditionFields[
                                        workflow.triggerType ?? ''
                                    ] ?? []
                                }
                                canManage={canManage}
                                toggling={toggling === workflow.id}
                                onToggle={onToggle}
                                onEdit={onEdit}
                                onRunNow={onRunNow}
                                onShowExecutions={onShowExecutions}
                                onDelete={onDelete}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
