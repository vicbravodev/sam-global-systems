import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { lazy, Suspense, useState } from 'react';
import { AutomationPulse } from '@/components/sam/automation/automation-pulse';
import { isRunning } from '@/components/sam/automation/copy';
import { ExecutionsPanel } from '@/components/sam/automation/executions-panel';
import type {
    AutomationPageProps,
    AutomationTab,
    ExecutionFilters,
    WorkflowRow,
} from '@/components/sam/automation/types';
import { useAutomationView } from '@/components/sam/automation/use-automation-view';
import { useWorkflowActions } from '@/components/sam/automation/use-workflow-actions';
import { WorkflowsPanel } from '@/components/sam/automation/workflows-panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { TabBar } from '@/components/sam/tab-bar';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import automationRoutes from '@/routes/automation';

// The editor (condition builder, step editor, comboboxes) loads on its first
// opening.
const WorkflowEditorDialog = lazy(() =>
    import('@/components/sam/automation/workflow-editor-dialog').then(
        (module) => ({ default: module.WorkflowEditorDialog }),
    ),
);

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

export default function AutomationIndex(props: AutomationPageProps) {
    useBroadcastReload({
        'action.executed': ['executions', 'runStats', 'summary'],
    });

    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const workflows = props.workflows ?? [];
    const summary = props.summary ?? EMPTY_SUMMARY;
    const serverFilters: ExecutionFilters = props.executionFilters ?? {
        status: null,
        workflow: null,
    };

    const {
        tab,
        setTab,
        workflowFilter,
        setWorkflowFilter,
        execFilters,
        applyExecFilters,
        showWorkflows,
        showExecutions,
        showWorkflowExecutions,
    } = useAutomationView(serverFilters);
    const [editor, setEditor] = useState<{
        open: boolean;
        workflow: WorkflowRow | null;
    }>({ open: false, workflow: null });
    // Mounted on first opening and kept, so closing still animates.
    const [editorMounted, setEditorMounted] = useState(false);

    if (editor.open && !editorMounted) {
        setEditorMounted(true);
    }

    const actions = useWorkflowActions(teamSlug);

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

                <AutomationPulse
                    summary={summary}
                    tab={tab}
                    workflowFilter={workflowFilter}
                    execFilters={execFilters}
                    onShowWorkflows={showWorkflows}
                    onShowExecutions={showExecutions}
                />

                <TabBar
                    aria-label="Secciones de automatizaciones"
                    className="shrink-0 px-5"
                    value={tab}
                    onChange={(key) => setTab(key as AutomationTab)}
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
                    <WorkflowsPanel
                        workflows={workflows}
                        visibleWorkflows={visibleWorkflows}
                        summary={summary}
                        workflowFilter={workflowFilter}
                        setWorkflowFilter={setWorkflowFilter}
                        runStats={props.runStats}
                        options={props.options}
                        triggerConditionFields={props.triggerConditionFields}
                        canManage={props.canManage}
                        toggling={actions.toggling}
                        onCreate={openCreate}
                        onToggle={(wf, next) => void actions.toggle(wf, next)}
                        onEdit={(wf) => setEditor({ open: true, workflow: wf })}
                        onRunNow={actions.runNow}
                        onShowExecutions={(wf) => showWorkflowExecutions(wf.id)}
                        onDelete={actions.setDeleting}
                    />
                ) : (
                    <ExecutionsPanel
                        executions={props.executions}
                        summary={summary}
                        execFilters={execFilters}
                        applyExecFilters={applyExecFilters}
                        filteredWorkflowName={filteredWorkflowName}
                        actionTypes={props.options.actionTypes}
                        canManage={props.canManage}
                    />
                )}
            </div>

            {editorMounted && (
                <Suspense fallback={null}>
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
                </Suspense>
            )}

            <ConfirmDialog
                open={actions.deleting !== null}
                title="Eliminar automatización"
                description={
                    actions.deleting
                        ? `«${actions.deleting.name}» dejará de actuar y desaparecerá de la lista. Las acciones que ya hizo seguirán en Ejecuciones. Esta acción no se puede deshacer.`
                        : ''
                }
                onConfirm={() => {
                    if (actions.deleting) {
                        return actions.remove(actions.deleting);
                    }
                }}
                onOpenChange={(open) => !open && actions.setDeleting(null)}
            />
        </>
    );
}

AutomationIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Automatizaciones',
            href: props.currentTeam
                ? automationRoutes.show.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
